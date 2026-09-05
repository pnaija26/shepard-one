<?php

namespace Tests\Feature;

use App\Models\AttendanceException;
use App\Models\AttendanceExceptionRule;
use App\Models\AttendanceRecord;
use App\Models\AuditEvent;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\Visitor;
use App\Models\VisitorVisit;
use App\Services\AttendanceExceptionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 3.3: Detect Attendance Exceptions.
 */
class AttendanceExceptionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'IDX-HQ']);
        $this->branch = Organization::create(['name' => 'Branch A', 'type' => 'branch', 'identifier' => 'IDX-A', 'parent_id' => $hq->id]);
    }

    private function leader(): User
    {
        $user = $this->privilegedUser(['branch_id' => null]);
        $role = Role::create(['name' => 'attendance_leader_' . $user->id]);
        foreach ([
            'attendance.read',
            'attendance.write',
            'attendance.exceptions.read',
            'attendance.exceptions.manage',
        ] as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);

        return $user;
    }

    private function member(): Member
    {
        return Member::create([
            'membership_id' => 'S1-M-ATT-01',
            'branch_id' => $this->branch->id,
            'registration_channel' => 'web',
            'first_name' => 'Grace',
            'last_name' => 'Okafor',
            'consent_data_processing' => true,
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
        ]);
    }

    private function publishConsecutiveRule(User $leader, int $count = 3): AttendanceExceptionRule
    {
        $this->actingAsMfaVerified($leader)
            ->postJson('/api/attendance/rules', [
                'name' => 'Three absences',
                'rule_type' => 'consecutive_absence',
                'branch_id' => $this->branch->id,
                'service_type' => 'sunday_service',
            ])
            ->assertCreated();

        $rule = AttendanceExceptionRule::firstOrFail();

        $this->actingAsMfaVerified($leader)
            ->postJson("/api/attendance/rules/{$rule->id}/publish", [
                'parameters' => ['consecutive_count' => $count, 'lookback_days' => 90],
            ])
            ->assertOk();

        return $rule->fresh();
    }

    private function recordAbsence(User $leader, Member $member, string $date): void
    {
        $this->actingAsMfaVerified($leader)
            ->postJson('/api/attendance/records', [
                'subject_type' => Member::class,
                'subject_id' => $member->id,
                'branch_id' => $this->branch->id,
                'service_type' => 'sunday_service',
                'gathering_date' => $date,
                'status' => 'absent',
            ])
            ->assertCreated();
    }

    // ------------------------------------------------------------------
    // AC1 — one open exception per rule and qualifying period
    // ------------------------------------------------------------------

    public function test_consecutive_absence_creates_one_open_exception_per_period(): void
    {
        Carbon::setTestNow('2026-08-31');
        $leader = $this->leader();
        $rule = $this->publishConsecutiveRule($leader);
        $member = $this->member();

        $this->recordAbsence($leader, $member, '2026-08-17');
        $this->recordAbsence($leader, $member, '2026-08-24');
        $this->assertDatabaseCount('attendance_exceptions', 0);

        $this->recordAbsence($leader, $member, '2026-08-31');

        $this->assertDatabaseHas('attendance_exceptions', [
            'rule_id' => $rule->id,
            'subject_type' => Member::class,
            'subject_id' => $member->id,
            'period_key' => '2026-08-17',
            'status' => AttendanceException::STATUS_OPEN,
            'rule_version' => 1,
        ]);

        $this->recordAbsence($leader, $member, '2026-09-07');
        $this->assertDatabaseCount('attendance_exceptions', 1);
    }

    public function test_repeated_team_absence_rule_detects_team_pattern(): void
    {
        Carbon::setTestNow('2026-08-31');
        $leader = $this->leader();
        $member = $this->member();

        $this->actingAsMfaVerified($leader)
            ->postJson('/api/attendance/rules', [
                'name' => 'Team absences',
                'rule_type' => 'repeated_team_absence',
                'branch_id' => $this->branch->id,
            ])
            ->assertCreated();

        $rule = AttendanceExceptionRule::firstOrFail();
        $this->actingAsMfaVerified($leader)
            ->postJson("/api/attendance/rules/{$rule->id}/publish", [
                'parameters' => ['absence_count' => 2, 'lookback_days' => 30],
            ])
            ->assertOk();

        foreach (['2026-08-10', '2026-08-17'] as $date) {
            $this->actingAsMfaVerified($leader)
                ->postJson('/api/attendance/records', [
                    'subject_type' => Member::class,
                    'subject_id' => $member->id,
                    'branch_id' => $this->branch->id,
                    'service_type' => 'team_rehearsal',
                    'gathering_date' => $date,
                    'status' => 'absent',
                    'team_id' => 42,
                ])
                ->assertCreated();
        }

        $this->assertDatabaseHas('attendance_exceptions', [
            'rule_id' => $rule->id,
            'rule_type' => 'repeated_team_absence',
            'status' => AttendanceException::STATUS_OPEN,
        ]);
    }

    // ------------------------------------------------------------------
    // AC2 — exclusions prevent unsupported conclusions
    // ------------------------------------------------------------------

    public function test_excused_and_online_records_do_not_trigger_consecutive_absence(): void
    {
        Carbon::setTestNow('2026-08-31');
        $leader = $this->leader();
        $this->publishConsecutiveRule($leader);
        $member = $this->member();

        $this->actingAsMfaVerified($leader)
            ->postJson('/api/attendance/records', [
                'subject_type' => Member::class,
                'subject_id' => $member->id,
                'branch_id' => $this->branch->id,
                'service_type' => 'sunday_service',
                'gathering_date' => '2026-08-17',
                'status' => 'excused',
            ])
            ->assertCreated();

        $this->recordAbsence($leader, $member, '2026-08-24');
        $this->recordAbsence($leader, $member, '2026-08-31');

        $this->assertDatabaseCount('attendance_exceptions', 0);

        $this->actingAsMfaVerified($leader)
            ->postJson('/api/attendance/records', [
                'subject_type' => Member::class,
                'subject_id' => $member->id,
                'branch_id' => $this->branch->id,
                'service_type' => 'sunday_service',
                'gathering_date' => '2026-09-07',
                'status' => 'online',
            ])
            ->assertCreated();

        $this->assertDatabaseCount('attendance_exceptions', 0);
    }

    public function test_service_cancellation_and_insufficient_history_are_excluded(): void
    {
        Carbon::setTestNow('2026-08-31');
        $leader = $this->leader();
        $this->publishConsecutiveRule($leader, 3);
        $member = $this->member();

        $this->actingAsMfaVerified($leader)
            ->postJson('/api/attendance/records', [
                'subject_type' => Member::class,
                'subject_id' => $member->id,
                'branch_id' => $this->branch->id,
                'service_type' => 'sunday_service',
                'gathering_date' => '2026-08-24',
                'status' => 'absent',
                'service_cancelled' => true,
            ])
            ->assertCreated();

        $this->recordAbsence($leader, $member, '2026-08-31');
        $this->assertDatabaseCount('attendance_exceptions', 0);
    }

    // ------------------------------------------------------------------
    // AC3 — correction re-evaluates and resolves with audit
    // ------------------------------------------------------------------

    public function test_correcting_attendance_resolves_exception_with_audit(): void
    {
        Carbon::setTestNow('2026-08-31');
        $leader = $this->leader();
        $this->publishConsecutiveRule($leader);
        $member = $this->member();

        $this->recordAbsence($leader, $member, '2026-08-17');
        $this->recordAbsence($leader, $member, '2026-08-24');
        $this->recordAbsence($leader, $member, '2026-08-31');

        $exception = AttendanceException::firstOrFail();
        $record = AttendanceRecord::query()->whereDate('gathering_date', '2026-08-31')->firstOrFail();

        $this->actingAsMfaVerified($leader)
            ->postJson("/api/attendance/records/{$record->id}/correct", [
                'status' => 'present',
                'correction_reason' => 'Member confirmed attendance after service.',
            ])
            ->assertOk();

        $exception->refresh();
        $this->assertSame(AttendanceException::STATUS_RESOLVED, $exception->status);
        $this->assertSame('attendance_corrected', $exception->resolution_reason);
        $this->assertNotNull($exception->resolved_at);

        $this->assertDatabaseHas('audit_events', ['action' => 'attendance.exception.reconciled']);
        $this->assertDatabaseHas('audit_events', ['action' => 'attendance.corrected']);
    }

    public function test_no_return_after_first_visit_detects_visitor_gap(): void
    {
        Carbon::setTestNow('2026-09-20');
        $leader = $this->leader();

        $visitor = Visitor::create([
            'branch_id' => $this->branch->id,
            'first_name' => 'New',
            'last_name' => 'Guest',
            'original_source' => 'service',
            'first_visit_at' => '2026-08-31',
            'created_by' => $leader->id,
            'updated_by' => $leader->id,
        ]);

        VisitorVisit::create([
            'visitor_id' => $visitor->id,
            'branch_id' => $this->branch->id,
            'visit_date' => '2026-08-31',
            'attendance_status' => 'first_timer',
            'source' => 'service',
            'consent_data_processing' => true,
            'consent_follow_up' => true,
            'recorded_by' => $leader->id,
        ]);

        $rule = AttendanceExceptionRule::create([
            'name' => 'No return',
            'rule_type' => 'no_return_after_first_visit',
            'branch_id' => $this->branch->id,
            'status' => AttendanceExceptionRule::STATUS_DRAFT,
            'created_by' => $leader->id,
        ]);

        app(AttendanceExceptionService::class)->publishRule($leader, $rule, ['days_since_first' => 14]);
        app(AttendanceExceptionService::class)->evaluateForSubject($leader, $visitor);

        $this->assertDatabaseHas('attendance_exceptions', [
            'rule_type' => 'no_return_after_first_visit',
            'subject_type' => Visitor::class,
            'subject_id' => $visitor->id,
            'status' => AttendanceException::STATUS_OPEN,
        ]);
    }
}
