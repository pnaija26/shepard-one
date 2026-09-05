<?php

namespace Tests\Feature;

use App\Models\FollowUp;
use App\Models\Member;
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
 * Story 12.3: team-leader web and mobile dashboard.
 */
class TeamLeaderDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'TL-HQ']);
        $this->branch = Organization::create(['name' => 'Branch A', 'type' => 'branch', 'identifier' => 'TL-A', 'parent_id' => $hq->id]);
    }

    private function leader(array $extra = []): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $role = Role::create(['name' => 'team_leader_' . $user->id]);

        foreach (array_merge([
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
        ], $extra) as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }

        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);

        return $user;
    }

    private function createActiveTeam(User $coordinator, User $leader): int
    {
        $coordinatorRole = Role::create(['name' => 'team_coord_' . $coordinator->id]);
        foreach (['teams.read', 'teams.manage'] as $action) {
            RolePermission::create(['role_id' => $coordinatorRole->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $coordinator->id, 'role_id' => $coordinatorRole->id, 'granted_by' => $coordinator->id]);

        $teamId = $this->actingAsMfaVerified($coordinator)
            ->postJson('/api/service-teams', [
                'branch_id' => $this->branch->id,
                'name' => 'Worship Team',
                'category' => 'worship',
                'description' => 'Sunday worship support.',
                'leaders' => [['user_id' => $leader->id, 'role' => 'lead']],
                'required_skills' => ['music'],
                'minimum_staffing' => ['minimum_per_session' => 2, 'maximum_per_session' => 8],
                'schedules' => [['type' => 'weekly', 'label' => 'Sunday', 'required_volunteers' => 2]],
                'objectives' => ['Lead worship.'],
                'attendance_rules' => ['require_check_in' => true, 'methods' => ['manual']],
                'reporting_template' => ['frequency' => 'weekly', 'fields' => ['attendance']],
                'approval_hierarchy' => ['requires_approval' => false, 'levels' => []],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/service-teams/{$teamId}/activate")
            ->assertOk();

        return $teamId;
    }

    private function seedLeaderWorkload(int $teamId, User $leader): Member
    {
        $member = Member::create([
            'membership_id' => 'TL-M-' . uniqid(),
            'branch_id' => $this->branch->id,
            'registration_channel' => 'web',
            'first_name' => 'New',
            'last_name' => 'Singer',
            'consent_data_processing' => true,
            'lifecycle_status' => 'active',
        ]);

        ServiceTeamAssignment::create([
            'service_team_id' => $teamId,
            'member_id' => $member->id,
            'team_role' => 'member',
            'status' => ServiceTeamAssignment::STATUS_ACTIVE,
            'effective_from' => now()->subDays(3)->toDateString(),
            'assigned_by' => $leader->id,
        ]);

        TeamOccurrence::create([
            'service_team_id' => $teamId,
            'branch_id' => $this->branch->id,
            'occurrence_type' => 'rehearsal',
            'title' => 'Missed rehearsal capture',
            'occurrence_date' => now()->subDays(2)->toDateString(),
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
            'status' => TeamReport::STATUS_RETURNED,
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
            'reason' => 'Welcome new singer',
            'assignee_id' => $leader->id,
            'due_date' => now()->subDay()->toDateString(),
            'contact_method' => 'phone',
            'priority' => 'high',
            'status' => FollowUp::STATUS_ASSIGNED,
            'created_by' => $leader->id,
        ]);

        return $member;
    }

    public function test_leader_dashboard_includes_priority_actions_with_text_urgency_labels(): void
    {
        $leader = $this->leader();
        $coordinator = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $teamId = $this->createActiveTeam($coordinator, $leader);
        $this->seedLeaderWorkload($teamId, $leader);

        $data = $this->actingAsMfaVerified($leader)
            ->getJson("/api/service-teams/{$teamId}/dashboard")
            ->assertOk()
            ->json('data');

        $this->assertSame('team_leader', $data['layout']);
        $this->assertNotEmpty($data['version']);
        $this->assertArrayHasKey('availability', $data['widgets']);
        $this->assertArrayHasKey('services', $data['widgets']);
        $this->assertArrayHasKey('follow_ups', $data['widgets']);
        $this->assertArrayHasKey('new_members', $data['widgets']);

        $this->assertNotEmpty($data['priority_actions']);
        $overdue = collect($data['priority_actions'])->firstWhere('type', 'overdue_follow_up');
        $this->assertNotNull($overdue);
        $this->assertSame('Overdue follow-up', $overdue['title']);
        $this->assertStringContainsString('Critical', $overdue['urgency_label']);
        $this->assertArrayHasKey('detail', $overdue);
    }

    public function test_stale_dashboard_sync_returns_recoverable_conflict(): void
    {
        $leader = $this->leader();
        $coordinator = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $teamId = $this->createActiveTeam($coordinator, $leader);
        $this->seedLeaderWorkload($teamId, $leader);

        $version = $this->actingAsMfaVerified($leader)
            ->getJson("/api/service-teams/{$teamId}/dashboard")
            ->json('data.version');

        FollowUp::query()->latest('id')->first()?->update(['status' => FollowUp::STATUS_CLOSED]);

        $this->actingAsMfaVerified($leader)
            ->postJson("/api/service-teams/{$teamId}/dashboard/sync", [
                'expected_version' => $version,
                'action' => 'follow_up_complete',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'dashboard_stale')
            ->assertJsonStructure(['current_version']);

        $refreshed = $this->actingAsMfaVerified($leader)
            ->postJson("/api/service-teams/{$teamId}/dashboard/sync", [
                'expected_version' => '',
            ])
            ->assertOk()
            ->json('data');

        $this->assertNotSame($version, $refreshed['version']);
        $this->assertSame(0, $refreshed['widgets']['tasks']['overdue_tasks']);
    }
}
