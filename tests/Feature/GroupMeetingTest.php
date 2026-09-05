<?php

namespace Tests\Feature;

use App\Models\ChurchGroup;
use App\Models\ChurchGroupMeeting;
use App\Models\ChurchGroupMeetingAttendance;
use App\Models\FollowUp;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 6.2: Run Group Meetings and Follow-Up.
 */
class GroupMeetingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'IDX-HQ']);
        $this->branch = Organization::create(['name' => 'Branch A', 'type' => 'branch', 'identifier' => 'IDX-A', 'parent_id' => $hq->id]);
    }

    private function meetingManager(array $extraPermissions = []): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $role = Role::create(['name' => 'group_meeting_mgr_' . $user->id]);
        foreach (array_merge([
            'groups.read',
            'groups.manage',
            'groups.meetings.read',
            'groups.meetings.manage',
            'groups.sensitive.read',
            'followups.manage',
        ], $extraPermissions) as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);

        return $user;
    }

    private function meetingReader(): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $role = Role::create(['name' => 'group_meeting_reader_' . $user->id]);
        foreach ([
            'groups.read',
            'groups.meetings.read',
        ] as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);

        return $user;
    }

    private function leaderUser(): User
    {
        return User::factory()->create([
            'roles' => ['member'],
            'has_mfa_enrolled' => true,
            'branch_id' => $this->branch->id,
        ]);
    }

    private function member(string $suffix, array $attrs = []): Member
    {
        return Member::create(array_merge([
            'membership_id' => 'S62-' . $suffix,
            'branch_id' => $this->branch->id,
            'registration_channel' => 'web',
            'first_name' => 'Group',
            'last_name' => 'Member' . $suffix,
            'consent_data_processing' => true,
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
            'date_of_birth' => '2000-01-01',
        ], $attrs));
    }

    private function createActiveGroup(User $manager): ChurchGroup
    {
        $leader = $this->leaderUser();

        $groupId = $this->actingAsMfaVerified($manager)
            ->postJson('/api/groups', [
                'name' => 'Cell Group A',
                'branch_id' => $this->branch->id,
                'group_type' => 'cell',
                'description' => 'Weekly cell.',
                'leaders' => [['user_id' => $leader->id, 'role' => 'lead']],
                'meeting_pattern' => [
                    'frequency' => 'weekly',
                    'day' => 'wednesday',
                    'start_time' => '19:00',
                    'end_time' => '21:00',
                    'venue' => 'Room 1',
                ],
                'capacity' => 12,
                'eligibility' => ['min_age' => 18, 'lifecycle_stages' => ['member'], 'requires_consent' => true],
                'communication_settings' => ['allow_member_posts' => true],
                'reporting_settings' => ['requires_weekly_report' => true],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($manager)
            ->postJson("/api/groups/{$groupId}/activate")
            ->assertOk();

        return ChurchGroup::query()->findOrFail($groupId);
    }

    private function scheduleMeeting(User $manager, ChurchGroup $group, array $overrides = []): int
    {
        return $this->actingAsMfaVerified($manager)
            ->postJson("/api/groups/{$group->id}/meetings", array_merge([
                'title' => 'Weekly fellowship',
                'meeting_type' => 'meeting',
                'scheduled_at' => now()->addDay()->toIso8601String(),
                'location' => 'Room 1',
            ], $overrides))
            ->assertCreated()
            ->json('data.id');
    }

    // ------------------------------------------------------------------
    // AC1 — schedule, record activity, confidentiality
    // ------------------------------------------------------------------

    public function test_manager_schedules_and_records_group_meeting(): void
    {
        $manager = $this->meetingManager();
        $group = $this->createActiveGroup($manager);
        $member = $this->member('REC');

        $meetingId = $this->scheduleMeeting($manager, $group);

        $this->actingAsMfaVerified($manager)
            ->postJson("/api/group-meetings/{$meetingId}/record", [
                'notes' => 'Great discussion on prayer.',
                'sensitive_notes' => 'Pastoral concern shared privately.',
                'prayer_needs' => [[
                    'subject' => 'Health concern',
                    'detail' => 'Needs hospital visit support',
                    'classification' => 'pastoral',
                    'member_id' => $member->id,
                ]],
                'actions' => [[
                    'title' => 'Visit member in hospital',
                    'assignee_id' => $manager->id,
                    'due_date' => now()->addDays(2)->toDateString(),
                    'status' => 'pending',
                    'member_id' => $member->id,
                ]],
                'report_fields' => ['attendance_count' => 1, 'new_visitors' => 0],
                'attendance' => [[
                    'member_id' => $member->id,
                    'status' => 'present',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', ChurchGroupMeeting::STATUS_COMPLETED)
            ->assertJsonPath('data.sensitive_notes', 'Pastoral concern shared privately.')
            ->assertJsonPath('data.attendance.0.status', 'present');

        $this->assertDatabaseHas('church_group_meetings', [
            'id' => $meetingId,
            'church_group_id' => $group->id,
            'status' => ChurchGroupMeeting::STATUS_COMPLETED,
        ]);
        $this->assertDatabaseHas('church_group_meeting_attendance', [
            'church_group_meeting_id' => $meetingId,
            'member_id' => $member->id,
            'status' => ChurchGroupMeetingAttendance::STATUS_PRESENT,
        ]);
    }

    public function test_sensitive_notes_are_hidden_without_permission(): void
    {
        $manager = $this->meetingManager();
        $reader = $this->meetingReader();

        $group = $this->createActiveGroup($manager);
        $member = $this->member('SENS');
        $meetingId = $this->scheduleMeeting($manager, $group);

        $this->actingAsMfaVerified($manager)
            ->postJson("/api/group-meetings/{$meetingId}/record", [
                'notes' => 'General notes',
                'sensitive_notes' => 'Confidential pastoral note',
                'prayer_needs' => [[
                    'subject' => 'Marriage support',
                    'detail' => 'Private details',
                    'classification' => 'restricted',
                    'member_id' => $member->id,
                ]],
                'attendance' => [['member_id' => $member->id, 'status' => 'present']],
            ])
            ->assertOk();

        $this->actingAsMfaVerified($reader)
            ->getJson("/api/group-meetings/{$meetingId}")
            ->assertOk()
            ->assertJsonPath('data.sensitive_notes', null)
            ->assertJsonPath('data.sensitive_notes_restricted', true)
            ->assertJsonPath('data.prayer_needs.0.restricted', true);
    }

    // ------------------------------------------------------------------
    // AC2 — follow-up triggers and dashboard totals
    // ------------------------------------------------------------------

    public function test_evaluate_follow_ups_creates_scoped_tasks(): void
    {
        $manager = $this->meetingManager();
        $group = $this->createActiveGroup($manager);
        $absentMember = $this->member('ABS');
        $visitor = Visitor::create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Guest',
            'last_name' => 'Visitor',
            'email' => 'guest@example.com',
            'original_source' => 'group',
            'first_visit_at' => now(),
            'created_by' => $manager->id,
        ]);

        $firstMeetingId = $this->scheduleMeeting($manager, $group, ['title' => 'Week 1']);
        $this->actingAsMfaVerified($manager)
            ->postJson("/api/group-meetings/{$firstMeetingId}/record", [
                'attendance' => [['member_id' => $absentMember->id, 'status' => 'absent']],
            ])
            ->assertOk();

        $secondMeetingId = $this->scheduleMeeting($manager, $group, [
            'title' => 'Week 2',
            'scheduled_at' => now()->addDays(2)->toIso8601String(),
        ]);
        $this->actingAsMfaVerified($manager)
            ->postJson("/api/group-meetings/{$secondMeetingId}/record", [
                'attendance' => [
                    ['member_id' => $absentMember->id, 'status' => 'absent'],
                    ['visitor_id' => $visitor->id, 'status' => 'present'],
                ],
            ])
            ->assertOk();

        $result = $this->actingAsMfaVerified($manager)
            ->postJson("/api/group-meetings/{$secondMeetingId}/evaluate-follow-ups", [
                'assignee_id' => $manager->id,
            ])
            ->assertOk()
            ->json('data');

        $this->assertGreaterThanOrEqual(2, $result['created']);

        $this->assertDatabaseHas('follow_ups', [
            'person_type' => Member::class,
            'person_id' => $absentMember->id,
            'source_type' => 'group_meeting',
            'source_reference_id' => $secondMeetingId,
        ]);
        $this->assertDatabaseHas('follow_ups', [
            'person_type' => Visitor::class,
            'person_id' => $visitor->id,
            'source_type' => 'group_meeting',
            'source_reference_id' => $secondMeetingId,
        ]);
    }

    public function test_attendance_correction_and_completed_actions_update_dashboard(): void
    {
        $manager = $this->meetingManager();
        $group = $this->createActiveGroup($manager);
        $member = $this->member('DASH');

        $meetingId = $this->scheduleMeeting($manager, $group);
        $this->actingAsMfaVerified($manager)
            ->postJson("/api/group-meetings/{$meetingId}/record", [
                'actions' => [[
                    'title' => 'Call absent member',
                    'status' => 'completed',
                    'member_id' => $member->id,
                ]],
                'attendance' => [['member_id' => $member->id, 'status' => 'absent']],
            ])
            ->assertOk();

        $attendanceId = ChurchGroupMeetingAttendance::query()
            ->where('church_group_meeting_id', $meetingId)
            ->value('id');

        $this->actingAsMfaVerified($manager)
            ->postJson("/api/group-meeting-attendance/{$attendanceId}/correct", [
                'status' => 'present',
                'correction_reason' => 'Member arrived late and was marked incorrectly.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'present');

        $dashboard = $this->actingAsMfaVerified($manager)
            ->getJson("/api/groups/{$group->id}/meeting-dashboard")
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $dashboard['completed_meetings']);
        $this->assertSame(1, $dashboard['corrected_attendance_records']);
        $this->assertEquals(100.0, $dashboard['attendance_rate']);
        $this->assertSame(0, $dashboard['pending_actions']);
        $this->assertSame(1, $dashboard['completed_actions']);
    }

    public function test_unauthorized_user_cannot_record_meetings(): void
    {
        $manager = $this->meetingManager();
        $outsider = User::factory()->create([
            'branch_id' => $this->branch->id,
            'has_mfa_enrolled' => true,
        ]);
        $group = $this->createActiveGroup($manager);
        $meetingId = $this->scheduleMeeting($manager, $group);

        $this->actingAsMfaVerified($outsider)
            ->postJson("/api/group-meetings/{$meetingId}/record", [
                'attendance' => [['person_name' => 'Guest', 'status' => 'present']],
            ])
            ->assertForbidden();
    }
}
