<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\ServiceTeam;
use App\Models\ServiceTeamAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 5.2: Assign Members to Teams and Duties.
 */
class ServiceTeamAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;

    private Organization $otherBranch;

    protected function setUp(): void
    {
        parent::setUp();

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'IDX-HQ']);
        $this->branch = Organization::create(['name' => 'Branch A', 'type' => 'branch', 'identifier' => 'IDX-A', 'parent_id' => $hq->id]);
        $this->otherBranch = Organization::create(['name' => 'Branch B', 'type' => 'branch', 'identifier' => 'IDX-B', 'parent_id' => $hq->id]);
    }

    /**
     * @param  string[]  $extraActions
     */
    private function coordinator(array $extraActions = []): User
    {
        $user = $this->privilegedUser();
        $role = Role::create(['name' => 'team_assign_' . $user->id]);
        foreach (array_merge(['teams.read', 'teams.manage', 'teams.assignments.read', 'teams.assignments.manage'], $extraActions) as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);

        return $user;
    }

    private function leaderUser(): User
    {
        return User::factory()->create(['roles' => ['member'], 'has_mfa_enrolled' => true, 'branch_id' => $this->branch->id]);
    }

    private function memberRecord(array $overrides = []): Member
    {
        $user = User::factory()->create(['roles' => ['member'], 'has_mfa_enrolled' => true, 'branch_id' => $this->branch->id]);

        return Member::create(array_merge([
            'user_id' => $user->id,
            'membership_id' => 'S52-M-' . $user->id,
            'branch_id' => $this->branch->id,
            'registration_channel' => 'web',
            'first_name' => 'Serve',
            'last_name' => 'Member',
            'consent_data_processing' => true,
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
            'skills' => ['vocals', 'keyboard'],
        ], $overrides));
    }

    private function createActiveTeam(User $coordinator, User $leader, array $overrides = []): int
    {
        $payload = array_merge([
            'branch_id' => $this->branch->id,
            'name' => 'Worship Team ' . uniqid(),
            'category' => 'worship',
            'description' => 'Sunday worship team.',
            'leaders' => [['user_id' => $leader->id, 'role' => 'lead']],
            'required_skills' => ['vocals', 'keyboard'],
            'minimum_staffing' => ['minimum_per_session' => 2, 'maximum_per_session' => 8],
            'schedules' => [['type' => 'weekly', 'label' => 'Sunday service', 'required_volunteers' => 4]],
            'objectives' => ['Lead worship.'],
            'attendance_rules' => ['require_check_in' => true, 'methods' => ['manual']],
            'reporting_template' => ['frequency' => 'weekly', 'fields' => ['attendance']],
            'approval_hierarchy' => ['requires_approval' => false, 'levels' => []],
        ], $overrides);

        $created = $this->actingAsMfaVerified($coordinator)
            ->postJson('/api/service-teams', $payload)
            ->assertCreated();

        $teamId = $created->json('data.id');

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/service-teams/{$teamId}/activate")
            ->assertOk();

        return $teamId;
    }

    private function assignmentPayload(Member $member, array $overrides = []): array
    {
        return array_merge([
            'member_id' => $member->id,
            'team_role' => 'member',
            'sub_team' => 'vocals',
            'shift_label' => 'Sunday morning',
            'responsibilities' => ['lead vocals'],
            'effective_from' => now()->toDateString(),
        ], $overrides);
    }

    // ------------------------------------------------------------------
    // AC1 — assignments, transfers, removals with policy enforcement
    // ------------------------------------------------------------------

    public function test_administrator_assigns_member_with_role_shift_and_effective_date(): void
    {
        $coordinator = $this->coordinator();
        $leader = $this->leaderUser();
        $teamId = $this->createActiveTeam($coordinator, $leader);
        $member = $this->memberRecord();

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/service-teams/{$teamId}/assignments", $this->assignmentPayload($member))
            ->assertCreated()
            ->assertJsonPath('data.status', ServiceTeamAssignment::STATUS_ACTIVE)
            ->assertJsonPath('data.team_role', 'member')
            ->assertJsonPath('data.shift_label', 'Sunday morning');

        $this->assertDatabaseHas('service_team_assignments', [
            'service_team_id' => $teamId,
            'member_id' => $member->id,
            'status' => ServiceTeamAssignment::STATUS_ACTIVE,
        ]);

        $this->assertDatabaseHas('audit_events', ['action' => 'team_assignment.created']);
    }

    public function test_bulk_assignments_transfer_and_removal_workflow(): void
    {
        $coordinator = $this->coordinator();
        $leader = $this->leaderUser();
        $teamId = $this->createActiveTeam($coordinator, $leader);
        $targetTeamId = $this->createActiveTeam($coordinator, $leader, ['name' => 'Media Team ' . uniqid()]);
        $memberA = $this->memberRecord();
        $memberB = $this->memberRecord(['membership_id' => 'S52-M-B-' . uniqid()]);

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/service-teams/{$teamId}/assignments/bulk", [
                'entries' => [
                    $this->assignmentPayload($memberA, ['shift_label' => 'Shift A']),
                    $this->assignmentPayload($memberB, ['shift_label' => 'Shift B']),
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.created', 2);

        $assignment = ServiceTeamAssignment::query()
            ->where('service_team_id', $teamId)
            ->where('member_id', $memberA->id)
            ->firstOrFail();

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/team-assignments/{$assignment->id}/transfer", [
                'service_team_id' => $targetTeamId,
                'team_role' => 'member',
                'shift_label' => 'Shift A',
                'effective_from' => now()->toDateString(),
            ])
            ->assertOk()
            ->assertJsonPath('data.service_team_id', $targetTeamId);

        $this->assertDatabaseHas('service_team_assignments', [
            'id' => $assignment->id,
            'status' => ServiceTeamAssignment::STATUS_TRANSFERRED,
        ]);

        $transferred = ServiceTeamAssignment::query()
            ->where('service_team_id', $targetTeamId)
            ->where('member_id', $memberA->id)
            ->firstOrFail();

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/team-assignments/{$transferred->id}/remove", ['reason' => 'Season ended'])
            ->assertOk()
            ->assertJsonPath('data.status', ServiceTeamAssignment::STATUS_REMOVED);
    }

    public function test_pending_assignment_requires_approval_before_becoming_active(): void
    {
        $coordinator = $this->coordinator();
        $leader = $this->leaderUser();
        $teamId = $this->createActiveTeam($coordinator, $leader, [
            'approval_hierarchy' => [
                'requires_approval' => true,
                'levels' => [['user_id' => $leader->id, 'role' => 'team_lead']],
            ],
        ]);
        $member = $this->memberRecord();

        $created = $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/service-teams/{$teamId}/assignments", $this->assignmentPayload($member))
            ->assertCreated()
            ->assertJsonPath('data.status', ServiceTeamAssignment::STATUS_PENDING);

        $assignmentId = $created->json('data.id');

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/team-assignments/{$assignmentId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', ServiceTeamAssignment::STATUS_ACTIVE);

        $this->assertDatabaseHas('audit_events', ['action' => 'team_assignment.approved']);
    }

    // ------------------------------------------------------------------
    // AC2 — conflicts blocked or overridden with audit
    // ------------------------------------------------------------------

    public function test_branch_and_skill_conflicts_are_blocked(): void
    {
        $coordinator = $this->coordinator();
        $leader = $this->leaderUser();
        $teamId = $this->createActiveTeam($coordinator, $leader);

        $otherBranchMember = $this->memberRecord([
            'branch_id' => $this->otherBranch->id,
            'membership_id' => 'S52-OTHER-' . uniqid(),
        ]);

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/service-teams/{$teamId}/assignments", $this->assignmentPayload($otherBranchMember))
            ->assertStatus(422)
            ->assertJsonPath('reason', 'branch_mismatch')
            ->assertJsonPath('overridable', true);

        $unskilledMember = $this->memberRecord([
            'membership_id' => 'S52-UNSKILLED-' . uniqid(),
            'skills' => ['ushering'],
        ]);

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/service-teams/{$teamId}/assignments", $this->assignmentPayload($unskilledMember))
            ->assertStatus(422)
            ->assertJsonPath('reason', 'missing_skills');
    }

    public function test_authorized_override_records_reason_and_audit(): void
    {
        $coordinator = $this->coordinator(['teams.assignments.override']);
        $leader = $this->leaderUser();
        $teamId = $this->createActiveTeam($coordinator, $leader);
        $member = $this->memberRecord([
            'membership_id' => 'S52-OVERRIDE-' . uniqid(),
            'skills' => ['ushering'],
        ]);

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/service-teams/{$teamId}/assignments", $this->assignmentPayload($member, [
                'override' => true,
                'override_reason' => 'Experienced volunteer completing keyboard training next week.',
            ]))
            ->assertCreated()
            ->assertJsonPath('data.override_applied', true)
            ->assertJsonPath('data.status', ServiceTeamAssignment::STATUS_ACTIVE);

        $this->assertDatabaseHas('service_team_assignment_events', [
            'event_type' => 'override',
        ]);

        $this->assertDatabaseHas('audit_events', ['action' => 'team_assignment.override']);
    }
}
