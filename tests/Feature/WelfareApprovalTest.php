<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\WelfareApprovalDecision;
use App\Models\WelfareApprovalStep;
use App\Models\WelfareRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 7.3: Route Welfare Approval by Configured Threshold.
 */
class WelfareApprovalTest extends TestCase
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
        $role = Role::create(['name' => 'welfare_role_' . $user->id . '_' . substr(md5(implode(',', $actions)), 0, 6)]);
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

    private function hqApprover(): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $this->grant($user, [
            'welfare.requests.read', 'welfare.restricted.read',
            'welfare.approvals.decide', 'welfare.approvals.hq',
        ]);

        return $user;
    }

    private function configurator(): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $this->grant($user, [
            'welfare.requests.read', 'welfare.approvals.configure',
        ]);

        return $user;
    }

    private function member(string $suffix): Member
    {
        return Member::create([
            'membership_id' => 'S73-' . $suffix,
            'branch_id' => $this->branch->id,
            'registration_channel' => 'web',
            'first_name' => 'Approve',
            'last_name' => 'Member' . $suffix,
            'consent_data_processing' => true,
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
        ]);
    }

    private function casePendingApproval(User $assessor, Member $beneficiary, float $value, string $hash): int
    {
        $requestId = $this->actingAsMfaVerified($assessor)
            ->postJson('/api/welfare-requests', [
                'branch_id' => $this->branch->id,
                'beneficiary_member_id' => $beneficiary->id,
                'request_type' => 'financial',
                'description' => 'Needs assistance.',
                'priority' => 'high',
                'requested_value' => $value,
                'consent_data_processing' => true,
                'consent_welfare_review' => true,
                'documents' => [[
                    'filename' => 'evidence.pdf',
                    'mime_type' => 'application/pdf',
                    'size_bytes' => 2048,
                    'content_hash' => $hash,
                ]],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($assessor)
            ->postJson("/api/welfare-requests/{$requestId}/submit")
            ->assertOk();

        $this->actingAsMfaVerified($assessor)
            ->postJson("/api/welfare-requests/{$requestId}/assess", [
                'assessment_notes' => 'Recommend support.',
                'verified_documents' => ['evidence.pdf'],
                'priority' => 'high',
                'recommendation' => 'approve',
                'proposed_assistance_type' => 'cash',
                'proposed_value' => $value,
                'complete' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', WelfareRequest::STATUS_PENDING_APPROVAL);

        return $requestId;
    }

    // ------------------------------------------------------------------
    // AC1 — threshold routing + config version retained
    // ------------------------------------------------------------------

    public function test_completed_recommendation_creates_threshold_sequence(): void
    {
        $assessor = $this->assessor();
        $beneficiary = $this->member('R1');
        $requestId = $this->casePendingApproval($assessor, $beneficiary, 150000, 'hash-r1');

        $show = $this->actingAsMfaVerified($assessor)
            ->getJson("/api/welfare-requests/{$requestId}")
            ->assertOk()
            ->json('data.approvals');

        $this->assertSame(['branch', 'hq'], array_column($show['steps'], 'level'));
        $this->assertNotNull($show['approval_config_version']['version']);
        $this->assertTrue($show['steps'][0]['is_current']);
    }

    public function test_high_value_requires_full_approval_chain(): void
    {
        $assessor = $this->assessor();
        $beneficiary = $this->member('R2');
        $requestId = $this->casePendingApproval($assessor, $beneficiary, 750000, 'hash-r2');

        $levels = $this->actingAsMfaVerified($assessor)
            ->getJson("/api/welfare-requests/{$requestId}")
            ->json('data.approvals.steps');

        $this->assertSame(['branch', 'hq', 'executive', 'finance'], array_column($levels, 'level'));
    }

    // ------------------------------------------------------------------
    // AC2 — decisions, immutability, self-approval / bypass guards
    // ------------------------------------------------------------------

    public function test_approver_advances_and_cannot_bypass_levels(): void
    {
        $assessor = $this->assessor();
        $branchApprover = $this->branchApprover();
        $hqApprover = $this->hqApprover();
        $beneficiary = $this->member('D1');
        $requestId = $this->casePendingApproval($assessor, $beneficiary, 150000, 'hash-d1');

        // HQ cannot decide while branch is current
        $this->actingAsMfaVerified($hqApprover)
            ->postJson("/api/welfare-requests/{$requestId}/decisions", [
                'decision' => 'approved',
                'reason' => 'Trying to skip branch level.',
            ])
            ->assertForbidden();

        $this->actingAsMfaVerified($branchApprover)
            ->postJson("/api/welfare-requests/{$requestId}/decisions", [
                'decision' => 'approved',
                'reason' => 'Branch endorses assistance.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', WelfareRequest::STATUS_PENDING_APPROVAL);

        $this->assertDatabaseHas('welfare_approval_decisions', [
            'welfare_request_id' => $requestId,
            'level' => 'branch',
            'decision' => 'approved',
            'decided_by' => $branchApprover->id,
        ]);

        $this->actingAsMfaVerified($hqApprover)
            ->postJson("/api/welfare-requests/{$requestId}/decisions", [
                'decision' => 'approved',
                'reason' => 'HQ endorses assistance.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', WelfareRequest::STATUS_APPROVED);

        $this->assertSame(2, WelfareApprovalDecision::query()->where('welfare_request_id', $requestId)->count());
    }

    public function test_requester_cannot_approve_own_request(): void
    {
        $assessor = $this->assessor();
        $beneficiary = $this->member('SELF');
        $requestId = $this->casePendingApproval($assessor, $beneficiary, 40000, 'hash-self');

        // Give assessor branch approve rights AND make them the requester already is assessor
        // Use a different user who is requester - recreate with member as requester
        $memberUser = User::factory()->create([
            'branch_id' => $this->branch->id,
            'has_mfa_enrolled' => true,
        ]);
        $beneficiary->update(['user_id' => $memberUser->id]);
        $this->grant($memberUser, [
            'welfare.approvals.decide', 'welfare.approvals.branch', 'welfare.requests.read.self',
        ]);

        WelfareRequest::query()->where('id', $requestId)->update([
            'requester_user_id' => $memberUser->id,
            'requester_member_id' => $beneficiary->id,
        ]);

        $this->actingAsMfaVerified($memberUser)
            ->postJson("/api/welfare-requests/{$requestId}/decisions", [
                'decision' => 'approved',
                'reason' => 'Approving my own case.',
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'self_approval');
    }

    // ------------------------------------------------------------------
    // AC3 — in-flight policy / reevaluate retains completed approvals
    // ------------------------------------------------------------------

    public function test_reevaluate_keeps_completed_approvals_when_thresholds_change(): void
    {
        $assessor = $this->assessor();
        $branchApprover = $this->branchApprover();
        $configurator = $this->configurator();
        $beneficiary = $this->member('RE');
        $requestId = $this->casePendingApproval($assessor, $beneficiary, 150000, 'hash-re');

        $this->actingAsMfaVerified($branchApprover)
            ->postJson("/api/welfare-requests/{$requestId}/decisions", [
                'decision' => 'approved',
                'reason' => 'Branch approved before policy change.',
            ])
            ->assertOk();

        $this->actingAsMfaVerified($configurator)
            ->postJson('/api/welfare-approval-configs/publish', [
                'name' => 'Tightened thresholds',
                'thresholds' => [
                    ['max_value' => 10000, 'levels' => ['branch']],
                    ['max_value' => null, 'levels' => ['branch', 'hq', 'executive']],
                ],
            ])
            ->assertCreated();

        $result = $this->actingAsMfaVerified($configurator)
            ->postJson("/api/welfare-requests/{$requestId}/reevaluate-approvals", [
                'use_published_policy' => true,
            ])
            ->assertOk()
            ->json('data.approvals');

        $this->assertDatabaseHas('welfare_approval_decisions', [
            'welfare_request_id' => $requestId,
            'level' => 'branch',
            'decision' => 'approved',
        ]);

        $pendingLevels = collect($result['steps'])
            ->where('status', WelfareApprovalStep::STATUS_PENDING)
            ->pluck('level')
            ->values()
            ->all();

        $this->assertContains('hq', $pendingLevels);
        $this->assertContains('executive', $pendingLevels);
        $this->assertSame(WelfareRequest::STATUS_PENDING_APPROVAL, $result['steps'] ? WelfareRequest::query()->find($requestId)->status : null);
    }
}
