<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\ServiceTeamAssignment;
use App\Models\TeamRoster;
use App\Models\TeamRosterSlot;
use App\Models\User;
use App\Models\VolunteerProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 5.9: Recommend Suitable Volunteers.
 */
class VolunteerRecommendationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'IDX-HQ']);
        $this->branch = Organization::create(['name' => 'Branch A', 'type' => 'branch', 'identifier' => 'IDX-A', 'parent_id' => $hq->id]);
    }

    private function coordinator(): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $role = Role::create(['name' => 'vol_rec_' . $user->id]);
        foreach ([
            'teams.read', 'teams.manage', 'teams.assignments.manage', 'teams.assignments.override',
            'volunteers.read', 'volunteers.manage', 'volunteers.recommend',
        ] as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);

        return $user;
    }

    private function createActiveTeam(User $coordinator): int
    {
        $leader = User::factory()->create(['roles' => ['member'], 'has_mfa_enrolled' => true, 'branch_id' => $this->branch->id]);

        $payload = [
            'branch_id' => $this->branch->id,
            'name' => 'Worship Team ' . uniqid(),
            'category' => 'worship',
            'description' => 'Sunday worship team.',
            'leaders' => [['user_id' => $leader->id, 'role' => 'lead']],
            'required_skills' => ['vocals', 'keyboard'],
            'minimum_staffing' => ['minimum_per_session' => 1, 'maximum_per_session' => 8],
            'schedules' => [['type' => 'weekly', 'label' => 'Sunday service', 'required_volunteers' => 2]],
            'objectives' => ['Lead worship.'],
            'attendance_rules' => ['require_check_in' => true, 'methods' => ['manual']],
            'reporting_template' => ['frequency' => 'weekly', 'fields' => ['attendance']],
            'approval_hierarchy' => ['requires_approval' => false, 'levels' => []],
        ];

        $teamId = $this->actingAsMfaVerified($coordinator)
            ->postJson('/api/service-teams', $payload)
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/service-teams/{$teamId}/activate")
            ->assertOk();

        return $teamId;
    }

    private function volunteerMember(string $suffix, array $skills, array $availability = [], array $training = []): Member
    {
        $member = Member::create([
            'user_id' => null,
            'membership_id' => 'S59-' . $suffix,
            'branch_id' => $this->branch->id,
            'registration_channel' => 'web',
            'first_name' => 'Volunteer',
            'last_name' => $suffix,
            'consent_data_processing' => true,
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
            'skills' => $skills,
        ]);

        VolunteerProfile::create([
            'member_id' => $member->id,
            'branch_id' => $this->branch->id,
            'skills' => $skills,
            'expertise' => [['area' => 'worship', 'level' => 'advanced', 'years' => 3]],
            'availability' => $availability !== [] ? $availability : [
                'weekly' => [['day' => 'sunday', 'start' => '08:00', 'end' => '12:00']],
                'unavailable_periods' => [],
            ],
            'preferences' => [],
            'experience' => [],
            'certifications' => [],
            'training' => $training,
            'service_history' => [],
            'volunteer_hours' => 12,
            'restricted_notes' => 'Sensitive pastoral note for coordinator only.',
            'status' => VolunteerProfile::STATUS_ACTIVE,
        ]);

        return $member;
    }

    private function dutyPayload(): array
    {
        return [
            'duty_label' => 'Sunday vocals',
            'shift_label' => 'Sunday AM',
            'shift_date' => now()->next('Sunday')->toDateString(),
            'shift_start' => '09:00',
            'shift_end' => '11:00',
            'day_of_week' => 'sunday',
            'required_skills' => ['vocals'],
            'required_training' => ['safeguarding'],
        ];
    }

    // ------------------------------------------------------------------
    // AC1 — ranked recommendations without restricted profile data
    // ------------------------------------------------------------------

    public function test_coordinator_receives_ranked_recommendations_with_reasons(): void
    {
        $coordinator = $this->coordinator();
        $teamId = $this->createActiveTeam($coordinator);

        $strong = $this->volunteerMember('Strong', ['vocals', 'keyboard'], training: [[
            'name' => 'safeguarding',
            'verification_status' => 'verified',
        ]]);
        $weak = $this->volunteerMember('Weak', ['drums'], availability: [
            'weekly' => [['day' => 'saturday', 'start' => '09:00', 'end' => '11:00']],
            'unavailable_periods' => [],
        ]);

        $response = $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/service-teams/{$teamId}/volunteer-recommendations", $this->dutyPayload())
            ->assertOk();

        $recommendations = $response->json('data.recommendations');
        $this->assertGreaterThanOrEqual(2, count($recommendations));
        $this->assertSame($strong->id, $recommendations[0]['member_id']);
        $this->assertTrue($recommendations[0]['eligible']);
        $this->assertStringContainsString('Matched skills', $recommendations[0]['reasons'][0]);
        $this->assertArrayNotHasKey('restricted_notes', $recommendations[0]);
        $this->assertFalse($recommendations[1]['eligible']);
        $this->assertNotEmpty($recommendations[1]['limitations']);
    }

    // ------------------------------------------------------------------
    // AC2 — limitations, blocked assignment, override confirmation
    // ------------------------------------------------------------------

    public function test_unavailable_volunteer_shows_limitation_and_blocks_confirmation(): void
    {
        $coordinator = $this->coordinator();
        $teamId = $this->createActiveTeam($coordinator);

        $member = $this->volunteerMember('Away', ['vocals'], availability: [
            'weekly' => [['day' => 'sunday', 'start' => '08:00', 'end' => '12:00']],
            'unavailable_periods' => [[
                'from' => now()->toDateString(),
                'to' => now()->addMonth()->toDateString(),
                'reason' => 'Travel',
            ]],
        ], training: [['name' => 'safeguarding', 'verification_status' => 'verified']]);

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/service-teams/{$teamId}/volunteer-recommendations", $this->dutyPayload())
            ->assertOk()
            ->assertJsonPath('data.recommendations.0.eligible', false);

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/service-teams/{$teamId}/volunteer-recommendations/confirm", array_merge($this->dutyPayload(), [
                'member_id' => $member->id,
                'team_role' => 'member',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['member_id']);
    }

    public function test_scheduling_conflict_requires_override_before_assignment(): void
    {
        $coordinator = $this->coordinator();
        $teamId = $this->createActiveTeam($coordinator);

        $member = $this->volunteerMember('Busy', ['vocals'], training: [[
            'name' => 'safeguarding',
            'verification_status' => 'verified',
        ]]);

        $roster = TeamRoster::create([
            'service_team_id' => $teamId,
            'branch_id' => $this->branch->id,
            'roster_type' => 'weekly',
            'title' => 'Sunday roster',
            'status' => TeamRoster::STATUS_PUBLISHED,
            'period_start' => now()->toDateString(),
            'period_end' => now()->addWeek()->toDateString(),
            'staffing_requirements' => [],
            'created_by' => $coordinator->id,
            'updated_by' => $coordinator->id,
            'published_at' => now(),
            'published_by' => $coordinator->id,
        ]);

        TeamRosterSlot::create([
            'team_roster_id' => $roster->id,
            'member_id' => $member->id,
            'duty_label' => 'Media',
            'shift_label' => 'Sunday AM',
            'shift_date' => now()->next('Sunday')->toDateString(),
            'status' => TeamRosterSlot::STATUS_PUBLISHED,
            'created_by' => $coordinator->id,
        ]);

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/service-teams/{$teamId}/volunteer-recommendations", $this->dutyPayload())
            ->assertOk()
            ->assertJsonPath('data.recommendations.0.match.roster_conflict', true);

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/service-teams/{$teamId}/volunteer-recommendations/confirm", array_merge($this->dutyPayload(), [
                'member_id' => $member->id,
                'team_role' => 'member',
            ]))
            ->assertStatus(422);
    }

    public function test_coordinator_confirms_eligible_recommendation_assignment(): void
    {
        $coordinator = $this->coordinator();
        $teamId = $this->createActiveTeam($coordinator);

        $member = $this->volunteerMember('Ready', ['vocals', 'keyboard'], training: [[
            'name' => 'safeguarding',
            'verification_status' => 'verified',
        ]]);

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/service-teams/{$teamId}/volunteer-recommendations/confirm", array_merge($this->dutyPayload(), [
                'member_id' => $member->id,
                'team_role' => 'member',
            ]))
            ->assertCreated()
            ->assertJsonPath('data.member_id', $member->id);

        $this->assertDatabaseHas('service_team_assignments', [
            'service_team_id' => $teamId,
            'member_id' => $member->id,
            'status' => ServiceTeamAssignment::STATUS_SCHEDULED,
        ]);
    }
}
