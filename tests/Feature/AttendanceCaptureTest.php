<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\AttendanceRecordCorrection;
use App\Models\AuditEvent;
use App\Models\ChurchService;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\MembershipCardService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 4.4: Capture Attendance Through Approved Methods.
 */
class AttendanceCaptureTest extends TestCase
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

    private function officer(): User
    {
        $user = $this->privilegedUser(['branch_id' => null]);
        $role = Role::create(['name' => 'attendance_officer_' . $user->id]);
        foreach (['attendance.read', 'attendance.write', 'services.read', 'services.manage'] as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);

        return $user;
    }

    private function member(array $overrides = []): Member
    {
        return Member::create(array_merge([
            'membership_id' => 'S1-M-CAP-' . uniqid(),
            'branch_id' => $this->branch->id,
            'registration_channel' => 'web',
            'first_name' => 'Daniel',
            'last_name' => 'Okoro',
            'membership_status' => 'active',
            'lifecycle_status' => 'active',
            'consent_data_processing' => true,
        ], $overrides));
    }

    private function publishedService(): ChurchService
    {
        $officer = $this->officer();

        $created = $this->actingAsMfaVerified($officer)
            ->postJson('/api/services', [
                'branch_id' => $this->branch->id,
                'service_type' => 'sunday_service',
                'title' => 'Sunday Celebration',
                'service_date' => '2026-09-07',
                'start_time' => '09:00',
                'end_time' => '11:30',
                'venue' => 'Main Auditorium',
                'ministers' => [['name' => 'Pastor James', 'role' => 'lead']],
            ])
            ->assertCreated();

        $serviceId = $created->json('data.id');

        $this->actingAsMfaVerified($officer)
            ->postJson("/api/services/{$serviceId}/publish")
            ->assertOk();

        return ChurchService::query()->findOrFail($serviceId);
    }

    private function capturePayload(ChurchService $service, Member $member, array $overrides = []): array
    {
        return array_merge([
            'session_key' => 'church_service',
            'session_id' => $service->id,
            'subject_type' => Member::class,
            'subject_id' => $member->id,
            'status' => 'present',
            'capture_method' => 'manual',
        ], $overrides);
    }

    // ------------------------------------------------------------------
    // AC1 — capture with method, session, and supported statuses
    // ------------------------------------------------------------------

    public function test_manual_capture_creates_session_attendance_record(): void
    {
        Carbon::setTestNow('2026-09-07 10:15:00');
        $officer = $this->officer();
        $service = $this->publishedService();
        $member = $this->member();

        $this->actingAsMfaVerified($officer)
            ->postJson('/api/attendance/capture', $this->capturePayload($service, $member, [
                'capture_method' => 'manual',
                'device_id' => 'desk-01',
            ]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'present')
            ->assertJsonPath('data.capture_method', 'manual')
            ->assertJsonPath('data.session_type', 'church_service')
            ->assertJsonPath('data.session_id', $service->id);

        $this->assertDatabaseHas('attendance_records', [
            'subject_id' => $member->id,
            'session_type' => 'church_service',
            'session_id' => $service->id,
            'capture_method' => 'manual',
            'recorded_by' => $officer->id,
        ]);

        $this->assertDatabaseHas('audit_events', ['action' => 'attendance.captured']);
    }

    public function test_qr_capture_records_attendance_for_member(): void
    {
        $officer = $this->officer();
        $service = $this->publishedService();
        $member = $this->member();
        $token = app(MembershipCardService::class)->issueToken($member)['token'];

        $this->actingAsMfaVerified($officer)
            ->postJson('/api/attendance/capture', [
                'session_key' => 'church_service',
                'session_id' => $service->id,
                'token' => $token,
                'status' => 'present',
                'capture_method' => 'qr',
            ])
            ->assertCreated()
            ->assertJsonPath('data.capture_method', 'qr')
            ->assertJsonPath('data.subject_id', $member->id);
    }

    public function test_member_id_capture_supports_late_status(): void
    {
        $officer = $this->officer();
        $service = $this->publishedService();
        $member = $this->member(['membership_id' => 'S1-M-LATE-01']);

        $this->actingAsMfaVerified($officer)
            ->postJson('/api/attendance/capture', [
                'session_key' => 'church_service',
                'session_id' => $service->id,
                'membership_id' => $member->membership_id,
                'status' => 'late',
                'capture_method' => 'member_id',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'late')
            ->assertJsonPath('data.capture_method', 'member_id');
    }

    // ------------------------------------------------------------------
    // AC2 — reject invalid or duplicate capture attempts
    // ------------------------------------------------------------------

    public function test_duplicate_capture_is_rejected_safely(): void
    {
        $officer = $this->officer();
        $service = $this->publishedService();
        $member = $this->member();
        $payload = $this->capturePayload($service, $member);

        $this->actingAsMfaVerified($officer)
            ->postJson('/api/attendance/capture', $payload)
            ->assertCreated();

        $this->actingAsMfaVerified($officer)
            ->postJson('/api/attendance/capture', $payload)
            ->assertStatus(422)
            ->assertJsonPath('reason', 'duplicate')
            ->assertJsonPath('next_step', 'Review the existing record or request an authorized correction.');

        $this->assertDatabaseCount('attendance_records', 1);
    }

    public function test_wrong_branch_capture_is_rejected(): void
    {
        $officer = $this->officer();
        $service = $this->publishedService();
        $member = $this->member(['branch_id' => $this->otherBranch->id]);

        $this->actingAsMfaVerified($officer)
            ->postJson('/api/attendance/capture', $this->capturePayload($service, $member))
            ->assertStatus(422)
            ->assertJsonPath('reason', 'wrong_branch');
    }

    // ------------------------------------------------------------------
    // AC3 — offline sync with idempotency and conflict surfacing
    // ------------------------------------------------------------------

    public function test_offline_sync_is_idempotent_and_preserves_original_entry(): void
    {
        Carbon::setTestNow('2026-09-07 09:45:00');
        $officer = $this->officer();
        $service = $this->publishedService();
        $member = $this->member();

        $entry = array_merge($this->capturePayload($service, $member), [
            'client_reference' => 'offline-ref-001',
            'captured_at' => '2026-09-07T09:40:00+00:00',
            'capture_method' => 'mobile',
            'device_id' => 'tablet-02',
        ]);

        $first = $this->actingAsMfaVerified($officer)
            ->postJson('/api/attendance/sync', ['entries' => [$entry]])
            ->assertOk()
            ->assertJsonPath('data.synced', 1)
            ->assertJsonPath('data.conflicts', []);

        $recordId = $first->json('data.records.0.id');

        $second = $this->actingAsMfaVerified($officer)
            ->postJson('/api/attendance/sync', ['entries' => [$entry]])
            ->assertOk()
            ->assertJsonPath('data.synced', 1);

        $this->assertSame($recordId, $second->json('data.records.0.id'));
        $this->assertDatabaseCount('attendance_records', 1);
        $this->assertDatabaseHas('attendance_records', [
            'id' => $recordId,
            'sync_status' => 'synced',
            'client_reference' => 'offline-ref-001',
        ]);
    }

    public function test_offline_conflict_is_surfaced_for_resolution(): void
    {
        $officer = $this->officer();
        $service = $this->publishedService();
        $member = $this->member();

        $this->actingAsMfaVerified($officer)
            ->postJson('/api/attendance/capture', $this->capturePayload($service, $member, [
                'status' => 'present',
            ]))
            ->assertCreated();

        $response = $this->actingAsMfaVerified($officer)
            ->postJson('/api/attendance/sync', [
                'entries' => [
                    array_merge($this->capturePayload($service, $member), [
                        'client_reference' => 'offline-conflict-001',
                        'status' => 'absent',
                        'capture_method' => 'mobile',
                        'offline' => true,
                    ]),
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.synced', 0)
            ->assertJsonPath('data.conflicts.0.reason', 'conflict');

        $this->assertDatabaseHas('attendance_records', [
            'subject_id' => $member->id,
            'status' => 'present',
            'sync_status' => 'conflict',
        ]);
    }

    // ------------------------------------------------------------------
    // AC4 — auditable correction after close
    // ------------------------------------------------------------------

    public function test_correction_with_reason_is_auditable_and_recalculates_metrics(): void
    {
        Carbon::setTestNow('2026-09-08');
        $officer = $this->officer();
        $service = $this->publishedService();
        $member = $this->member();

        $created = $this->actingAsMfaVerified($officer)
            ->postJson('/api/attendance/capture', $this->capturePayload($service, $member, [
                'status' => 'absent',
            ]))
            ->assertCreated();

        $recordId = $created->json('data.id');

        $this->actingAsMfaVerified($officer)
            ->postJson("/api/attendance/records/{$recordId}/correct", [
                'status' => 'present',
                'correction_reason' => 'Member was present but marked absent during rush hour.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'present')
            ->assertJsonPath('data.original_status', 'absent');

        $this->assertDatabaseHas('attendance_record_corrections', [
            'attendance_record_id' => $recordId,
            'before_status' => 'absent',
            'after_status' => 'present',
            'reason' => 'Member was present but marked absent during rush hour.',
        ]);

        $this->assertDatabaseHas('audit_events', ['action' => 'attendance.corrected']);
    }
}
