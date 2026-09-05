<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\TeamAttendanceCorrection;
use App\Models\TeamAttendanceRecord;
use App\Models\TeamOccurrence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 5.5: Track Team Attendance and Reliability.
 */
class TeamAttendanceTest extends TestCase
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
        $user = $this->privilegedUser();
        $role = Role::create(['name' => 'team_att_' . $user->id]);
        foreach ([
            'teams.read', 'teams.manage',
            'teams.attendance.read', 'teams.attendance.capture', 'teams.attendance.correct',
        ] as $action) {
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
        return Member::create(array_merge([
            'membership_id' => 'S55-M-' . uniqid(),
            'branch_id' => $this->branch->id,
            'registration_channel' => 'web',
            'first_name' => 'Team',
            'last_name' => 'Volunteer',
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

    private function createOccurrence(User $leader, int $teamId, array $overrides = []): int
    {
        $payload = array_merge([
            'occurrence_type' => 'rehearsal',
            'title' => 'Thursday rehearsal',
            'occurrence_date' => now()->toDateString(),
            'start_time' => '18:00',
            'end_time' => '20:00',
        ], $overrides);

        return $this->actingAsMfaVerified($leader)
            ->postJson("/api/service-teams/{$teamId}/occurrences", $payload)
            ->assertCreated()
            ->json('data.id');
    }

    // ------------------------------------------------------------------
    // AC1 — independent team attendance capture
    // ------------------------------------------------------------------

    public function test_leader_captures_team_attendance_statuses_for_occurrence(): void
    {
        $leader = $this->leader();
        $teamLeader = $this->teamLeader();
        $teamId = $this->createActiveTeam($leader, $teamLeader);
        $memberA = $this->memberRecord();
        $memberB = $this->memberRecord();
        $memberC = $this->memberRecord();
        $memberD = $this->memberRecord();

        $occurrenceId = $this->createOccurrence($leader, $teamId);

        $this->actingAsMfaVerified($leader)
            ->postJson("/api/team-occurrences/{$occurrenceId}/attendance", [
                'entries' => [
                    ['member_id' => $memberA->id, 'status' => 'present'],
                    ['member_id' => $memberB->id, 'status' => 'absent'],
                    ['member_id' => $memberC->id, 'status' => 'excused'],
                    ['member_id' => $memberD->id, 'status' => 'late'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.created', 4);

        $this->assertDatabaseHas('team_attendance_records', [
            'team_occurrence_id' => $occurrenceId,
            'member_id' => $memberA->id,
            'status' => TeamAttendanceRecord::STATUS_PRESENT,
        ]);

        $this->assertDatabaseHas('team_occurrences', [
            'id' => $occurrenceId,
            'status' => TeamOccurrence::STATUS_COMPLETED,
        ]);
    }

    public function test_analysis_does_not_use_general_gathering_attendance(): void
    {
        $leader = $this->leader();
        $teamLeader = $this->teamLeader();
        $teamId = $this->createActiveTeam($leader, $teamLeader);
        $member = $this->memberRecord();

        AttendanceRecord::create([
            'subject_type' => Member::class,
            'subject_id' => $member->id,
            'branch_id' => $this->branch->id,
            'service_type' => 'sunday_service',
            'gathering_date' => now()->toDateString(),
            'status' => 'present',
            'team_id' => $teamId,
            'capture_method' => 'manual',
            'sync_status' => 'synced',
            'recorded_by' => $leader->id,
        ]);

        $this->assertDatabaseHas('attendance_records', [
            'team_id' => $teamId,
            'subject_id' => $member->id,
        ]);

        $this->actingAsMfaVerified($leader)
            ->getJson('/api/service-teams/' . $teamId . '/attendance-analysis?' . http_build_query([
                'from_date' => now()->subMonths(3)->toDateString(),
                'to_date' => now()->toDateString(),
            ]))
            ->assertOk()
            ->assertJsonPath('data.uses_gathering_attendance', false)
            ->assertJsonPath('data.totals.records', 0)
            ->assertJsonPath('data.gathering_attendance_records_in_scope', 1);
    }

    // ------------------------------------------------------------------
    // AC2 — reliability analysis and auditable corrections
    // ------------------------------------------------------------------

    public function test_analysis_calculates_totals_trend_reliability_and_follow_up(): void
    {
        $leader = $this->leader();
        $teamLeader = $this->teamLeader();
        $teamId = $this->createActiveTeam($leader, $teamLeader);
        $reliable = $this->memberRecord(['membership_id' => 'S55-REL-' . uniqid()]);
        $unreliable = $this->memberRecord(['membership_id' => 'S55-UNR-' . uniqid()]);

        $dates = ['2026-08-01', '2026-08-08', '2026-08-15'];

        foreach ($dates as $index => $date) {
            $occurrenceId = $this->createOccurrence($leader, $teamId, [
                'title' => 'Rehearsal ' . ($index + 1),
                'occurrence_date' => $date,
            ]);

            $this->actingAsMfaVerified($leader)
                ->postJson("/api/team-occurrences/{$occurrenceId}/attendance", [
                    'entries' => [
                        ['member_id' => $reliable->id, 'status' => 'present'],
                        ['member_id' => $unreliable->id, 'status' => 'absent'],
                    ],
                ])
                ->assertOk();
        }

        $this->assertSame(6, TeamAttendanceRecord::query()->count());

        $this->actingAsMfaVerified($leader)
            ->getJson('/api/service-teams/' . $teamId . '/attendance-analysis?' . http_build_query([
                'from_date' => '2026-08-01',
                'to_date' => '2026-08-31',
            ]))
            ->assertOk()
            ->assertJsonPath('data.totals.records', 6)
            ->assertJsonStructure(['data' => ['totals', 'members', 'trend', 'members_requiring_follow_up']]);

        $analysis = $this->actingAsMfaVerified($leader)
            ->getJson('/api/service-teams/' . $teamId . '/attendance-analysis?' . http_build_query([
                'from_date' => '2026-08-01',
                'to_date' => '2026-08-31',
            ]))
            ->json('data.members');

        $reliableRow = collect($analysis)->firstWhere('member_id', $reliable->id);
        $unreliableRow = collect($analysis)->firstWhere('member_id', $unreliable->id);

        $this->assertEquals(100.0, $reliableRow['attendance_percent']);
        $this->assertSame('reliable', $reliableRow['reliability']);
        $this->assertSame('needs_follow_up', $unreliableRow['reliability']);
        $this->assertNotEmpty(collect($analysis)->filter(fn ($row) => $row['member_id'] === $unreliable->id));
    }

    public function test_correction_recalculates_analysis_with_audit_trail(): void
    {
        $leader = $this->leader();
        $teamLeader = $this->teamLeader();
        $teamId = $this->createActiveTeam($leader, $teamLeader);
        $member = $this->memberRecord();

        $dates = ['2026-08-01', '2026-08-08', '2026-08-15'];

        foreach ($dates as $date) {
            $occurrenceId = $this->createOccurrence($leader, $teamId, [
                'occurrence_date' => $date,
            ]);

            $this->actingAsMfaVerified($leader)
                ->postJson("/api/team-occurrences/{$occurrenceId}/attendance", [
                    'entries' => [['member_id' => $member->id, 'status' => 'absent']],
                ])
                ->assertOk();
        }

        $before = $this->actingAsMfaVerified($leader)
            ->getJson('/api/service-teams/' . $teamId . '/attendance-analysis?' . http_build_query([
                'from_date' => '2026-08-01',
                'to_date' => '2026-08-31',
            ]))
            ->json('data.members.0.attendance_percent');

        $this->assertEquals(0.0, $before);

        $record = TeamAttendanceRecord::query()->where('member_id', $member->id)->oldest('id')->firstOrFail();

        $this->actingAsMfaVerified($leader)
            ->postJson("/api/team-attendance/{$record->id}/correct", [
                'status' => 'present',
                'reason' => 'Marked absent in error during roll call.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', TeamAttendanceRecord::STATUS_PRESENT);

        $this->assertDatabaseHas('team_attendance_corrections', [
            'team_attendance_record_id' => $record->id,
            'before_status' => TeamAttendanceRecord::STATUS_ABSENT,
            'after_status' => TeamAttendanceRecord::STATUS_PRESENT,
        ]);

        $after = $this->actingAsMfaVerified($leader)
            ->getJson('/api/service-teams/' . $teamId . '/attendance-analysis?' . http_build_query([
                'from_date' => '2026-08-01',
                'to_date' => '2026-08-31',
            ]))
            ->json('data.members.0.attendance_percent');

        $this->assertGreaterThan($before, $after);
        $this->assertDatabaseHas('audit_events', ['action' => 'team_attendance.corrected']);
    }
}
