<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\CareCase;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Story 8.1: Create a Restricted Care Case.
 */
class CareCaseTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;
    private Organization $otherBranch;

    protected function setUp(): void
    {
        parent::setUp();

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'CARE-HQ']);
        $this->branch = Organization::create(['name' => 'Branch A', 'type' => 'branch', 'identifier' => 'CARE-A', 'parent_id' => $hq->id]);
        $this->otherBranch = Organization::create(['name' => 'Branch B', 'type' => 'branch', 'identifier' => 'CARE-B', 'parent_id' => $hq->id]);
    }

    private function grant(User $user, array $actions): void
    {
        $role = Role::create(['name' => 'care_' . $user->id . '_' . substr(md5(implode(',', $actions)), 0, 6)]);
        foreach ($actions as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);
    }

    private function officer(array $extra = []): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $this->grant($user, array_merge([
            'care.cases.create',
            'care.cases.read',
            'care.cases.sensitive.read',
            'care.cases.manage',
        ], $extra));

        return $user;
    }

    private function readerOnly(): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $this->grant($user, ['care.cases.read']);

        return $user;
    }

    private function outsider(): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->otherBranch->id]);
        $this->grant($user, [
            'care.cases.create',
            'care.cases.read',
            'care.cases.sensitive.read',
            'care.cases.manage',
        ]);

        return $user;
    }

    private function member(string $suffix, ?Organization $branch = null): Member
    {
        return Member::create([
            'membership_id' => 'CARE-' . $suffix,
            'branch_id' => ($branch ?? $this->branch)->id,
            'registration_channel' => 'web',
            'first_name' => 'Care',
            'last_name' => 'Member' . $suffix,
            'consent_data_processing' => true,
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
        ]);
    }

    // ------------------------------------------------------------------
    // AC1 — create restricted case with encrypted fields + assignment
    // ------------------------------------------------------------------

    public function test_authorized_officer_creates_restricted_care_case(): void
    {
        $officer = $this->officer();
        $beneficiary = $this->member('01');

        $payload = [
            'branch_id' => $this->branch->id,
            'beneficiary_member_id' => $beneficiary->id,
            'category' => 'bereavement',
            'description' => 'Family requested pastoral support after a loss.',
            'sensitive_notes' => 'Private counselling context for assigned caregiver.',
            'priority' => 'high',
            'consent_basis' => 'family_request',
            'confidentiality' => 'care_team',
            'evidence' => [[
                'filename' => 'consent.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => 2048,
                'content_hash' => 'care-hash-01',
            ]],
        ];

        $case = $this->actingAsMfaVerified($officer)
            ->postJson('/api/care-cases', $payload)
            ->assertCreated()
            ->assertJsonPath('data.category', 'bereavement')
            ->assertJsonPath('data.status', CareCase::STATUS_ASSIGNED)
            ->assertJsonPath('data.is_restricted', true)
            ->assertJsonPath('data.data_classification', 'restricted_sensitive')
            ->assertJsonPath('data.description', $payload['description'])
            ->assertJsonPath('data.sensitive_notes', $payload['sensitive_notes'])
            ->json('data');

        $this->assertNotEmpty($case['case_number']);
        $this->assertSame($officer->id, $case['assigned_officer_id']);

        $raw = DB::table('care_cases')->where('id', $case['id'])->first();
        $this->assertNotSame($payload['description'], $raw->description);
        $this->assertNotSame($payload['sensitive_notes'], $raw->sensitive_notes);
        $this->assertStringContainsString('eyJpdiI6', (string) $raw->description);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'care_case.created',
            'subject_id' => $case['id'],
            'module' => 'care',
        ]);
    }

    public function test_case_requires_eligible_care_officer_assignment(): void
    {
        $creator = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $this->grant($creator, [
            'care.cases.create',
            'care.cases.read',
            'care.cases.sensitive.read',
            // intentionally no care.cases.manage — and no other eligible officer exists
        ]);
        $beneficiary = $this->member('02');

        $this->actingAsMfaVerified($creator)
            ->postJson('/api/care-cases', [
                'branch_id' => $this->branch->id,
                'beneficiary_member_id' => $beneficiary->id,
                'category' => 'hospital_visit',
                'description' => 'Member admitted to hospital overnight.',
                'priority' => 'urgent',
                'consent_basis' => 'emergency',
                'confidentiality' => 'care_team',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'no_eligible_officer');
    }

    public function test_case_can_be_assigned_to_explicit_eligible_officer(): void
    {
        $creator = $this->officer();
        $assignee = $this->officer();
        $beneficiary = $this->member('03');

        $this->actingAsMfaVerified($creator)
            ->postJson('/api/care-cases', [
                'branch_id' => $this->branch->id,
                'beneficiary_member_id' => $beneficiary->id,
                'category' => 'counselling',
                'description' => 'Ongoing counselling need requiring continuity.',
                'priority' => 'normal',
                'consent_basis' => 'member_request',
                'confidentiality' => 'assigned_only',
                'assigned_officer_id' => $assignee->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.assigned_officer_id', $assignee->id)
            ->assertJsonPath('data.confidentiality', 'assigned_only');
    }

    // ------------------------------------------------------------------
    // AC2 — omit inaccessible cases / details + audit denials
    // ------------------------------------------------------------------

    public function test_users_without_clearance_do_not_see_restricted_cases(): void
    {
        $officer = $this->officer();
        $reader = $this->readerOnly();
        $beneficiary = $this->member('04');

        $caseId = $this->actingAsMfaVerified($officer)
            ->postJson('/api/care-cases', [
                'branch_id' => $this->branch->id,
                'beneficiary_member_id' => $beneficiary->id,
                'category' => 'emergency',
                'description' => 'Emergency pastoral response needed tonight.',
                'sensitive_notes' => 'Do not disclose outside care team.',
                'priority' => 'urgent',
                'consent_basis' => 'emergency',
                'confidentiality' => 'care_team',
                'assigned_officer_id' => $officer->id,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($reader)
            ->getJson('/api/care-cases')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAsMfaVerified($reader)
            ->getJson("/api/care-cases/{$caseId}")
            ->assertForbidden();

        $this->assertDatabaseHas('audit_events', [
            'action' => 'care_case.access_denied',
            'subject_id' => $caseId,
            'actor_id' => $reader->id,
            'category' => AuditEvent::CATEGORY_SECURITY,
        ]);
    }

    public function test_out_of_branch_users_cannot_discover_cases(): void
    {
        $officer = $this->officer();
        $outsider = $this->outsider();
        $beneficiary = $this->member('05');

        $caseId = $this->actingAsMfaVerified($officer)
            ->postJson('/api/care-cases', [
                'branch_id' => $this->branch->id,
                'beneficiary_member_id' => $beneficiary->id,
                'category' => 'new_baby',
                'description' => 'Celebrate and support the new family.',
                'priority' => 'normal',
                'consent_basis' => 'member_request',
                'confidentiality' => 'care_team',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($outsider)
            ->getJson('/api/care-cases')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAsMfaVerified($outsider)
            ->getJson("/api/care-cases/{$caseId}")
            ->assertForbidden();
    }

    public function test_sensitive_view_is_audited_for_authorized_officer(): void
    {
        $officer = $this->officer();
        $beneficiary = $this->member('06');

        $caseId = $this->actingAsMfaVerified($officer)
            ->postJson('/api/care-cases', [
                'branch_id' => $this->branch->id,
                'beneficiary_member_id' => $beneficiary->id,
                'category' => 'marriage_family',
                'description' => 'Marriage counselling request with confidentiality.',
                'priority' => 'high',
                'consent_basis' => 'referral',
                'confidentiality' => 'pastor_only',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($officer)
            ->getJson("/api/care-cases/{$caseId}")
            ->assertOk()
            ->assertJsonPath('data.restricted_details_omitted', false)
            ->assertJsonPath('data.beneficiary.name', 'Care Member06');

        $this->assertDatabaseHas('audit_events', [
            'action' => 'care_case.sensitive_viewed',
            'subject_id' => $caseId,
            'actor_id' => $officer->id,
            'category' => AuditEvent::CATEGORY_SECURITY,
        ]);
    }
}
