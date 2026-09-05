<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\CareCase;
use App\Models\CareCaseActivity;
use App\Models\CareCaseEscalation;
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
 * Story 8.2: Deliver and Close Pastoral Care.
 */
class CareCaseDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'CARE2-HQ']);
        $this->branch = Organization::create(['name' => 'Branch A', 'type' => 'branch', 'identifier' => 'CARE2-A', 'parent_id' => $hq->id]);
    }

    private function grant(User $user, array $actions): void
    {
        $role = Role::create(['name' => 'care2_' . $user->id . '_' . substr(md5(implode(',', $actions)), 0, 6)]);
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
            'care.cases.escalate',
            'care.cases.close',
            'care.cases.reopen',
        ], $extra));

        return $user;
    }

    private function member(string $suffix): Member
    {
        return Member::create([
            'membership_id' => 'CARE2-' . $suffix,
            'branch_id' => $this->branch->id,
            'registration_channel' => 'web',
            'first_name' => 'Care',
            'last_name' => 'Person' . $suffix,
            'consent_data_processing' => true,
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
        ]);
    }

    private function openCase(User $officer, string $suffix = '01', array $overrides = []): int
    {
        $beneficiary = $this->member($suffix);

        return $this->actingAsMfaVerified($officer)
            ->postJson('/api/care-cases', array_merge([
                'branch_id' => $this->branch->id,
                'beneficiary_member_id' => $beneficiary->id,
                'category' => 'pastoral_visit',
                'description' => 'Member needs ongoing pastoral support this week.',
                'priority' => 'normal',
                'consent_basis' => 'member_request',
                'confidentiality' => 'care_team',
                'assigned_officer_id' => $officer->id,
            ], $overrides))
            ->assertCreated()
            ->json('data.id');
    }

    // ------------------------------------------------------------------
    // AC1 — chronological immutable activities
    // ------------------------------------------------------------------

    public function test_officer_records_immutable_care_activity_with_follow_up(): void
    {
        $officer = $this->officer();
        $caseId = $this->openCase($officer, 'act1');
        $due = now()->addDays(3)->toDateString();

        $activity = $this->actingAsMfaVerified($officer)
            ->postJson("/api/care-cases/{$caseId}/activities", [
                'activity_type' => 'visit',
                'outcome' => 'partial_progress',
                'notes' => 'Home visit completed.',
                'restricted_note' => 'Shared confidential family context.',
                'next_follow_up_on' => $due,
            ])
            ->assertCreated()
            ->assertJsonPath('data.activity_type', 'visit')
            ->assertJsonPath('data.restricted_note', 'Shared confidential family context.')
            ->assertJsonPath('case.status', CareCase::STATUS_IN_PROGRESS)
            ->assertJsonPath('case.next_follow_up_on', $due)
            ->json('data');

        $this->assertDatabaseHas('care_case_activities', [
            'id' => $activity['id'],
            'care_case_id' => $caseId,
            'activity_type' => 'visit',
        ]);

        $model = CareCaseActivity::query()->findOrFail($activity['id']);
        $this->expectException(\App\Services\CareCaseException::class);
        $model->update(['notes' => 'Silent overwrite attempt']);
    }

    // ------------------------------------------------------------------
    // AC2 — escalation without disclosing to unrelated users
    // ------------------------------------------------------------------

    public function test_escalation_assigns_qualified_officer_and_is_audited(): void
    {
        $officer = $this->officer();
        $lead = $this->officer();
        $caseId = $this->openCase($officer, 'esc1');

        // Link lead so non-disclosing notification can be created.
        Member::create([
            'membership_id' => 'CARE2-LEAD',
            'branch_id' => $this->branch->id,
            'user_id' => $lead->id,
            'registration_channel' => 'web',
            'first_name' => 'Lead',
            'last_name' => 'Carer',
            'consent_data_processing' => true,
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
        ]);

        $escalation = $this->actingAsMfaVerified($officer)
            ->postJson("/api/care-cases/{$caseId}/escalate", [
                'trigger_type' => 'safeguarding_concern',
                'to_officer_id' => $lead->id,
                'notes' => 'Needs safeguarding-qualified oversight.',
            ])
            ->assertCreated()
            ->assertJsonPath('case.status', CareCase::STATUS_ESCALATED)
            ->assertJsonPath('case.assigned_officer_id', $lead->id)
            ->json('data');

        $this->assertDatabaseHas('care_case_escalations', [
            'id' => $escalation['id'],
            'trigger_type' => 'safeguarding_concern',
            'to_officer_id' => $lead->id,
        ]);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'care_case.escalated',
            'subject_id' => $caseId,
            'category' => AuditEvent::CATEGORY_SECURITY,
        ]);

        $notification = MemberNotification::query()
            ->where('user_id', $lead->id)
            ->where('type', 'care.case.escalated')
            ->first();

        $this->assertNotNull($notification);
        $this->assertArrayNotHasKey('description', $notification->metadata ?? []);
        $this->assertArrayNotHasKey('beneficiary_name', $notification->metadata ?? []);

        $this->actingAsMfaVerified($lead)
            ->postJson("/api/care-case-escalations/{$escalation['id']}/acknowledge")
            ->assertOk()
            ->assertJsonPath('data.acknowledged_by', $lead->id);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'care_case.escalation_acknowledged',
            'subject_id' => $caseId,
        ]);
    }

    public function test_process_escalations_handles_missed_deadline(): void
    {
        $officer = $this->officer();
        $lead = $this->officer();
        $caseId = $this->openCase($officer, 'esc2', ['assigned_officer_id' => $officer->id]);

        CareCase::query()->whereKey($caseId)->update([
            'next_follow_up_on' => now()->subDays(2)->toDateString(),
        ]);

        $this->actingAsMfaVerified($officer)
            ->postJson('/api/care-cases/process-escalations')
            ->assertOk()
            ->assertJsonPath('data.escalated', 1);

        $this->assertDatabaseHas('care_case_escalations', [
            'care_case_id' => $caseId,
            'trigger_type' => 'missed_deadline',
            'to_officer_id' => $lead->id,
        ]);
        $this->assertSame(CareCase::STATUS_ESCALATED, CareCase::query()->find($caseId)?->status);
        $this->assertSame($lead->id, CareCase::query()->find($caseId)?->assigned_officer_id);
    }

    // ------------------------------------------------------------------
    // AC3 — close and reopen
    // ------------------------------------------------------------------

    public function test_close_records_outcome_and_reopen_requires_permission_and_reason(): void
    {
        $officer = $this->officer();
        $caseId = $this->openCase($officer, 'cls1');

        $this->actingAsMfaVerified($officer)
            ->postJson("/api/care-cases/{$caseId}/close", [
                'closure_reason' => 'resolved',
                'closure_outcome' => 'Member received pastoral support and reported stability.',
                'future_care_plan' => 'Light-touch check-in next month.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', CareCase::STATUS_CLOSED)
            ->assertJsonPath('data.closure_reason', 'resolved')
            ->assertJsonPath('data.future_care_plan', 'Light-touch check-in next month.');

        $this->assertNotNull(CareCase::query()->find($caseId)?->closed_at);

        $reader = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $this->grant($reader, [
            'care.cases.read',
            'care.cases.sensitive.read',
            'care.cases.manage',
            // no reopen
        ]);

        $this->actingAsMfaVerified($reader)
            ->postJson("/api/care-cases/{$caseId}/reopen", [
                'reason' => 'Member asked for more support.',
            ])
            ->assertForbidden();

        $this->actingAsMfaVerified($officer)
            ->postJson("/api/care-cases/{$caseId}/reopen", [
                'reason' => 'Member asked for additional pastoral support after closure.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', CareCase::STATUS_ASSIGNED)
            ->assertJsonPath('data.reopen_reason', 'Member asked for additional pastoral support after closure.');

        $this->assertNull(CareCase::query()->find($caseId)?->closed_at);
    }

    public function test_closed_case_rejects_new_activities_until_reopened(): void
    {
        $officer = $this->officer();
        $caseId = $this->openCase($officer, 'cls2');

        $this->actingAsMfaVerified($officer)
            ->postJson("/api/care-cases/{$caseId}/close", [
                'closure_reason' => 'resolved',
                'closure_outcome' => 'Care complete for this season.',
            ])
            ->assertOk();

        $this->actingAsMfaVerified($officer)
            ->postJson("/api/care-cases/{$caseId}/activities", [
                'activity_type' => 'contact',
                'outcome' => 'reached',
                'notes' => 'Should not be allowed.',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'closed');
    }
}
