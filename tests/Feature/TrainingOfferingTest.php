<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\MemberNotification;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\TrainingEnrolment;
use App\Models\TrainingOffering;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 6.3: Publish Training and Discipleship Offerings.
 */
class TrainingOfferingTest extends TestCase
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
        $role = Role::create(['name' => 'training_coord_' . $user->id]);
        foreach ([
            'training.read', 'training.manage', 'training.publish', 'training.enrol',
            'training.materials.restricted', 'training.facilitators.read',
        ] as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);

        return $user;
    }

    private function reader(): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $role = Role::create(['name' => 'training_reader_' . $user->id]);
        RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => 'training.read']);
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

        $role = Role::create(['name' => 'training_member_' . $user->id]);
        foreach (['training.read', 'training.enrol.self'] as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);

        return $user;
    }

    private function member(string $suffix, array $attrs = []): Member
    {
        return Member::create(array_merge([
            'membership_id' => 'S63-' . $suffix,
            'branch_id' => $this->branch->id,
            'registration_channel' => 'web',
            'first_name' => 'Train',
            'last_name' => 'Member' . $suffix,
            'consent_data_processing' => true,
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
            'date_of_birth' => '1995-01-01',
        ], $attrs));
    }

    /**
     * @return array<string, mixed>
     */
    private function offeringPayload(array $overrides = []): array
    {
        return array_merge([
            'branch_id' => $this->branch->id,
            'name' => 'Foundations of Faith',
            'course_type' => 'new_believer',
            'description' => 'Introductory discipleship pathway.',
            'capacity' => 2,
            'waitlist_enabled' => true,
            'sessions' => [[
                'title' => 'Session 1: Gospel',
                'scheduled_at' => now()->addWeek()->toIso8601String(),
                'location' => 'Room A',
                'duration_minutes' => 90,
            ]],
            'prerequisites' => ['required_offering_ids' => []],
            'facilitators' => [[
                'name' => 'Pastor Jane',
                'role' => 'lead',
                'email' => 'jane@example.com',
                'phone' => '+1234567890',
            ]],
            'assessments' => [[
                'title' => 'Reflection journal',
                'type' => 'reflection',
                'required' => true,
            ]],
            'materials' => [
                ['title' => 'Welcome guide', 'url' => 'https://example.com/welcome', 'access_level' => 'public'],
                ['title' => 'Workbook', 'url' => 'https://example.com/workbook', 'access_level' => 'enrolled'],
                ['title' => 'Pastoral notes', 'url' => 'https://example.com/pastoral', 'access_level' => 'restricted'],
            ],
            'completion_rules' => ['min_attendance_sessions' => 1],
            'enrolment_rules' => [
                'lifecycle_stages' => ['member'],
                'min_age' => 18,
                'requires_consent' => true,
            ],
        ], $overrides);
    }

    private function createPublishedOffering(User $coordinator, array $overrides = []): int
    {
        $offeringId = $this->actingAsMfaVerified($coordinator)
            ->postJson('/api/training-offerings', $this->offeringPayload($overrides))
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/training-offerings/{$offeringId}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', TrainingOffering::STATUS_PUBLISHED);

        return $offeringId;
    }

    // ------------------------------------------------------------------
    // AC1 — configure and publish versioned offerings with permissions
    // ------------------------------------------------------------------

    public function test_coordinator_creates_and_publishes_training_offering(): void
    {
        $coordinator = $this->coordinator();

        $created = $this->actingAsMfaVerified($coordinator)
            ->postJson('/api/training-offerings', $this->offeringPayload())
            ->assertCreated()
            ->assertJsonPath('data.status', TrainingOffering::STATUS_DRAFT)
            ->assertJsonPath('data.course_type', 'new_believer');

        $offeringId = $created->json('data.id');

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/training-offerings/{$offeringId}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', TrainingOffering::STATUS_PUBLISHED)
            ->assertJsonPath('data.current_version', 1)
            ->assertJsonCount(1, 'data.published_config.sessions');

        $this->assertDatabaseHas('training_offerings', [
            'id' => $offeringId,
            'status' => TrainingOffering::STATUS_PUBLISHED,
            'current_version' => 1,
        ]);
    }

    public function test_protected_materials_and_facilitators_respect_permissions(): void
    {
        $coordinator = $this->coordinator();
        $reader = $this->reader();
        $offeringId = $this->createPublishedOffering($coordinator);

        $coordinatorView = $this->actingAsMfaVerified($coordinator)
            ->getJson("/api/training-offerings/{$offeringId}")
            ->assertOk()
            ->json('data.published_config');

        $this->assertSame('jane@example.com', $coordinatorView['facilitators'][0]['email']);
        $this->assertCount(3, $coordinatorView['materials']);

        $readerView = $this->actingAsMfaVerified($reader)
            ->getJson("/api/training-offerings/{$offeringId}")
            ->assertOk()
            ->json('data.published_config');

        $this->assertTrue($readerView['facilitators'][0]['contact_restricted'] ?? false);

        $restrictedMaterial = collect($readerView['materials'])->firstWhere('restricted', true);
        $this->assertNotNull($restrictedMaterial);
        $this->assertSame('[Restricted material]', $restrictedMaterial['title']);
    }

    // ------------------------------------------------------------------
    // AC2 — enrolment evaluation, waitlist, and member delivery
    // ------------------------------------------------------------------

    public function test_eligible_member_enrols_and_receives_schedule_and_materials(): void
    {
        $coordinator = $this->coordinator();
        $member = $this->member('ENROL');
        $memberUser = $this->memberUser($member);
        $offeringId = $this->createPublishedOffering($coordinator);

        $enrolment = $this->actingAsMfaVerified($memberUser)
            ->postJson("/api/training-offerings/{$offeringId}/enrol", ['member_id' => $member->id])
            ->assertCreated()
            ->assertJsonPath('data.status', TrainingEnrolment::STATUS_ENROLLED)
            ->json('data');

        $this->assertNotEmpty($enrolment['schedule']);
        $this->assertNotEmpty($enrolment['materials']);

        $this->assertDatabaseHas('training_enrolments', [
            'training_offering_id' => $offeringId,
            'member_id' => $member->id,
            'status' => TrainingEnrolment::STATUS_ENROLLED,
        ]);

        $this->assertDatabaseHas('member_notifications', [
            'member_id' => $member->id,
            'type' => 'training.enrolment.enrolled',
        ]);
    }

    public function test_capacity_full_places_member_on_waitlist(): void
    {
        $coordinator = $this->coordinator();
        $offeringId = $this->createPublishedOffering($coordinator, ['capacity' => 1]);

        $first = $this->member('ONE');
        $second = $this->member('TWO');

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/training-offerings/{$offeringId}/enrol", ['member_id' => $first->id])
            ->assertCreated()
            ->assertJsonPath('data.status', TrainingEnrolment::STATUS_ENROLLED);

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/training-offerings/{$offeringId}/enrol", ['member_id' => $second->id])
            ->assertCreated()
            ->assertJsonPath('data.status', TrainingEnrolment::STATUS_WAITLISTED)
            ->assertJsonPath('data.waitlist_position', 1);
    }

    public function test_missing_prerequisite_results_in_rejection(): void
    {
        $coordinator = $this->coordinator();
        $prerequisiteId = $this->createPublishedOffering($coordinator, [
            'name' => 'Intro Course',
            'course_type' => 'membership',
        ]);

        $advancedId = $this->actingAsMfaVerified($coordinator)
            ->postJson('/api/training-offerings', $this->offeringPayload([
                'name' => 'Advanced Leadership',
                'course_type' => 'leadership',
                'prerequisites' => ['required_offering_ids' => [$prerequisiteId]],
            ]))
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/training-offerings/{$advancedId}/publish")
            ->assertOk();

        $member = $this->member('PRE');

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/training-offerings/{$advancedId}/enrol", ['member_id' => $member->id])
            ->assertCreated()
            ->assertJsonPath('data.status', TrainingEnrolment::STATUS_REJECTED)
            ->assertJsonPath('data.rejection_reason', 'Required prerequisite offering not completed.');
    }

    public function test_unauthorized_user_cannot_configure_offerings(): void
    {
        $outsider = User::factory()->create([
            'branch_id' => $this->branch->id,
            'has_mfa_enrolled' => true,
        ]);

        $this->actingAsMfaVerified($outsider)
            ->postJson('/api/training-offerings', $this->offeringPayload())
            ->assertForbidden();
    }
}
