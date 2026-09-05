<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\VolunteerProfile;
use App\Models\VolunteerProfileChange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Story 5.3: Maintain Volunteer Profiles.
 */
class VolunteerProfileTest extends TestCase
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
     * @param  string[]  $actions
     */
    private function coordinator(array $actions = ['volunteers.read', 'volunteers.manage']): User
    {
        $user = $this->privilegedUser();
        $role = Role::create(['name' => 'vol_coord_' . $user->id]);
        foreach ($actions as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);

        return $user;
    }

    private function volunteerMember(array $memberAttrs = []): Member
    {
        $user = User::factory()->create(['roles' => ['member'], 'has_mfa_enrolled' => false, 'branch_id' => $this->branch->id]);

        return Member::create(array_merge([
            'user_id' => $user->id,
            'membership_id' => 'S53-M-' . $user->id,
            'branch_id' => $this->branch->id,
            'registration_channel' => 'web',
            'first_name' => 'Val',
            'last_name' => 'Volunteer',
            'consent_data_processing' => true,
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
            'skills' => ['vocals'],
        ], $memberAttrs));
    }

    private function profilePayload(Member $member, array $overrides = []): array
    {
        return array_merge([
            'member_id' => $member->id,
            'skills' => ['vocals', 'keyboard'],
            'expertise' => [['area' => 'worship', 'level' => 'advanced', 'years' => 4]],
            'availability' => [
                'weekly' => [['day' => 'sunday', 'start' => '08:00', 'end' => '12:00']],
                'unavailable_periods' => [[
                    'from' => now()->toDateString(),
                    'to' => now()->addDays(7)->toDateString(),
                    'reason' => 'Travel',
                ]],
            ],
            'preferences' => ['teams' => ['worship'], 'shifts' => ['morning']],
            'experience' => [['title' => 'Worship leader', 'organization' => 'Branch choir', 'years' => 3]],
            'certifications' => [[
                'name' => 'Sound engineering',
                'issuer' => 'Media school',
                'issued_at' => now()->subYear()->toDateString(),
                'expires_at' => now()->addDays(10)->toDateString(),
            ]],
            'training' => [['name' => 'Usher orientation', 'completed_at' => now()->subMonth()->toDateString()]],
            'service_history' => [['title' => 'Sunday usher', 'hours' => 2, 'served_at' => now()->subWeek()->toDateString()]],
            'volunteer_hours' => 24,
            'restricted_notes' => 'Strong leader; watch scheduling load.',
        ], $overrides);
    }

    // ------------------------------------------------------------------
    // AC1 — centralized volunteer profile with alerts
    // ------------------------------------------------------------------

    public function test_coordinator_creates_volunteer_profile_with_capabilities_and_service_data(): void
    {
        $coordinator = $this->coordinator();
        $member = $this->volunteerMember();

        $this->actingAsMfaVerified($coordinator)
            ->postJson('/api/volunteers', $this->profilePayload($member))
            ->assertCreated()
            ->assertJsonPath('data.member_id', $member->id)
            ->assertJsonPath('data.skills.0', 'vocals')
            ->assertJsonPath('data.restricted_notes', 'Strong leader; watch scheduling load.')
            ->assertJsonPath('data.alerts.expiring_certifications.0.name', 'Sound engineering');

        $this->assertDatabaseHas('volunteer_profiles', [
            'member_id' => $member->id,
            'status' => VolunteerProfile::STATUS_ACTIVE,
        ]);

        $this->assertDatabaseHas('audit_events', ['action' => 'volunteer_profile.created']);
    }

    public function test_alerts_identify_expiring_certifications_and_unavailable_periods(): void
    {
        $coordinator = $this->coordinator();
        $member = $this->volunteerMember();

        $this->actingAsMfaVerified($coordinator)
            ->postJson('/api/volunteers', $this->profilePayload($member))
            ->assertCreated();

        $this->actingAsMfaVerified($coordinator)
            ->getJson('/api/volunteers/alerts')
            ->assertOk()
            ->assertJsonPath('data.expiring_certifications.0.member_id', $member->id)
            ->assertJsonPath('data.unavailable_periods.0.reason', 'Travel');
    }

    // ------------------------------------------------------------------
    // AC2 — verification rules, effective dates, restricted notes
    // ------------------------------------------------------------------

    public function test_volunteer_self_update_applies_immediate_fields_and_queues_verification(): void
    {
        $member = $this->volunteerMember();
        $coordinator = $this->coordinator();

        $this->actingAsMfaVerified($coordinator)
            ->postJson('/api/volunteers', $this->profilePayload($member, ['restricted_notes' => null]))
            ->assertCreated();

        Sanctum::actingAs($member->user);

        $this->putJson('/api/me/volunteer-profile', [
            'skills' => ['vocals', 'drums'],
            'certifications' => [[
                'name' => 'First aid',
                'issuer' => 'Red Cross',
                'issued_at' => now()->toDateString(),
                'expires_at' => now()->addYear()->toDateString(),
            ]],
            'effective_from' => now()->addDay()->toDateString(),
        ])
            ->assertOk()
            ->assertJsonPath('data.skills.1', 'drums')
            ->assertJsonPath('data.pending_changes.0.field', 'certifications');

        $this->assertDatabaseHas('volunteer_profile_changes', [
            'field' => 'certifications',
            'verification_status' => VolunteerProfileChange::STATUS_PENDING,
        ]);
    }

    public function test_coordinator_verifies_pending_certification_change(): void
    {
        $member = $this->volunteerMember();
        $coordinator = $this->coordinator();

        $this->actingAsMfaVerified($coordinator)
            ->postJson('/api/volunteers', $this->profilePayload($member))
            ->assertCreated();

        Sanctum::actingAs($member->user);
        $this->putJson('/api/me/volunteer-profile', [
            'certifications' => [
                [
                    'name' => 'Sound engineering',
                    'issuer' => 'Media school',
                    'issued_at' => now()->subYear()->toDateString(),
                    'expires_at' => now()->addDays(10)->toDateString(),
                ],
                [
                    'name' => 'Child safeguarding',
                    'issuer' => 'Diocese',
                    'issued_at' => now()->toDateString(),
                    'expires_at' => now()->addYears(2)->toDateString(),
                ],
            ],
        ])->assertOk();

        $change = VolunteerProfileChange::query()
            ->where('field', 'certifications')
            ->where('verification_status', VolunteerProfileChange::STATUS_PENDING)
            ->firstOrFail();

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/volunteers/changes/{$change->id}/verify", ['approve' => true])
            ->assertOk()
            ->assertJsonPath('data.certifications.1.name', 'Child safeguarding');

        $this->assertDatabaseHas('volunteer_profile_changes', [
            'id' => $change->id,
            'verification_status' => VolunteerProfileChange::STATUS_VERIFIED,
        ]);
    }

    public function test_restricted_notes_hidden_from_volunteer_and_read_only_leader(): void
    {
        $member = $this->volunteerMember();
        $coordinator = $this->coordinator();
        $reader = $this->coordinator(['volunteers.read']);

        $created = $this->actingAsMfaVerified($coordinator)
            ->postJson('/api/volunteers', $this->profilePayload($member))
            ->assertCreated();

        $profileId = $created->json('data.id');

        Sanctum::actingAs($member->user);
        $this->getJson('/api/me/volunteer-profile')
            ->assertOk()
            ->assertJsonMissingPath('data.restricted_notes');

        $this->actingAsMfaVerified($reader)
            ->getJson("/api/volunteers/{$profileId}")
            ->assertOk()
            ->assertJsonMissingPath('data.restricted_notes');

        $this->actingAsMfaVerified($coordinator)
            ->getJson("/api/volunteers/{$profileId}")
            ->assertOk()
            ->assertJsonPath('data.restricted_notes', 'Strong leader; watch scheduling load.');
    }
}
