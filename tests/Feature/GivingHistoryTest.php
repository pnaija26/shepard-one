<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Contribution;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 11.3: View Giving History, Statements, and Reports.
 */
class GivingHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'GH-HQ']);
        $this->branch = Organization::create(['name' => 'Branch A', 'type' => 'branch', 'identifier' => 'GH-A', 'parent_id' => $hq->id]);
        config(['payments.member_giving_enabled' => true]);
    }

    private function grant(User $user, array $actions): void
    {
        $role = Role::create(['name' => 'gh_' . $user->id . '_' . substr(md5(implode(',', $actions)), 0, 6)]);
        foreach ($actions as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);
    }

    /**
     * @return array{0: User, 1: Member}
     */
    private function memberWithGiving(): array
    {
        $user = User::factory()->create([
            'branch_id' => $this->branch->id,
            'email' => 'gh' . uniqid() . '@church.test',
        ]);
        $this->grant($user, ['payments.giving.self']);

        $member = Member::create([
            'membership_id' => 'GH-M-' . $user->id,
            'branch_id' => $this->branch->id,
            'user_id' => $user->id,
            'registration_channel' => 'web',
            'first_name' => 'Alex',
            'last_name' => 'Giver',
            'email' => $user->email,
            'consent_data_processing' => true,
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
        ]);

        return [$user, $member];
    }

    private function financeOfficer(bool $withIdentity = false): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $actions = [
            'payments.giving.reports',
            'payments.contributions.read',
            'payments.contributions.reconcile',
            'payments.contributions.manual',
        ];
        if ($withIdentity) {
            $actions[] = 'payments.giving.identity';
        }
        $this->grant($user, $actions);

        return $user;
    }

    private function seedContribution(Member $member, array $overrides = []): Contribution
    {
        return Contribution::create(array_merge([
            'reference' => 'CT-GH-' . strtoupper(uniqid()),
            'provider' => 'manual',
            'source_type' => Contribution::SOURCE_MANUAL,
            'provider_payment_reference' => 'REF-' . uniqid(),
            'payment_reference' => 'REF-' . uniqid(),
            'status' => Contribution::STATUS_SUCCEEDED,
            'amount_cents' => 5000,
            'currency' => 'USD',
            'category' => 'tithe',
            'branch_id' => $this->branch->id,
            'member_id' => $member->id,
            'payer_linked' => true,
            'reconciliation_status' => Contribution::RECON_RECONCILED,
            'receipt_eligible' => true,
            'occurred_at' => Carbon::now()->subDays(3),
            'provider_evidence' => ['source' => 'test'],
        ], $overrides));
    }

    public function test_member_sees_only_own_approved_history_and_cannot_tamper_member_id(): void
    {
        [$userA, $memberA] = $this->memberWithGiving();
        [$userB, $memberB] = $this->memberWithGiving();

        $own = $this->seedContribution($memberA, ['amount_cents' => 4200, 'category' => 'tithe']);
        $this->seedContribution($memberA, [
            'amount_cents' => 1000,
            'reconciliation_status' => Contribution::RECON_UNMATCHED,
            'payment_reference' => 'UNMATCHED-1',
            'provider_payment_reference' => 'UNMATCHED-1',
        ]);
        $other = $this->seedContribution($memberB, ['amount_cents' => 9999, 'category' => 'offering']);

        $history = $this->actingAs($userA, 'sanctum')
            ->getJson('/api/me/giving?from=' . now()->subMonth()->toDateString() . '&to=' . now()->toDateString())
            ->assertOk()
            ->json('data');

        $ids = collect($history['items'])->pluck('id')->all();
        $this->assertContains($own->id, $ids);
        $this->assertNotContains($other->id, $ids);
        $this->assertSame(4200, $history['total_cents']);
        $this->assertSame(1, $history['count']);

        $this->actingAs($userA, 'sanctum')
            ->getJson('/api/me/giving?member_id=' . $memberB->id)
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden_member');

        $this->assertDatabaseHas('audit_events', [
            'action' => 'giving.member_history.tamper',
            'actor_id' => $userA->id,
        ]);

        $statement = $this->actingAs($userA, 'sanctum')
            ->postJson('/api/me/giving/statement', [
                'from' => now()->subMonth()->toDateString(),
                'to' => now()->toDateString(),
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame(4200, $statement['total_cents']);
        $this->assertSame(1, $statement['line_count']);
        $this->assertSame($memberA->id, $statement['member_id']);
    }

    public function test_finance_report_minimizes_identity_unless_authorized_and_totals_reconcile(): void
    {
        $finance = $this->financeOfficer(withIdentity: false);
        $financeWithId = $this->financeOfficer(withIdentity: true);
        [$user, $member] = $this->memberWithGiving();

        $c1 = $this->seedContribution($member, ['amount_cents' => 3000, 'category' => 'tithe']);
        $c2 = $this->seedContribution($member, [
            'amount_cents' => 2000,
            'category' => 'offering',
            'payment_reference' => 'OFF-1',
            'provider_payment_reference' => 'OFF-1',
        ]);

        \App\Models\ContributionAdjustment::create([
            'reference' => 'ADJ-TEST-1',
            'contribution_id' => $c1->id,
            'adjustment_type' => 'correction',
            'amount_delta_cents' => -100,
            'reason' => 'Fee adjustment',
            'created_by' => $finance->id,
            'occurred_at' => now(),
        ]);

        $minimized = $this->actingAsMfaVerified($finance)
            ->getJson('/api/giving/reports?from=' . now()->subMonth()->toDateString() . '&to=' . now()->toDateString())
            ->assertOk()
            ->json('data');

        $this->assertFalse($minimized['identity_included']);
        $this->assertSame(5000, $minimized['totals']['gross_cents']);
        $this->assertSame(-100, $minimized['totals']['adjustment_delta_cents']);
        $this->assertSame(4900, $minimized['totals']['net_cents']);
        $this->assertArrayHasKey('donor', $minimized['records'][0]);
        $this->assertArrayNotHasKey('member_name', $minimized['records'][0]);
        $this->assertArrayNotHasKey('member_id', $minimized['records'][0]);

        $withIdentity = $this->actingAsMfaVerified($financeWithId)
            ->getJson('/api/giving/reports?include_identity=1&from=' . now()->subMonth()->toDateString() . '&to=' . now()->toDateString())
            ->assertOk()
            ->json('data');

        $this->assertTrue($withIdentity['identity_included']);
        $this->assertSame($member->id, $withIdentity['records'][0]['member_id']);

        // Unauthorized role denied on report and unauthorized path.
        $this->actingAsMfaVerified($user)
            ->getJson('/api/giving/reports')
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');

        $this->actingAsMfaVerified($user)
            ->getJson('/api/giving/unauthorized?path=dashboard-export')
            ->assertForbidden()
            ->assertJsonPath('code', 'financial_redacted');

        $this->assertTrue(
            AuditEvent::query()->where('action', 'giving.unauthorized_path')->where('actor_id', $user->id)->exists()
        );

        unset($c2);
    }
}
