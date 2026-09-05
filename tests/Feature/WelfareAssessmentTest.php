<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\MemberNotification;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\WelfareAssessmentVersion;
use App\Models\WelfareRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 7.2: Assess and Recommend Welfare Assistance.
 */
class WelfareAssessmentTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'IDX-HQ']);
        $this->branch = Organization::create(['name' => 'Branch A', 'type' => 'branch', 'identifier' => 'IDX-A', 'parent_id' => $hq->id]);
    }

    private function officer(array $extra = []): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $role = Role::create(['name' => 'welfare_assess_' . $user->id]);
        foreach (array_merge([
            'welfare.requests.read',
            'welfare.requests.submit',
            'welfare.restricted.read',
            'welfare.assess',
        ], $extra) as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);

        return $user;
    }

    private function memberUser(Member $member): User
    {
        $user = User::factory()->create([
            'branch_id' => $this->branch->id,
            'has_mfa_enrolled' => true,
        ]);
        $member->update(['user_id' => $user->id]);

        $role = Role::create(['name' => 'welfare_member_' . $user->id]);
        foreach (['welfare.requests.read.self', 'welfare.requests.submit.self'] as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);

        return $user;
    }

    private function member(string $suffix): Member
    {
        return Member::create([
            'membership_id' => 'S72-' . $suffix,
            'branch_id' => $this->branch->id,
            'registration_channel' => 'web',
            'first_name' => 'Assess',
            'last_name' => 'Member' . $suffix,
            'consent_data_processing' => true,
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
        ]);
    }

    private function submittedCase(User $officer, Member $beneficiary, string $hash = 'assess-hash'): int
    {
        $requestId = $this->actingAsMfaVerified($officer)
            ->postJson('/api/welfare-requests', [
                'branch_id' => $this->branch->id,
                'beneficiary_member_id' => $beneficiary->id,
                'request_type' => 'financial',
                'description' => 'Family needs rent support.',
                'priority' => 'high',
                'requested_value' => 100000,
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

        $this->actingAsMfaVerified($officer)
            ->postJson("/api/welfare-requests/{$requestId}/submit")
            ->assertOk();

        return $requestId;
    }

    // ------------------------------------------------------------------
    // AC1 — assessment version + advance to review + SoD
    // ------------------------------------------------------------------

    public function test_officer_records_complete_assessment_and_advances_to_review(): void
    {
        $officer = $this->officer();
        $beneficiary = $this->member('A1');
        $requestId = $this->submittedCase($officer, $beneficiary, 'hash-a1');

        $this->actingAsMfaVerified($officer)
            ->postJson("/api/welfare-requests/{$requestId}/assign", ['officer_id' => $officer->id])
            ->assertOk()
            ->assertJsonPath('data.status', WelfareRequest::STATUS_UNDER_ASSESSMENT);

        $result = $this->actingAsMfaVerified($officer)
            ->postJson("/api/welfare-requests/{$requestId}/assess", [
                'assessment_notes' => 'Documents verified. Recommend partial cash support.',
                'verified_documents' => ['evidence.pdf'],
                'priority' => 'high',
                'recommendation' => 'partial_approve',
                'proposed_assistance_type' => 'cash',
                'proposed_value' => 75000,
                'follow_up_needs' => 'Confirm landlord details within 7 days.',
                'complete' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', WelfareRequest::STATUS_PENDING_APPROVAL)
            ->json('data');

        $this->assertSame(1, $result['current_assessment_version']);
        $this->assertTrue($result['assessments'][0]['complete']);
        $this->assertNotEmpty($result['assessments'][0]['recorded_at']);
        $this->assertNotEmpty($result['approvals']['steps']);

        $this->assertDatabaseHas('welfare_assessment_versions', [
            'welfare_request_id' => $requestId,
            'version' => 1,
            'recommendation' => 'partial_approve',
            'complete' => true,
        ]);
    }

    public function test_assessor_cannot_approve_prohibited_levels(): void
    {
        $submitter = $this->officer();
        $assessor = $this->officer([
            'welfare.approvals.decide',
            'welfare.approvals.branch',
        ]);
        $beneficiary = $this->member('SOD');
        $requestId = $this->submittedCase($submitter, $beneficiary, 'hash-sod');

        $this->actingAsMfaVerified($submitter)
            ->postJson("/api/welfare-requests/{$requestId}/assign", ['officer_id' => $assessor->id])
            ->assertOk();

        $this->actingAsMfaVerified($assessor)
            ->postJson("/api/welfare-requests/{$requestId}/assess", [
                'assessment_notes' => 'Ready for review.',
                'verified_documents' => ['evidence.pdf'],
                'priority' => 'normal',
                'recommendation' => 'approve',
                'proposed_assistance_type' => 'cash',
                'proposed_value' => 50000,
                'complete' => true,
            ])
            ->assertOk();

        $this->actingAsMfaVerified($assessor)
            ->postJson("/api/welfare-requests/{$requestId}/approve", [
                'decision' => 'approved',
                'reason' => 'Attempting assessor approval.',
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'sod_prohibited_approval');
    }

    // ------------------------------------------------------------------
    // AC2 — conditions: return / reassign / escalate + beneficiary message
    // ------------------------------------------------------------------

    public function test_missing_evidence_returns_case_with_beneficiary_message(): void
    {
        $officer = $this->officer();
        $beneficiary = $this->member('RET');
        $memberUser = $this->memberUser($beneficiary);
        $requestId = $this->submittedCase($officer, $beneficiary, 'hash-ret');

        $result = $this->actingAsMfaVerified($officer)
            ->postJson("/api/welfare-requests/{$requestId}/conditions", [
                'condition_type' => 'missing_evidence',
                'notes' => 'Need recent utility bill.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', WelfareRequest::STATUS_RETURNED_FOR_INFO)
            ->json('data');

        $this->assertSame(
            'Additional information is needed before your request can continue.',
            $result['beneficiary_status_message'],
        );

        $memberView = $this->actingAsMfaVerified($memberUser)
            ->getJson("/api/welfare-requests/{$requestId}")
            ->assertOk()
            ->json('data');

        $this->assertSame($result['beneficiary_status_message'], $memberView['beneficiary_status_message']);
        $this->assertSame([], $memberView['assessments']);
        $this->assertSame([], $memberView['case_events']);

        $this->assertDatabaseHas('member_notifications', [
            'member_id' => $beneficiary->id,
            'type' => 'welfare.request.status_updated',
        ]);
    }

    public function test_conflict_of_interest_reassigns_to_another_officer(): void
    {
        $officer = $this->officer();
        $replacement = $this->officer();
        $beneficiary = $this->member('COI');
        $requestId = $this->submittedCase($officer, $beneficiary, 'hash-coi');

        $this->actingAsMfaVerified($officer)
            ->postJson("/api/welfare-requests/{$requestId}/assign", ['officer_id' => $officer->id])
            ->assertOk();

        $this->actingAsMfaVerified($officer)
            ->postJson("/api/welfare-requests/{$requestId}/conditions", [
                'condition_type' => 'conflict_of_interest',
                'notes' => 'Officer is related to beneficiary.',
                'reassign_to_officer_id' => $replacement->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', WelfareRequest::STATUS_UNDER_ASSESSMENT)
            ->assertJsonPath('data.assigned_officer_id', $replacement->id);
    }

    public function test_duplicate_assistance_escalates_case(): void
    {
        $officer = $this->officer();
        $beneficiary = $this->member('ESC');
        $requestId = $this->submittedCase($officer, $beneficiary, 'hash-esc');

        $this->actingAsMfaVerified($officer)
            ->postJson("/api/welfare-requests/{$requestId}/conditions", [
                'condition_type' => 'duplicate_assistance',
                'notes' => 'Similar assistance granted last month.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', WelfareRequest::STATUS_ESCALATED)
            ->assertJsonPath(
                'data.beneficiary_status_message',
                'Your request is under senior welfare review.',
            );
    }

    public function test_unauthorized_user_cannot_assess(): void
    {
        $officer = $this->officer();
        $outsider = User::factory()->create([
            'branch_id' => $this->branch->id,
            'has_mfa_enrolled' => true,
        ]);
        $beneficiary = $this->member('SEC');
        $requestId = $this->submittedCase($officer, $beneficiary, 'hash-sec');

        $this->actingAsMfaVerified($outsider)
            ->postJson("/api/welfare-requests/{$requestId}/assess", [
                'assessment_notes' => 'Should fail.',
                'priority' => 'normal',
                'recommendation' => 'approve',
            ])
            ->assertForbidden();
    }
}
