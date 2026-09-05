<?php

namespace Tests\Feature;

use App\Models\FollowUp;
use App\Models\Member;
use App\Models\MemberNotification;
use App\Models\OperationalIncident;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\ServiceTeamAssignment;
use App\Models\TeamOccurrence;
use App\Models\TeamReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 5.8: Use a Team Operations Dashboard.
 */
class TeamOperationsDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'IDX-HQ']);
        $this->branch = Organization::create(['name' => 'Branch A', 'type' => 'branch', 'identifier' => 'IDX-A', 'parent_id' => $hq->id]);
    }

    private function leader(array $extraPermissions = []): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $role = Role::create(['name' => 'team_dashboard_lead_' . $user->id]);

        $permissions = array_merge([
            'teams.dashboard.read',
            'teams.assignments.read',
            'teams.attendance.read',
            'teams.rosters.read',
            'teams.reports.read',
            'teams.reports.submit',
            'followups.read',
            'volunteers.read',
            'events.read',
            'incidents.read',
        ], $extraPermissions);

        foreach ($permissions as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }

        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);

        return $user;
    }

    private function outsider(): User
    {
        $branchB = Organization::create(['name' => 'Branch B', 'type' => 'branch', 'identifier' => 'IDX-B', 'parent_id' => Organization::firstWhere('type', 'headquarters')->id]);

        return $this->privilegedUser(['branch_id' => $branchB->id]);
    }

    private function createActiveTeam(User $coordinator, User $leader): int
    {
        $payload = [
            'branch_id' => $this->branch->id,
            'name' => 'Ops Team ' . uniqid(),
            'category' => 'operations',
            'description' => 'Operations support team.',
            'leaders' => [['user_id' => $leader->id, 'role' => 'lead']],
            'required_skills' => ['coordination'],
            'minimum_staffing' => ['minimum_per_session' => 2, 'maximum_per_session' => 6],
            'schedules' => [['type' => 'weekly', 'label' => 'Sunday service', 'required_volunteers' => 2]],
            'objectives' => ['Support services.'],
            'attendance_rules' => ['require_check_in' => true, 'methods' => ['manual']],
            'reporting_template' => ['frequency' => 'weekly', 'fields' => ['attendance']],
            'approval_hierarchy' => ['requires_approval' => false, 'levels' => []],
        ];

        $coordinatorRole = Role::create(['name' => 'team_builder_' . $coordinator->id]);
        foreach (['teams.read', 'teams.manage'] as $action) {
            RolePermission::create(['role_id' => $coordinatorRole->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $coordinator->id, 'role_id' => $coordinatorRole->id, 'granted_by' => $coordinator->id]);

        $teamId = $this->actingAsMfaVerified($coordinator)
            ->postJson('/api/service-teams', $payload)
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/service-teams/{$teamId}/activate")
            ->assertOk();

        return $teamId;
    }

    private function seedDashboardData(int $teamId, User $leader): Member
    {
        $member = Member::create([
            'user_id' => null,
            'membership_id' => 'S58-MEM-' . uniqid(),
            'branch_id' => $this->branch->id,
            'registration_channel' => 'web',
            'first_name' => 'Dash',
            'last_name' => 'Member',
            'consent_data_processing' => true,
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
        ]);

        ServiceTeamAssignment::create([
            'service_team_id' => $teamId,
            'member_id' => $member->id,
            'team_role' => 'member',
            'status' => ServiceTeamAssignment::STATUS_ACTIVE,
            'effective_from' => now()->toDateString(),
            'assigned_by' => $leader->id,
        ]);

        ServiceTeamAssignment::create([
            'service_team_id' => $teamId,
            'member_id' => $member->id,
            'team_role' => 'member',
            'status' => ServiceTeamAssignment::STATUS_PENDING,
            'effective_from' => now()->addWeek()->toDateString(),
            'assigned_by' => $leader->id,
        ]);

        TeamOccurrence::create([
            'service_team_id' => $teamId,
            'branch_id' => $this->branch->id,
            'occurrence_type' => 'rehearsal',
            'title' => 'Past uncaptured rehearsal',
            'occurrence_date' => now()->subDays(3)->toDateString(),
            'status' => TeamOccurrence::STATUS_SCHEDULED,
            'created_by' => $leader->id,
            'updated_by' => $leader->id,
        ]);

        TeamReport::create([
            'service_team_id' => $teamId,
            'branch_id' => $this->branch->id,
            'reporting_period_start' => now()->subWeek()->toDateString(),
            'reporting_period_end' => now()->subDay()->toDateString(),
            'template_version' => 1,
            'template_snapshot' => ['fields' => ['attendance']],
            'status' => TeamReport::STATUS_DRAFT,
            'version' => 1,
            'field_values' => [],
            'attachments' => [],
            'incidents' => [],
            'results' => [],
            'recommendations' => [],
            'is_locked' => false,
            'created_by' => $leader->id,
            'updated_by' => $leader->id,
        ]);

        FollowUp::create([
            'person_type' => Member::class,
            'person_id' => $member->id,
            'branch_id' => $this->branch->id,
            'reason' => 'Welcome new team member',
            'assignee_id' => $leader->id,
            'due_date' => now()->subDay()->toDateString(),
            'contact_method' => 'phone',
            'priority' => 'normal',
            'status' => FollowUp::STATUS_ASSIGNED,
            'created_by' => $leader->id,
        ]);

        OperationalIncident::create([
            'reference' => 'INC-S58-' . uniqid(),
            'branch_id' => $this->branch->id,
            'classification' => 'equipment',
            'priority' => 'medium',
            'status' => OperationalIncident::STATUS_OPEN,
            'occurred_at' => now()->subDay(),
            'location' => 'Main hall',
            'description' => 'Microphone failure during rehearsal.',
            'assigned_team' => 'operations',
            'owner_id' => $leader->id,
            'reported_by' => $leader->id,
        ]);

        Member::create([
            'user_id' => $leader->id,
            'membership_id' => 'S58-LEAD-' . $leader->id,
            'branch_id' => $this->branch->id,
            'registration_channel' => 'web',
            'first_name' => 'Team',
            'last_name' => 'Leader',
            'consent_data_processing' => true,
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
        ]);

        MemberNotification::create([
            'member_id' => Member::where('user_id', $leader->id)->value('id'),
            'user_id' => $leader->id,
            'type' => 'team_report.submitted',
            'message' => 'A team report needs review.',
            'metadata' => ['service_team_id' => $teamId],
        ]);

        return $member;
    }

    // ------------------------------------------------------------------
    // AC1 — scoped widgets with accessible states
    // ------------------------------------------------------------------

    public function test_leader_sees_permission_scoped_dashboard_widgets(): void
    {
        $leader = $this->leader();
        $coordinator = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $teamId = $this->createActiveTeam($coordinator, $leader);
        $this->seedDashboardData($teamId, $leader);

        $this->actingAsMfaVerified($leader)
            ->getJson('/api/me/team-dashboard/teams')
            ->assertOk()
            ->assertJsonPath('data.0.id', $teamId);

        $response = $this->actingAsMfaVerified($leader)
            ->getJson("/api/service-teams/{$teamId}/dashboard")
            ->assertOk();

        $widgets = $response->json('data.widgets');

        $this->assertSame('ready', $widgets['membership']['state']);
        $this->assertSame(1, $widgets['membership']['active_members']);
        $this->assertSame(1, $widgets['membership']['pending_assignments']);

        $this->assertSame('ready', $widgets['attendance']['state']);
        $this->assertSame(1, $widgets['attendance']['uncaptured_past_occurrences']);

        $this->assertSame('ready', $widgets['reports']['state']);
        $this->assertSame(1, $widgets['reports']['draft_reports']);

        $this->assertSame('ready', $widgets['tasks']['state']);
        $this->assertSame(1, $widgets['tasks']['overdue_tasks']);

        $this->assertSame('ready', $widgets['issues']['state']);
        $this->assertSame(1, $widgets['issues']['open_issues']);

        $this->assertSame('ready', $widgets['notifications']['state']);
        $this->assertSame(1, $widgets['notifications']['unread_notifications']);
    }

    public function test_widgets_expose_empty_or_unauthorized_states(): void
    {
        $leader = $this->leader();
        $coordinator = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $teamId = $this->createActiveTeam($coordinator, $leader);

        $widgets = $this->actingAsMfaVerified($leader)
            ->getJson("/api/service-teams/{$teamId}/dashboard")
            ->assertOk()
            ->json('data.widgets');

        $this->assertSame('empty', $widgets['membership']['state']);
        $this->assertSame('empty', $widgets['reports']['state']);
        $this->assertSame('empty', $widgets['attendance']['state']);
    }

    // ------------------------------------------------------------------
    // AC2 — drill-down and team isolation
    // ------------------------------------------------------------------

    public function test_drill_down_returns_filtered_records_and_next_actions(): void
    {
        $leader = $this->leader();
        $coordinator = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $teamId = $this->createActiveTeam($coordinator, $leader);
        $this->seedDashboardData($teamId, $leader);

        $this->actingAsMfaVerified($leader)
            ->getJson("/api/service-teams/{$teamId}/dashboard/drill-down/reports?" . http_build_query(['status' => 'draft']))
            ->assertOk()
            ->assertJsonPath('data.widget', 'reports')
            ->assertJsonPath('data.team_id', $teamId)
            ->assertJsonCount(1, 'data.records')
            ->assertJsonPath('data.records.0.status', TeamReport::STATUS_DRAFT)
            ->assertJsonFragment(['action' => 'submit_report']);

        $this->actingAsMfaVerified($leader)
            ->getJson("/api/service-teams/{$teamId}/dashboard/drill-down/tasks?" . http_build_query(['scope' => 'overdue']))
            ->assertOk()
            ->assertJsonCount(1, 'data.records')
            ->assertJsonPath('data.records.0.status', FollowUp::STATUS_ASSIGNED);
    }

    public function test_outsider_cannot_access_another_teams_dashboard(): void
    {
        $leader = $this->leader();
        $coordinator = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $teamId = $this->createActiveTeam($coordinator, $leader);

        $outsider = $this->outsider();
        $outsiderRole = Role::create(['name' => 'outsider_dash_' . $outsider->id]);
        RolePermission::create(['role_id' => $outsiderRole->id, 'scope_type' => 'global', 'action' => 'teams.dashboard.read']);
        RoleAssignment::create(['user_id' => $outsider->id, 'role_id' => $outsiderRole->id, 'granted_by' => $outsider->id]);

        $this->actingAsMfaVerified($outsider)
            ->getJson("/api/service-teams/{$teamId}/dashboard")
            ->assertForbidden();

        $this->actingAsMfaVerified($outsider)
            ->getJson("/api/service-teams/{$teamId}/dashboard/drill-down/reports")
            ->assertForbidden();
    }
}
