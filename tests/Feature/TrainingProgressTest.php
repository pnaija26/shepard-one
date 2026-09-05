<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\TrainingCertificate;
use App\Models\TrainingCompletionRecord;
use App\Models\TrainingEnrolment;
use App\Models\TrainingSessionAttendance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 6.4: Track Training Completion and Certification.
 */
class TrainingProgressTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'IDX-HQ']);
        $this->branch = Organization::create(['name' => 'Branch A', 'type' => 'branch', 'identifier' => 'IDX-A', 'parent_id' => $hq->id]);
    }

    private function facilitator(): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $role = Role::create(['name' => 'training_facilitator_' . $user->id]);
        foreach ([
            'training.read', 'training.manage', 'training.publish', 'training.enrol',
            'training.progress.read', 'training.progress.manage', 'training.progress.correct',
            'training.completion.confirm', 'training.certificates.revoke', 'training.certificates.verify',
        ] as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);

        return $user;
    }

    private function member(string $suffix, array $attrs = []): Member
    {
        return Member::create(array_merge([
            'membership_id' => 'S64-' . $suffix,
            'branch_id' => $this->branch->id,
            'registration_channel' => 'web',
            'first_name' => 'Train',
            'last_name' => 'Learner' . $suffix,
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
            'name' => 'Discipleship Foundations',
            'course_type' => 'new_believer',
            'description' => 'Core discipleship course.',
            'capacity' => 20,
            'waitlist_enabled' => true,
            'sessions' => [[
                'title' => 'Session 1: Gospel',
                'scheduled_at' => now()->addWeek()->toIso8601String(),
                'location' => 'Room A',
                'duration_minutes' => 90,
            ]],
            'prerequisites' => ['required_offering_ids' => []],
            'facilitators' => [['name' => 'Pastor Jane', 'role' => 'lead']],
            'assessments' => [[
                'title' => 'Reflection journal',
                'type' => 'reflection',
                'required' => true,
            ]],
            'materials' => [['title' => 'Workbook', 'access_level' => 'enrolled']],
            'completion_rules' => ['min_attendance_sessions' => 1],
            'enrolment_rules' => ['lifecycle_stages' => ['member'], 'requires_consent' => true],
        ], $overrides);
    }

    private function createPublishedOffering(User $facilitator, array $overrides = []): int
    {
        $offeringId = $this->actingAsMfaVerified($facilitator)
            ->postJson('/api/training-offerings', $this->offeringPayload($overrides))
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($facilitator)
            ->postJson("/api/training-offerings/{$offeringId}/publish")
            ->assertOk();

        return $offeringId;
    }

    private function enrolMember(User $facilitator, int $offeringId, Member $member): int
    {
        return $this->actingAsMfaVerified($facilitator)
            ->postJson("/api/training-offerings/{$offeringId}/enrol", ['member_id' => $member->id])
            ->assertCreated()
            ->assertJsonPath('data.status', TrainingEnrolment::STATUS_ENROLLED)
            ->json('data.id');
    }

    private function satisfyRequirements(User $facilitator, int $enrolmentId): void
    {
        $this->actingAsMfaVerified($facilitator)
            ->postJson("/api/training-enrolments/{$enrolmentId}/attendance", [
                'entries' => [[
                    'session_key' => 'session_1',
                    'session_title' => 'Session 1: Gospel',
                    'status' => 'present',
                ]],
            ])
            ->assertOk();

        $this->actingAsMfaVerified($facilitator)
            ->postJson("/api/training-enrolments/{$enrolmentId}/assessments", [
                'entries' => [[
                    'assessment_key' => 'Reflection journal',
                    'assessment_title' => 'Reflection journal',
                    'result_status' => 'passed',
                    'score' => 92,
                ]],
            ])
            ->assertOk();
    }

    // ------------------------------------------------------------------
    // AC1 — progress calculation and auditable corrections
    // ------------------------------------------------------------------

    public function test_attendance_and_assessments_calculate_progress(): void
    {
        $facilitator = $this->facilitator();
        $member = $this->member('PROG');
        $offeringId = $this->createPublishedOffering($facilitator);
        $enrolmentId = $this->enrolMember($facilitator, $offeringId, $member);

        $partial = $this->actingAsMfaVerified($facilitator)
            ->postJson("/api/training-enrolments/{$enrolmentId}/attendance", [
                'entries' => [[
                    'session_key' => 'session_1',
                    'session_title' => 'Session 1: Gospel',
                    'status' => 'absent',
                ]],
            ])
            ->assertOk()
            ->json('data');

        $this->assertFalse($partial['requirements_met']);
        $this->assertNotEmpty($partial['unmet_criteria']);

        $this->satisfyRequirements($facilitator, $enrolmentId);

        $complete = $this->actingAsMfaVerified($facilitator)
            ->getJson("/api/training-enrolments/{$enrolmentId}/progress")
            ->assertOk()
            ->json('data');

        $this->assertTrue($complete['requirements_met']);
        $this->assertEquals(100, $complete['progress_percent']);
        $this->assertSame(TrainingCompletionRecord::STATUS_READY, $complete['completion']['status']);
    }

    public function test_corrections_retain_actor_reason_and_prior_value(): void
    {
        $facilitator = $this->facilitator();
        $member = $this->member('CORR');
        $offeringId = $this->createPublishedOffering($facilitator);
        $enrolmentId = $this->enrolMember($facilitator, $offeringId, $member);

        $this->actingAsMfaVerified($facilitator)
            ->postJson("/api/training-enrolments/{$enrolmentId}/attendance", [
                'entries' => [[
                    'session_key' => 'session_1',
                    'session_title' => 'Session 1: Gospel',
                    'status' => 'absent',
                ]],
            ])
            ->assertOk();

        $attendanceId = TrainingSessionAttendance::query()
            ->where('training_enrolment_id', $enrolmentId)
            ->value('id');

        $this->actingAsMfaVerified($facilitator)
            ->postJson("/api/training-session-attendance/{$attendanceId}/correct", [
                'status' => 'present',
                'reason' => 'Member arrived late and was marked incorrectly.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'present');

        $this->assertDatabaseHas('training_session_attendance_corrections', [
            'training_session_attendance_id' => $attendanceId,
            'before_status' => 'absent',
            'after_status' => 'present',
            'corrected_by' => $facilitator->id,
        ]);
    }

    // ------------------------------------------------------------------
    // AC2 — completion, certificates, revocation, and eligibility
    // ------------------------------------------------------------------

    public function test_confirm_completion_issues_verifiable_certificate(): void
    {
        $facilitator = $this->facilitator();
        $member = $this->member('CERT');
        $offeringId = $this->createPublishedOffering($facilitator);
        $enrolmentId = $this->enrolMember($facilitator, $offeringId, $member);
        $this->satisfyRequirements($facilitator, $enrolmentId);

        $result = $this->actingAsMfaVerified($facilitator)
            ->postJson("/api/training-enrolments/{$enrolmentId}/confirm-completion")
            ->assertOk()
            ->json('data');

        $reference = $result['certificate']['certificate_reference'];
        $this->assertNotEmpty($reference);
        $this->assertSame(1, $result['certificate']['course_version']);

        $this->assertDatabaseHas('training_certificates', [
            'training_enrolment_id' => $enrolmentId,
            'certificate_reference' => $reference,
            'status' => TrainingCertificate::STATUS_ISSUED,
        ]);

        $this->actingAsMfaVerified($facilitator)
            ->getJson("/api/training-certificates/verify/{$reference}")
            ->assertOk()
            ->assertJsonPath('data.valid', true);
    }

    public function test_duplicate_certificate_issuance_is_prevented(): void
    {
        $facilitator = $this->facilitator();
        $member = $this->member('DUP');
        $offeringId = $this->createPublishedOffering($facilitator);
        $enrolmentId = $this->enrolMember($facilitator, $offeringId, $member);
        $this->satisfyRequirements($facilitator, $enrolmentId);

        $this->actingAsMfaVerified($facilitator)
            ->postJson("/api/training-enrolments/{$enrolmentId}/confirm-completion")
            ->assertOk();

        $this->actingAsMfaVerified($facilitator)
            ->postJson("/api/training-enrolments/{$enrolmentId}/confirm-completion")
            ->assertStatus(422)
            ->assertJsonPath('code', 'duplicate_certificate');
    }

    public function test_revoked_certificate_is_invalid_and_prerequisite_fails(): void
    {
        $facilitator = $this->facilitator();
        $member = $this->member('REV');

        $introId = $this->createPublishedOffering($facilitator, [
            'name' => 'Intro Course',
            'course_type' => 'membership',
        ]);
        $introEnrolmentId = $this->enrolMember($facilitator, $introId, $member);
        $this->satisfyRequirements($facilitator, $introEnrolmentId);

        $completion = $this->actingAsMfaVerified($facilitator)
            ->postJson("/api/training-enrolments/{$introEnrolmentId}/confirm-completion")
            ->assertOk()
            ->json('data');

        $certificateId = $completion['certificate']['id'];
        $reference = $completion['certificate']['certificate_reference'];

        $advancedId = $this->createPublishedOffering($facilitator, [
            'name' => 'Advanced Leadership',
            'course_type' => 'leadership',
            'prerequisites' => ['required_offering_ids' => [$introId]],
        ]);

        $this->actingAsMfaVerified($facilitator)
            ->postJson("/api/training-offerings/{$advancedId}/enrol", ['member_id' => $member->id])
            ->assertCreated()
            ->assertJsonPath('data.status', TrainingEnrolment::STATUS_ENROLLED);

        $this->actingAsMfaVerified($facilitator)
            ->postJson("/api/training-certificates/{$certificateId}/revoke", [
                'reason' => 'Completion requirements were not fully met on review.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', TrainingCertificate::STATUS_REVOKED);

        $this->actingAsMfaVerified($facilitator)
            ->getJson("/api/training-certificates/verify/{$reference}")
            ->assertOk()
            ->assertJsonPath('data.valid', false);

        $otherMember = $this->member('REV2');
        $this->actingAsMfaVerified($facilitator)
            ->postJson("/api/training-offerings/{$advancedId}/enrol", ['member_id' => $otherMember->id])
            ->assertCreated()
            ->assertJsonPath('data.status', TrainingEnrolment::STATUS_REJECTED);
    }

    public function test_unauthorized_user_cannot_record_progress(): void
    {
        $facilitator = $this->facilitator();
        $outsider = User::factory()->create(['branch_id' => $this->branch->id, 'has_mfa_enrolled' => true]);
        $member = $this->member('SEC');
        $offeringId = $this->createPublishedOffering($facilitator);
        $enrolmentId = $this->enrolMember($facilitator, $offeringId, $member);

        $this->actingAsMfaVerified($outsider)
            ->postJson("/api/training-enrolments/{$enrolmentId}/attendance", [
                'entries' => [[
                    'session_key' => 'session_1',
                    'session_title' => 'Session 1',
                    'status' => 'present',
                ]],
            ])
            ->assertForbidden();
    }
}
