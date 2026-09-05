<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\WelfareFollowUpEntry;
use App\Models\WelfareFollowUpReminder;
use App\Models\WelfareRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 7.5: Follow Up and Report on Welfare Cases.
 */
class WelfareFollowUpTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'IDX-HQ']);
        $this->branch = Organization::create(['name' => 'Branch A', 'type' => 'branch', 'identifier' => 'IDX-A', 'parent_id' => $hq->id]);
    }

    private function grant(User $user, array $actions): void
    {
        $role = Role::create(['name' => 'welfare_fu_' . $user->id . '_' . substr(md5(implode(',', $actions)), 0, 6)]);
        foreach ($actions as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);
    }

    private function assessor(): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $this->grant($user, [
            'welfare.requests.read', 'welfare.requests.submit', 'welfare.restricted.read', 'welfare.assess',
        ]);

        return $user;
    }

    private function branchApprover(): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $this->grant($user, [
            'welfare.requests.read', 'welfare.restricted.read',
            'welfare.approvals.decide', 'welfare.approvals.branch',
        ]);

        return $user;
    }

    private function financeOfficer(): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $this->grant($user, [
            'welfare.requests.read', 'welfare.restricted.read',
            'welfare.deliveries.manage', 'welfare.deliveries.confirm', 'welfare.finance.read',
        ]);

        return $user;
    }

    private function welfareManager(): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $this->grant($user, [
            'welfare.requests.read', 'welfare.restricted.read',
            'welfare.follow_ups.manage', 'welfare.follow_ups.escalate',
            'welfare.reports.read', 'welfare.finance.read',
        ]);

        return $user;
    }

    private function identityReporter(): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $this->grant($user, [
            'welfare.reports.read', 'welfare.reports.identity.read', 'welfare.finance.read',
        ]);

        return $user;
    }

    private function member(string $suffix): Member
    {
        return Member::create([
            'membership_id' => 'S75-' . $suffix,
            'branch_id' => $this->branch->id,
            'registration_channel' => 'web',
            'first_name' => 'Follow',
            'last_name' => 'Member' . $suffix,
            'consent_data_processing' => true,
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
        ]);
    }

    /**
     * @return array{0:int,1:User,2:Member}
     */
    private function followUpCase(float $value = 25000, string $hash = 'hash-fu'): array
    {
        $assessor = $this->assessor();
        $approver = $this->branchApprover();
        $finance = $this->financeOfficer();
        $beneficiary = $this->member(substr($hash, -4));

        $requestId = $this->actingAsMfaVerified($assessor)
            ->postJson('/api/welfare-requests', [
                'branch_id' => $this->branch->id,
                'beneficiary_member_id' => $beneficiary->id,
                'request_type' => 'financial',
                'description' => 'Needs short-term support.',
                'priority' => 'normal',
                'requested_value' => $value,
                'consent_data_processing' => true,
                'consent_welfare_review' => true,
                'documents' => [[
                    'filename' => 'evidence.pdf',
                    'mime_type' => 'application/pdf',
                    'size_bytes' => 1024,
                    'content_hash' => $hash,
                ]],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($assessor)->postJson("/api/welfare-requests/{$requestId}/submit")->assertOk();
        $this->actingAsMfaVerified($assessor)->postJson("/api/welfare-requests/{$requestId}/assess", [
            'assessment_notes' => 'Recommend cash support.',
            'verified_documents' => ['evidence.pdf'],
            'priority' => 'normal',
            'recommendation' => 'approve',
            'proposed_assistance_type' => 'cash',
            'proposed_value' => $value,
            'complete' => true,
        ])->assertOk();

        $this->actingAsMfaVerified($approver)->postJson("/api/welfare-requests/{$requestId}/decisions", [
            'decision' => 'approved',
            'reason' => 'Branch approves within threshold.',
        ])->assertOk();

        $deliveryId = $this->actingAsMfaVerified($finance)
            ->postJson("/api/welfare-requests/{$requestId}/deliveries", [
                'delivery_type' => 'disbursement',
                'method' => 'cash',
                'amount' => $value,
                'delivered_on' => now()->toDateString(),
                'reference' => 'TRX-' . $hash,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($finance)
            ->postJson("/api/welfare-deliveries/{$deliveryId}/confirm", ['status' => 'confirmed'])
            ->assertOk()
            ->assertJsonPath('data.case.status', WelfareRequest::STATUS_FOLLOW_UP);

        return [$requestId, $assessor, $beneficiary];
    }

    // ------------------------------------------------------------------
    // AC1 — follow-up, transitions, overdue reminders/escalation
    // ------------------------------------------------------------------

    public function test_officer_records_follow_up_outcome_and_due_date(): void
    {
        [$requestId] = $this->followUpCase(25000, 'hash-fu01');
        $manager = $this->welfareManager();
        $due = now()->addDays(5)->toDateString();

        $this->actingAsMfaVerified($manager)
            ->postJson("/api/welfare-requests/{$requestId}/follow-ups", [
                'outcome' => 'contacted',
                'further_action' => 'reschedule',
                'follow_up_due_on' => $due,
                'notes' => 'Beneficiary confirmed receipt and needs check-in.',
            ])
            ->assertCreated()
            ->assertJsonPath('case.status', WelfareRequest::STATUS_FOLLOW_UP)
            ->assertJsonPath('case.follow_up_due_on', $due)
            ->assertJsonPath('data.outcome', 'contacted');

        $this->assertDatabaseHas('welfare_follow_up_entries', [
            'welfare_request_id' => $requestId,
            'entry_type' => WelfareFollowUpEntry::TYPE_FOLLOW_UP,
            'further_action' => 'reschedule',
        ]);
    }

    public function test_closure_requires_evidence_and_moves_to_closed(): void
    {
        [$requestId] = $this->followUpCase(25000, 'hash-fu02');
        $manager = $this->welfareManager();

        $this->actingAsMfaVerified($manager)
            ->postJson("/api/welfare-requests/{$requestId}/close", [
                'closure_reason' => 'resolved',
                'notes' => 'Need met.',
            ])
            ->assertStatus(422);

        $this->actingAsMfaVerified($manager)
            ->postJson("/api/welfare-requests/{$requestId}/close", [
                'closure_reason' => 'resolved',
                'notes' => 'Need met.',
                'evidence' => [[
                    'filename' => 'closure.pdf',
                    'mime_type' => 'application/pdf',
                    'size_bytes' => 2048,
                    'content_hash' => 'closure-hash-1',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('case.status', WelfareRequest::STATUS_CLOSED)
            ->assertJsonPath('data.entry_type', WelfareFollowUpEntry::TYPE_CLOSURE);

        $this->assertNotNull(WelfareRequest::query()->find($requestId)?->closed_at);
    }

    public function test_overdue_follow_up_triggers_reminder_then_escalation(): void
    {
        [$requestId] = $this->followUpCase(25000, 'hash-fu03');
        $manager = $this->welfareManager();

        WelfareRequest::query()->whereKey($requestId)->update([
            'follow_up_due_on' => now()->subDays(1)->toDateString(),
        ]);

        $this->actingAsMfaVerified($manager)
            ->postJson('/api/welfare-follow-ups/process-overdue')
            ->assertOk()
            ->assertJsonPath('data.reminded', 1);

        $this->assertDatabaseHas('welfare_follow_up_reminders', [
            'welfare_request_id' => $requestId,
            'reminder_type' => WelfareFollowUpReminder::TYPE_OVERDUE_REMINDER,
        ]);

        WelfareRequest::query()->whereKey($requestId)->update([
            'follow_up_due_on' => now()->subDays(4)->toDateString(),
            'last_follow_up_reminder_at' => now()->subDays(2),
        ]);

        $this->actingAsMfaVerified($manager)
            ->postJson('/api/welfare-follow-ups/process-overdue')
            ->assertOk()
            ->assertJsonPath('data.escalated', 1);

        $this->assertSame(
            WelfareRequest::STATUS_ESCALATED,
            WelfareRequest::query()->find($requestId)?->status,
        );
        $this->assertDatabaseHas('welfare_follow_up_reminders', [
            'welfare_request_id' => $requestId,
            'reminder_type' => WelfareFollowUpReminder::TYPE_OVERDUE_ESCALATION,
        ]);
    }

    public function test_invalid_status_cannot_receive_follow_up(): void
    {
        $assessor = $this->assessor();
        $beneficiary = $this->member('drft');
        $manager = $this->welfareManager();

        $requestId = $this->actingAsMfaVerified($assessor)
            ->postJson('/api/welfare-requests', [
                'branch_id' => $this->branch->id,
                'beneficiary_member_id' => $beneficiary->id,
                'request_type' => 'food',
                'description' => 'Draft only.',
                'priority' => 'low',
                'consent_data_processing' => true,
                'consent_welfare_review' => true,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($manager)
            ->postJson("/api/welfare-requests/{$requestId}/follow-ups", [
                'outcome' => 'contacted',
                'further_action' => 'continue',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_status');
    }

    // ------------------------------------------------------------------
    // AC2 — reporting with minimized identity
    // ------------------------------------------------------------------

    public function test_welfare_report_aggregates_and_minimizes_identity(): void
    {
        [$requestId, , $beneficiary] = $this->followUpCase(30000, 'hash-fu04');
        $manager = $this->welfareManager();

        $report = $this->actingAsMfaVerified($manager)
            ->getJson('/api/welfare-reports?' . http_build_query([
                'branch_id' => $this->branch->id,
                'request_type' => 'financial',
                'status' => WelfareRequest::STATUS_FOLLOW_UP,
            ]))
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $report['summary']['case_count']);
        $this->assertSame(1, $report['summary']['beneficiary_count']);
        $this->assertSame(30000.0, (float) $report['summary']['expenditure_total']);
        $this->assertFalse($report['identity_included']);
        $this->assertNull($report['cases'][0]['beneficiary_member_id']);
        $this->assertNull($report['cases'][0]['beneficiary_name']);
        $this->assertTrue($report['cases'][0]['identity_minimized']);
        $this->assertSame($requestId, $report['cases'][0]['id']);
        $this->assertNotSame($beneficiary->first_name, $report['cases'][0]['beneficiary_name']);
    }

    public function test_identity_in_report_requires_explicit_authorization(): void
    {
        $this->followUpCase(20000, 'hash-fu05');
        $manager = $this->welfareManager();
        $identity = $this->identityReporter();

        $this->actingAsMfaVerified($manager)
            ->getJson('/api/welfare-reports?include_identity=1')
            ->assertForbidden();

        $report = $this->actingAsMfaVerified($identity)
            ->getJson('/api/welfare-reports?include_identity=1')
            ->assertOk()
            ->json('data');

        $this->assertTrue($report['identity_included']);
        $this->assertNotNull($report['cases'][0]['beneficiary_member_id']);
        $this->assertNotNull($report['cases'][0]['beneficiary_name']);
    }
}
