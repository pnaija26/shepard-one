<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Member;
use App\Models\MemberNotification;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\ServiceTeam;
use App\Models\ServiceTeamConfigVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 5.1: Create and Configure a Service Team.
 */
class ServiceTeamTest extends TestCase
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

    private function coordinator(?int $branchId = null): User
    {
        $user = $this->privilegedUser(['branch_id' => $branchId]);
        $role = Role::create(['name' => 'team_coord_' . $user->id]);
        foreach (['teams.read', 'teams.manage'] as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);

        return $user;
    }

    private function leaderUser(): User
    {
        $user = User::factory()->create(['roles' => ['member'], 'has_mfa_enrolled' => true, 'branch_id' => $this->branch->id]);

        Member::create([
            'user_id' => $user->id,
            'membership_id' => 'S1-M-LEAD-' . $user->id,
            'branch_id' => $this->branch->id,
            'registration_channel' => 'web',
            'first_name' => 'Team',
            'last_name' => 'Lead',
            'consent_data_processing' => true,
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
        ]);

        return $user;
    }

    private function teamPayload(User $leader, array $overrides = []): array
    {
        return array_merge([
            'branch_id' => $this->branch->id,
            'name' => 'Worship Team',
            'category' => 'worship',
            'description' => 'Leads Sunday worship and midweek rehearsals.',
            'leaders' => [['user_id' => $leader->id, 'role' => 'lead']],
            'required_skills' => ['vocals', 'keyboard'],
            'minimum_staffing' => ['minimum_per_session' => 4, 'maximum_per_session' => 8],
            'schedules' => [[
                'type' => 'weekly',
                'label' => 'Sunday service',
                'required_volunteers' => 6,
            ]],
            'objectives' => ['Lead congregational worship with excellence.'],
            'attendance_rules' => [
                'require_check_in' => true,
                'methods' => ['manual', 'qr'],
            ],
            'reporting_template' => [
                'frequency' => 'weekly',
                'fields' => ['attendance', 'issues', 'prayer_requests'],
            ],
            'approval_hierarchy' => [
                'requires_approval' => true,
                'levels' => [['user_id' => $leader->id, 'role' => 'department_head']],
            ],
        ], $overrides);
    }

    // ------------------------------------------------------------------
    // AC1 — create team with operating rules in scope
    // ------------------------------------------------------------------

    public function test_coordinator_creates_service_team_with_operating_rules(): void
    {
        $coordinator = $this->coordinator();
        $leader = $this->leaderUser();

        $this->actingAsMfaVerified($coordinator)
            ->postJson('/api/service-teams', $this->teamPayload($leader))
            ->assertCreated()
            ->assertJsonPath('data.name', 'Worship Team')
            ->assertJsonPath('data.status', ServiceTeam::STATUS_DRAFT)
            ->assertJsonPath('data.current_config_version', 1);

        $this->assertDatabaseHas('service_teams', [
            'branch_id' => $this->branch->id,
            'name' => 'Worship Team',
            'category' => 'worship',
        ]);

        $this->assertDatabaseHas('service_team_config_versions', [
            'version' => 1,
        ]);

        $this->assertDatabaseHas('audit_events', ['action' => 'service_team.created']);
    }

    public function test_duplicate_name_and_contradictory_configuration_are_rejected(): void
    {
        $coordinator = $this->coordinator();
        $leader = $this->leaderUser();
        $payload = $this->teamPayload($leader);

        $this->actingAsMfaVerified($coordinator)
            ->postJson('/api/service-teams', $payload)
            ->assertCreated();

        $this->actingAsMfaVerified($coordinator)
            ->postJson('/api/service-teams', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        $this->actingAsMfaVerified($coordinator)
            ->postJson('/api/service-teams', $this->teamPayload($leader, [
                'name' => 'Media Team',
                'minimum_staffing' => ['minimum_per_session' => 10, 'maximum_per_session' => 5],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['minimum_staffing.minimum_per_session']);
    }

    public function test_cross_scope_department_is_rejected(): void
    {
        $coordinator = $this->coordinator();
        $leader = $this->leaderUser();
        $otherDepartment = Organization::create([
            'name' => 'Other Dept',
            'type' => 'department',
            'identifier' => 'IDX-OD',
            'parent_id' => $this->otherBranch->id,
        ]);

        $this->actingAsMfaVerified($coordinator)
            ->postJson('/api/service-teams', $this->teamPayload($leader, [
                'department_id' => $otherDepartment->id,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['department_id']);
    }

    // ------------------------------------------------------------------
    // AC2 — reconfigure with version history and leader notification
    // ------------------------------------------------------------------

    public function test_active_team_reconfiguration_versions_history_and_notifies_leaders(): void
    {
        $coordinator = $this->coordinator();
        $leader = $this->leaderUser();
        $payload = $this->teamPayload($leader);

        $created = $this->actingAsMfaVerified($coordinator)
            ->postJson('/api/service-teams', $payload)
            ->assertCreated();

        $teamId = $created->json('data.id');

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/service-teams/{$teamId}/activate")
            ->assertOk()
            ->assertJsonPath('data.status', ServiceTeam::STATUS_ACTIVE);

        $updatedPayload = array_merge($payload, [
            'minimum_staffing' => ['minimum_per_session' => 5, 'maximum_per_session' => 10],
            'schedules' => [[
                'type' => 'weekly',
                'label' => 'Sunday service',
                'required_volunteers' => 7,
            ]],
        ]);

        $this->actingAsMfaVerified($coordinator)
            ->putJson("/api/service-teams/{$teamId}", $updatedPayload)
            ->assertOk()
            ->assertJsonPath('data.current_config_version', 2);

        $this->assertDatabaseCount('service_team_config_versions', 2);
        $this->assertDatabaseHas('service_team_changes', [
            'service_team_id' => $teamId,
            'change_type' => 'updated',
            'config_version' => 2,
        ]);

        $this->assertDatabaseHas('member_notifications', [
            'user_id' => $leader->id,
            'type' => 'service_team.config_changed',
        ]);
    }

    public function test_archived_team_preserves_history_and_blocks_future_edits(): void
    {
        $coordinator = $this->coordinator();
        $leader = $this->leaderUser();
        $payload = $this->teamPayload($leader);

        $created = $this->actingAsMfaVerified($coordinator)
            ->postJson('/api/service-teams', $payload)
            ->assertCreated();

        $teamId = $created->json('data.id');

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/service-teams/{$teamId}/activate")
            ->assertOk();

        $versionCount = ServiceTeamConfigVersion::query()->where('service_team_id', $teamId)->count();

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/service-teams/{$teamId}/archive", ['reason' => 'Season ended'])
            ->assertOk()
            ->assertJsonPath('data.status', ServiceTeam::STATUS_ARCHIVED);

        $this->assertSame($versionCount, ServiceTeamConfigVersion::query()->where('service_team_id', $teamId)->count());

        $this->actingAsMfaVerified($coordinator)
            ->putJson("/api/service-teams/{$teamId}", $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['team']);

        $this->assertDatabaseHas('audit_events', ['action' => 'service_team.archived']);
    }
}
