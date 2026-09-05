<?php

namespace Tests\Feature;

use App\Models\Contribution;
use App\Models\ContributionReceipt;
use App\Models\Member;
use App\Models\MemberNotification;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 11.2: Reconcile Contributions and Issue Receipts.
 */
class ContributionReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'REC-HQ']);
        $this->branch = Organization::create(['name' => 'Branch A', 'type' => 'branch', 'identifier' => 'REC-A', 'parent_id' => $hq->id]);
    }

    private function grant(User $user, array $actions): void
    {
        $role = Role::create(['name' => 'rec_' . $user->id . '_' . substr(md5(implode(',', $actions)), 0, 6)]);
        foreach ($actions as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);
    }

    private function officer(): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $this->grant($user, [
            'payments.contributions.read',
            'payments.contributions.reconcile',
            'payments.contributions.manual',
            'payments.receipts.issue',
            'payments.receipts.void',
        ]);

        return $user;
    }

    private function member(): Member
    {
        $user = User::factory()->create([
            'branch_id' => $this->branch->id,
            'email' => 'giver' . uniqid() . '@church.test',
        ]);

        return Member::create([
            'membership_id' => 'REC-M-' . $user->id,
            'branch_id' => $this->branch->id,
            'user_id' => $user->id,
            'registration_channel' => 'web',
            'first_name' => 'Giver',
            'last_name' => 'One',
            'email' => $user->email,
            'consent_data_processing' => true,
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
            'communication_preferences' => ['in_app' => true, 'email' => true],
        ]);
    }

    public function test_match_reconcile_preserves_provider_evidence_and_flags_duplicates(): void
    {
        $admin = $this->officer();
        $member = $this->member();

        $campaignId = $this->actingAsMfaVerified($admin)
            ->postJson('/api/contributions/campaigns', [
                'name' => 'Building Drive',
                'code' => 'BUILD26',
                'branch_id' => $this->branch->id,
            ])
            ->assertCreated()
            ->json('data.id');

        $id = $this->actingAsMfaVerified($admin)
            ->postJson('/api/contributions/manual', [
                'amount_cents' => 10000,
                'currency' => 'USD',
                'category' => 'building_fund',
                'branch_id' => $this->branch->id,
                'payment_reference' => 'CHK-1001',
                'notes' => 'Cheque deposit',
            ])
            ->assertCreated()
            ->json('data.id');

        $beforeEvidence = Contribution::query()->find($id)->provider_evidence;

        $matched = $this->actingAsMfaVerified($admin)
            ->postJson("/api/contributions/{$id}/match", [
                'member_id' => $member->id,
                'category' => 'building_fund',
                'campaign_id' => $campaignId,
                'branch_id' => $this->branch->id,
                'payment_reference' => 'CHK-1001',
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame('matched', $matched['reconciliation_status']);
        $this->assertSame($member->id, $matched['member_id']);
        $this->assertEquals($beforeEvidence, Contribution::query()->find($id)->provider_evidence);

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/contributions/{$id}/reconcile")
            ->assertOk()
            ->assertJsonPath('data.reconciliation_status', 'reconciled');

        // Duplicate reference on new manual entry.
        $this->actingAsMfaVerified($admin)
            ->postJson('/api/contributions/manual', [
                'amount_cents' => 5000,
                'currency' => 'USD',
                'category' => 'tithe',
                'branch_id' => $this->branch->id,
                'payment_reference' => 'CHK-1001',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'duplicate_reference');

        // Amount mismatch flags needs_resolution without changing evidence.
        $id2 = $this->actingAsMfaVerified($admin)
            ->postJson('/api/contributions/manual', [
                'amount_cents' => 2500,
                'currency' => 'USD',
                'category' => 'offering',
                'branch_id' => $this->branch->id,
                'payment_reference' => 'CHK-2002',
            ])
            ->assertCreated()
            ->json('data.id');

        $evidence2 = Contribution::query()->find($id2)->provider_evidence;

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/contributions/{$id2}/match", [
                'member_id' => $member->id,
                'expected_amount_cents' => 9999,
            ])
            ->assertOk()
            ->assertJsonPath('data.reconciliation_status', 'needs_resolution')
            ->assertJsonPath('data.resolution_reason', 'amount_mismatch');

        $this->assertEquals($evidence2, Contribution::query()->find($id2)->provider_evidence);
    }

    public function test_receipt_issue_delivery_void_creates_adjustment_not_delete(): void
    {
        $admin = $this->officer();
        $member = $this->member();

        $id = $this->actingAsMfaVerified($admin)
            ->postJson('/api/contributions/manual', [
                'amount_cents' => 7500,
                'currency' => 'USD',
                'category' => 'tithe',
                'branch_id' => $this->branch->id,
                'member_id' => $member->id,
                'payment_reference' => 'CASH-77',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/contributions/{$id}/match", [
                'member_id' => $member->id,
                'category' => 'tithe',
                'payment_reference' => 'CASH-77',
            ])
            ->assertOk();

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/contributions/{$id}/reconcile")
            ->assertOk();

        $receipt = $this->actingAsMfaVerified($admin)
            ->postJson("/api/contributions/{$id}/receipts", ['deliver' => true])
            ->assertCreated()
            ->json('data');

        $this->assertSame('issued', $receipt['status']);
        $this->assertTrue($receipt['delivered']);
        $this->assertSame('in_app', $receipt['delivery_channel']);
        $this->assertNotEmpty($receipt['verification_code']);
        $this->assertSame(7500, $receipt['financial_fields']['amount_cents']);

        $this->assertDatabaseHas('member_notifications', [
            'member_id' => $member->id,
            'type' => 'giving.receipt',
        ]);

        // Second receipt blocked.
        $this->actingAsMfaVerified($admin)
            ->postJson("/api/contributions/{$id}/receipts")
            ->assertStatus(422)
            ->assertJsonPath('code', 'receipt_exists');

        $this->getJson('/api/receipts/verify?' . http_build_query([
            'receipt_number' => $receipt['receipt_number'],
            'verification_code' => $receipt['verification_code'],
        ]))
            ->assertOk()
            ->assertJsonPath('data.receipt_number', $receipt['receipt_number']);

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/receipts/{$receipt['id']}/void", [
                'reason' => 'Issued to wrong member',
            ])
            ->assertOk()
            ->assertJsonPath('data.adjustment_type', 'void');

        $this->assertDatabaseHas('contribution_receipts', [
            'id' => $receipt['id'],
            'status' => ContributionReceipt::STATUS_VOIDED,
        ]);
        $this->assertDatabaseHas('contribution_adjustments', [
            'contribution_id' => $id,
            'adjustment_type' => 'void',
        ]);
        $this->assertSame(1, Contribution::query()->whereKey($id)->count());
        $this->assertSame(1, ContributionReceipt::query()->whereKey($receipt['id'])->count());
        $this->assertSame(1, MemberNotification::query()->where('member_id', $member->id)->count());
    }
}
