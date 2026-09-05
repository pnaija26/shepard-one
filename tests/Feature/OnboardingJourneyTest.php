<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\OnboardingEnrollment;
use App\Models\OnboardingJourney;
use App\Models\OnboardingStepRun;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\Visitor;
use App\Models\VisitorVisit;
use App\Services\OnboardingJourneyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 3.2: Run Configurable Welcome and Onboarding Journeys.
 */
class OnboardingJourneyTest extends TestCase
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
        $user = $this->privilegedUser(['branch_id' => null]);
        $role = Role::create(['name' => 'onboarding_coord_' . $user->id]);
        foreach (['onboarding.read', 'onboarding.manage', 'visitors.read', 'visitors.write', 'visitors.sensitive'] as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);

        return $user;
    }

    private function defaultSteps(): array
    {
        return [
            ['key' => 'day_0_welcome', 'day_offset' => 0, 'action_type' => 'message', 'channel' => 'email', 'message' => 'Welcome'],
            ['key' => 'day_1_task', 'day_offset' => 1, 'action_type' => 'task', 'title' => 'Call visitor'],
            ['key' => 'day_3_reminder', 'day_offset' => 3, 'action_type' => 'reminder', 'channel' => 'in_app', 'message' => 'Reminder'],
        ];
    }

    private function publishVisitorJourney(User $coordinator): OnboardingJourney
    {
        $this->actingAsMfaVerified($coordinator)
            ->postJson('/api/onboarding/journeys', [
                'name' => 'Visitor welcome',
                'trigger_event' => 'visitor.captured',
                'branch_id' => $this->branch->id,
            ])
            ->assertCreated();

        $journey = OnboardingJourney::firstOrFail();

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/onboarding/journeys/{$journey->id}/publish", [
                'steps' => $this->defaultSteps(),
            ])
            ->assertOk();

        return $journey->fresh();
    }

    // ------------------------------------------------------------------
    // AC1 — enroll once on event with journey version recorded
    // ------------------------------------------------------------------

    public function test_visitor_capture_enrolls_in_published_journey_once(): void
    {
        $coordinator = $this->coordinator();
        $journey = $this->publishVisitorJourney($coordinator);

        $this->actingAsMfaVerified($coordinator)
            ->postJson('/api/visitors', [
                'branch_id' => $this->branch->id,
                'first_name' => 'Tolu',
                'last_name' => 'Ade',
                'email' => 'tolu@example.com',
                'visit_date' => '2026-08-31',
                'source' => 'service',
                'consent_data_processing' => true,
                'consent_follow_up' => true,
            ])
            ->assertCreated();

        $visitor = Visitor::firstOrFail();

        $this->assertDatabaseHas('onboarding_enrollments', [
            'journey_id' => $journey->id,
            'subject_type' => Visitor::class,
            'subject_id' => $visitor->id,
            'journey_version' => 1,
            'status' => OnboardingEnrollment::STATUS_ACTIVE,
        ]);

        $this->assertDatabaseCount('onboarding_enrollments', 1);
        $this->assertDatabaseCount('onboarding_step_runs', 3);

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/visitors/{$visitor->id}/visits", [
                'visit_date' => '2026-09-07',
                'source' => 'service',
                'consent_data_processing' => true,
            ])
            ->assertCreated();

        $this->assertDatabaseCount('onboarding_enrollments', 1);
    }

    // ------------------------------------------------------------------
    // AC2 — due steps execute idempotently with visible statuses
    // ------------------------------------------------------------------

    public function test_due_steps_execute_idempotently_and_show_statuses(): void
    {
        $coordinator = $this->coordinator();
        $this->publishVisitorJourney($coordinator);

        $this->actingAsMfaVerified($coordinator)
            ->postJson('/api/visitors', [
                'branch_id' => $this->branch->id,
                'first_name' => 'Day',
                'last_name' => 'Zero',
                'visit_date' => '2026-08-31',
                'source' => 'service',
                'consent_data_processing' => true,
                'consent_follow_up' => true,
            ])
            ->assertCreated();

        $service = app(OnboardingJourneyService::class);
        $counts = $service->processDueSteps($coordinator);
        $this->assertSame(1, $counts['completed']);

        $countsAgain = $service->processDueSteps($coordinator);
        $this->assertSame(0, $countsAgain['processed']);

        $enrollment = OnboardingEnrollment::firstOrFail();

        $this->actingAsMfaVerified($coordinator)
            ->getJson("/api/onboarding/enrollments/{$enrollment->id}")
            ->assertOk()
            ->assertJsonPath('data.steps.0.status', OnboardingStepRun::STATUS_COMPLETED)
            ->assertJsonPath('data.steps.1.status', OnboardingStepRun::STATUS_PENDING);
    }

    public function test_failed_step_is_recorded_as_retryable_failure(): void
    {
        $coordinator = $this->coordinator();

        $journey = OnboardingJourney::create([
            'name' => 'Failure path',
            'trigger_event' => 'visitor.captured',
            'branch_id' => $this->branch->id,
            'status' => OnboardingJourney::STATUS_DRAFT,
            'created_by' => $coordinator->id,
        ]);

        app(OnboardingJourneyService::class)->publishJourney($coordinator, $journey, [
            ['key' => 'fail_step', 'day_offset' => 0, 'action_type' => 'message', 'simulate_failure' => true],
        ]);

        $this->actingAsMfaVerified($coordinator)
            ->postJson('/api/visitors', [
                'branch_id' => $this->branch->id,
                'first_name' => 'Fail',
                'last_name' => 'Case',
                'visit_date' => '2026-08-31',
                'source' => 'service',
                'consent_data_processing' => true,
                'consent_follow_up' => true,
            ])
            ->assertCreated();

        $counts = app(OnboardingJourneyService::class)->processDueSteps($coordinator);
        $this->assertSame(1, $counts['failed']);

        $this->assertDatabaseHas('onboarding_step_runs', [
            'step_key' => 'fail_step',
            'status' => OnboardingStepRun::STATUS_FAILED,
        ]);
    }

    // ------------------------------------------------------------------
    // AC3 — stop conditions skip prohibited steps only
    // ------------------------------------------------------------------

    public function test_consent_withdrawal_skips_future_steps_without_blocking_other_enrollments(): void
    {
        $coordinator = $this->coordinator();
        $this->publishVisitorJourney($coordinator);

        $this->actingAsMfaVerified($coordinator)
            ->postJson('/api/visitors', [
                'branch_id' => $this->branch->id,
                'first_name' => 'Consent',
                'last_name' => 'Withdrawn',
                'visit_date' => '2026-08-31',
                'source' => 'service',
                'consent_data_processing' => true,
                'consent_follow_up' => true,
            ])
            ->assertCreated();

        $member = Member::create([
            'membership_id' => 'S1-M-ONB-01',
            'branch_id' => $this->branch->id,
            'registration_channel' => 'web',
            'first_name' => 'Active',
            'last_name' => 'Member',
            'consent_data_processing' => true,
            'lifecycle_stage' => 'visitor',
            'lifecycle_status' => 'active',
        ]);

        $memberJourney = OnboardingJourney::create([
            'name' => 'Member registered',
            'trigger_event' => 'member.registered',
            'branch_id' => $this->branch->id,
            'status' => OnboardingJourney::STATUS_DRAFT,
            'created_by' => $coordinator->id,
        ]);
        app(OnboardingJourneyService::class)->publishJourney($coordinator, $memberJourney, [
            ['key' => 'member_day_0', 'day_offset' => 0, 'action_type' => 'message', 'message' => 'Hello member'],
        ]);
        app(OnboardingJourneyService::class)->handleEvent('member.registered', $member, $coordinator);

        $visitor = Visitor::firstOrFail();
        VisitorVisit::query()->where('visitor_id', $visitor->id)->update(['consent_follow_up' => false]);

        $this->travel(1)->days();
        $counts = app(OnboardingJourneyService::class)->processDueSteps($coordinator);

        $this->assertGreaterThanOrEqual(1, $counts['skipped'] + $counts['completed']);

        $visitorEnrollment = OnboardingEnrollment::where('subject_type', Visitor::class)->firstOrFail();
        $memberEnrollment = OnboardingEnrollment::where('subject_type', Member::class)->firstOrFail();

        $this->assertDatabaseHas('onboarding_step_runs', [
            'enrollment_id' => $visitorEnrollment->id,
            'step_key' => 'day_1_task',
            'status' => OnboardingStepRun::STATUS_SKIPPED,
        ]);

        $this->assertDatabaseHas('onboarding_step_runs', [
            'enrollment_id' => $memberEnrollment->id,
            'step_key' => 'member_day_0',
            'status' => OnboardingStepRun::STATUS_COMPLETED,
        ]);

        $this->assertDatabaseHas('audit_events', ['action' => 'onboarding.step.skipped']);
    }
}
