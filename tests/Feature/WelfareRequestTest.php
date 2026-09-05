<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\MemberNotification;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\WelfareRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 7.1: Submit a Welfare Request.
 */
class WelfareRequestTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'IDX-HQ']);
        $this->branch = Organization::create(['name' => 'Branch A', 'type' => 'branch', 'identifier' => 'IDX-A', 'parent_id' => $hq->id]);
    }

    private function officer(): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $role = Role::create(['name' => 'welfare_officer_' . $user->id]);
        foreach ([
            'welfare.requests.read',
            'welfare.requests.submit',
            'welfare.restricted.read',
        ] as $action) {
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

    private function member(string $suffix, array $attrs = []): Member
    {
        return Member::create(array_merge([
            'membership_id' => 'S71-' . $suffix,
            'branch_id' => $this->branch->id,
            'registration_channel' => 'web',
            'first_name' => 'Welfare',
            'last_name' => 'Member' . $suffix,
            'consent_data_processing' => true,
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
        ], $attrs));
    }

    /**
     * @return array<string, mixed>
     */
    private function validDocument(string $hash = 'hash-valid-001'): array
    {
        return [
            'filename' => 'bank_statement.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 2048,
            'content_hash' => $hash,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requestPayload(Member $beneficiary, array $overrides = []): array
    {
        return array_merge([
            'branch_id' => $this->branch->id,
            'beneficiary_member_id' => $beneficiary->id,
            'request_type' => 'financial',
            'description' => 'Need assistance with rent for this month.',
            'priority' => 'high',
            'requested_value' => 150000,
            'consent_data_processing' => true,
            'consent_welfare_review' => true,
            'documents' => [$this->validDocument()],
        ], $overrides);
    }

    // ------------------------------------------------------------------
    // AC1 — create confidential case with scoped access
    // ------------------------------------------------------------------

    public function test_officer_creates_and_submits_welfare_request(): void
    {
        $officer = $this->officer();
        $beneficiary = $this->member('OFF');

        $created = $this->actingAsMfaVerified($officer)
            ->postJson('/api/welfare-requests', $this->requestPayload($beneficiary))
            ->assertCreated()
            ->assertJsonPath('data.status', WelfareRequest::STATUS_DRAFT);

        $requestId = $created->json('data.id');
        $caseNumber = $created->json('data.case_number');
        $this->assertNotEmpty($caseNumber);

        $this->actingAsMfaVerified($officer)
            ->postJson("/api/welfare-requests/{$requestId}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', WelfareRequest::STATUS_SUBMITTED);

        $this->assertDatabaseHas('welfare_requests', [
            'id' => $requestId,
            'branch_id' => $this->branch->id,
            'status' => WelfareRequest::STATUS_SUBMITTED,
        ]);
    }

    public function test_member_submits_own_welfare_request(): void
    {
        $member = $this->member('SELF');
        $memberUser = $this->memberUser($member);

        $requestId = $this->actingAsMfaVerified($memberUser)
            ->postJson('/api/me/welfare-requests', $this->requestPayload($member))
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($memberUser)
            ->postJson("/api/welfare-requests/{$requestId}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', WelfareRequest::STATUS_SUBMITTED);

        $this->assertDatabaseHas('member_notifications', [
            'member_id' => $member->id,
            'type' => 'welfare.request.submitted',
        ]);
    }

    public function test_unauthorized_user_cannot_view_welfare_request(): void
    {
        $officer = $this->officer();
        $outsider = User::factory()->create(['branch_id' => $this->branch->id, 'has_mfa_enrolled' => true]);
        $beneficiary = $this->member('SEC');

        $requestId = $this->actingAsMfaVerified($officer)
            ->postJson('/api/welfare-requests', $this->requestPayload($beneficiary))
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($outsider)
            ->getJson("/api/welfare-requests/{$requestId}")
            ->assertForbidden();
    }

    // ------------------------------------------------------------------
    // AC2 — document validation with recoverable draft
    // ------------------------------------------------------------------

    public function test_invalid_document_is_rejected_and_draft_preserved(): void
    {
        $officer = $this->officer();
        $beneficiary = $this->member('DOC');

        $requestId = $this->actingAsMfaVerified($officer)
            ->postJson('/api/welfare-requests', $this->requestPayload($beneficiary, [
                'documents' => [[
                    'filename' => 'statement.pdf',
                    'mime_type' => 'application/x-msdownload',
                    'size_bytes' => 2048,
                    'content_hash' => 'bad-mime-hash',
                ]],
            ]))
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($officer)
            ->postJson("/api/welfare-requests/{$requestId}/submit")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['documents']);

        $draft = $this->actingAsMfaVerified($officer)
            ->getJson("/api/welfare-requests/{$requestId}")
            ->assertOk()
            ->json('data');

        $this->assertSame(WelfareRequest::STATUS_DRAFT, $draft['status']);
        $this->assertSame('Need assistance with rent for this month.', $draft['description']);
        $this->assertSame('rejected', $draft['supporting_documents'][0]['status']);
    }

    public function test_duplicate_document_hash_is_rejected(): void
    {
        $officer = $this->officer();
        $beneficiary = $this->member('DUP');

        $firstId = $this->actingAsMfaVerified($officer)
            ->postJson('/api/welfare-requests', $this->requestPayload($beneficiary, [
                'documents' => [$this->validDocument('duplicate-hash-001')],
            ]))
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($officer)
            ->postJson("/api/welfare-requests/{$firstId}/submit")
            ->assertOk();

        $secondId = $this->actingAsMfaVerified($officer)
            ->postJson('/api/welfare-requests', $this->requestPayload($beneficiary, [
                'request_type' => 'medical',
                'documents' => [$this->validDocument('duplicate-hash-001')],
            ]))
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($officer)
            ->postJson("/api/welfare-requests/{$secondId}/submit")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['documents']);
    }

    public function test_submit_without_consent_is_rejected(): void
    {
        $officer = $this->officer();
        $beneficiary = $this->member('CON');

        $requestId = $this->actingAsMfaVerified($officer)
            ->postJson('/api/welfare-requests', $this->requestPayload($beneficiary, [
                'consent_welfare_review' => false,
            ]))
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($officer)
            ->postJson("/api/welfare-requests/{$requestId}/submit")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['consent_welfare_review']);
    }
}
