<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\MemberNotification;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\TeamRoster;
use App\Models\TeamRosterSlot;
use App\Models\User;
use App\Models\VolunteerProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Story 5.4: Publish Team Rosters.
 */
class TeamRosterTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'IDX-HQ']);
        $this->branch = Organization::create(['name' => 'Branch A', 'type' => 'branch', 'identifier' => 'IDX-A', 'parent_id' => $hq->id]);
    }

    /**
     * @param  string[]  $extraActions
     */
    private function leader(array $extraActions = []): User
    {
        $user = $this->privilegedUser();
        $role = Role::create(['name' => 'roster_lead_' . $user->id]);
        foreach (array_merge([
            'teams.read', 'teams.manage',
            'teams.rosters.read', 'teams.rosters.manage',
        ], $extraActions) as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);

        return $user;
    }

    private function teamLeader(): User
    {
        return User::factory()->create(['roles' => ['member'], 'has_mfa_enrolled' => true, 'branch_id' => $this->branch->id]);
    }

    private function memberRecord(array $overrides = []): Member
    {
        $user = User::factory()->create(['roles' => ['member'], 'has_mfa_enrolled' => false, 'branch_id' => $this->branch->id]);

        return Member::create(array_merge([
            'user_id' => $user->id,
            'membership_id' => 'S54-M-' . $user->id,
            'branch_id' => $this->branch->id,
            'registration_channel' => 'web',
            'first_name' => 'Roster',
            'last_name' => 'Member',
            'consent_data_processing' => true,
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
            'skills' => ['vocals', 'keyboard'],
        ], $overrides));
    }

    private function createActiveTeam(User $coordinator, User $leader): int
    {
        $payload = [
            'branch_id' => $this->branch->id,
            'name' => 'Worship Team ' . uniqid(),
            'category' => 'worship',
            'description' => 'Sunday worship team.',
            'leaders' => [['user_id' => $leader->id, 'role' => 'lead']],
            'required_skills' => ['vocals', 'keyboard'],
            'minimum_staffing' => ['minimum_per_session' => 2, 'maximum_per_session' => 8],
            'schedules' => [['type' => 'weekly', 'label' => 'Sunday service', 'required_volunteers' => 2]],
            'objectives' => ['Lead worship.'],
            'attendance_rules' => ['require_check_in' => true, 'methods' => ['manual']],
            'reporting_template' => ['frequency' => 'weekly', 'fields' => ['attendance']],
            'approval_hierarchy' => ['requires_approval' => false, 'levels' => []],
        ];

        $created = $this->actingAsMfaVerified($coordinator)
            ->postJson('/api/service-teams', $payload)
            ->assertCreated();

        $teamId = $created->json('data.id');

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/service-teams/{$teamId}/activate")
            ->assertOk();

        return $teamId;
    }

    private function rosterPayload(array $overrides = []): array
    {
        return array_merge([
            'roster_type' => 'weekly',
            'title' => 'Sunday worship roster',
            'period_start' => now()->toDateString(),
            'period_end' => now()->addDays(6)->toDateString(),
            'staffing_requirements' => [
                'duties' => [['duty_label' => 'Sunday service', 'required_count' => 2]],
            ],
        ], $overrides);
    }

    private function slotPayload(Member $member, array $overrides = []): array
    {
        return array_merge([
            'member_id' => $member->id,
            'duty_label' => 'Sunday service',
            'shift_label' => 'Morning',
            'shift_date' => now()->addDay()->toDateString(),
            'shift_start' => '08:00',
            'shift_end' => '12:00',
        ], $overrides);
    }

    // ------------------------------------------------------------------
    // AC1 — validate conflicts and publish with approval
    // ------------------------------------------------------------------

    public function test_leader_validates_conflicts_before_publication(): void
    {
        $coordinator = $this->leader();
        $teamLeader = $this->teamLeader();
        $teamId = $this->createActiveTeam($coordinator, $teamLeader);
        $member = $this->memberRecord();

        VolunteerProfile::create([
            'member_id' => $member->id,
            'branch_id' => $this->branch->id,
            'availability' => [
                'weekly' => [],
                'unavailable_periods' => [[
                    'from' => now()->toDateString(),
                    'to' => now()->addDays(14)->toDateString(),
                    'reason' => 'Away',
                ]],
            ],
            'status' => 'active',
        ]);

        $roster = $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/service-teams/{$teamId}/rosters", $this->rosterPayload())
            ->assertCreated();

        $rosterId = $roster->json('data.id');

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/team-rosters/{$rosterId}/slots", $this->slotPayload($member))
            ->assertCreated();

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/team-rosters/{$rosterId}/validate")
            ->assertOk()
            ->assertJsonPath('data.valid', false)
            ->assertJsonPath('data.conflicts.0.reasons.0', 'unavailable')
            ->assertJsonPath('data.staffing.0.reason', 'staffing_shortfall');

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/team-rosters/{$rosterId}/publish")
            ->assertStatus(422)
            ->assertJsonPath('reason', 'roster_conflicts')
            ->assertJsonPath('overridable', true);
    }

    public function test_valid_roster_publishes_without_override(): void
    {
        $coordinator = $this->leader();
        $teamLeader = $this->teamLeader();
        $teamId = $this->createActiveTeam($coordinator, $teamLeader);
        $memberA = $this->memberRecord();
        $memberB = $this->memberRecord(['membership_id' => 'S54-B-' . uniqid(), 'skills' => ['vocals', 'keyboard']]);

        $roster = $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/service-teams/{$teamId}/rosters", $this->rosterPayload())
            ->assertCreated();

        $rosterId = $roster->json('data.id');

        foreach ([$memberA, $memberB] as $member) {
            $this->actingAsMfaVerified($coordinator)
                ->postJson("/api/team-rosters/{$rosterId}/slots", $this->slotPayload($member))
                ->assertCreated();
        }

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/team-rosters/{$rosterId}/validate")
            ->assertOk()
            ->assertJsonPath('data.valid', true);

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/team-rosters/{$rosterId}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', TeamRoster::STATUS_PUBLISHED);

        $this->assertDatabaseHas('member_notifications', ['type' => 'team_roster.published']);
        $this->assertDatabaseHas('audit_events', ['action' => 'team_roster.published']);
    }

    // ------------------------------------------------------------------
    // AC2 — member responses and approved substitutes
    // ------------------------------------------------------------------

    public function test_member_response_notifies_leader_and_updates_status(): void
    {
        $coordinator = $this->leader();
        $teamLeader = $this->teamLeader();
        $teamId = $this->createActiveTeam($coordinator, $teamLeader);
        $member = $this->memberRecord();
        $substitute = $this->memberRecord(['membership_id' => 'S54-SUB-' . uniqid()]);

        Member::query()->where('user_id', $teamLeader->id)->delete();
        Member::create([
            'user_id' => $teamLeader->id,
            'membership_id' => 'S54-LEAD-' . $teamLeader->id,
            'branch_id' => $this->branch->id,
            'registration_channel' => 'web',
            'first_name' => 'Team',
            'last_name' => 'Lead',
            'consent_data_processing' => true,
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
            'skills' => ['vocals', 'keyboard'],
        ]);

        $rosterId = $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/service-teams/{$teamId}/rosters", $this->rosterPayload([
                'staffing_requirements' => ['duties' => [['duty_label' => 'Sunday service', 'required_count' => 1]]],
            ]))
            ->json('data.id');

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/team-rosters/{$rosterId}/slots", $this->slotPayload($member))
            ->assertCreated();

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/team-rosters/{$rosterId}/publish")
            ->assertOk();

        $slot = TeamRosterSlot::query()->where('member_id', $member->id)->firstOrFail();

        Sanctum::actingAs($member->user);
        $this->postJson("/api/me/roster-slots/{$slot->id}/respond", [
            'response' => 'replacement_requested',
            'reason' => 'Family commitment',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', TeamRosterSlot::STATUS_REPLACEMENT_REQUESTED);

        $this->assertDatabaseHas('member_notifications', [
            'user_id' => $teamLeader->id,
            'type' => 'team_roster.member_response',
        ]);

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/roster-slots/{$slot->id}/substitute", [
                'substitute_member_id' => $substitute->id,
                'reason' => 'Approved replacement',
            ])
            ->assertOk()
            ->assertJsonPath('data.member_id', $substitute->id);

        $this->assertDatabaseHas('team_roster_slots', [
            'id' => $slot->id,
            'status' => TeamRosterSlot::STATUS_SUBSTITUTED,
        ]);

        $this->assertDatabaseHas('team_roster_slots', [
            'member_id' => $substitute->id,
            'replaced_slot_id' => $slot->id,
            'status' => TeamRosterSlot::STATUS_PUBLISHED,
        ]);
    }

    public function test_member_can_accept_published_assignment(): void
    {
        $coordinator = $this->leader();
        $teamLeader = $this->teamLeader();
        $teamId = $this->createActiveTeam($coordinator, $teamLeader);
        $member = $this->memberRecord();

        $rosterId = $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/service-teams/{$teamId}/rosters", $this->rosterPayload([
                'staffing_requirements' => ['duties' => [['duty_label' => 'Sunday service', 'required_count' => 1]]],
            ]))
            ->json('data.id');

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/team-rosters/{$rosterId}/slots", $this->slotPayload($member))
            ->assertCreated();

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/team-rosters/{$rosterId}/publish")
            ->assertOk();

        $slot = TeamRosterSlot::query()->where('member_id', $member->id)->firstOrFail();

        Sanctum::actingAs($member->user);
        $this->postJson("/api/me/roster-slots/{$slot->id}/respond", ['response' => 'accepted'])
            ->assertOk()
            ->assertJsonPath('data.status', TeamRosterSlot::STATUS_ACCEPTED);
    }
}
