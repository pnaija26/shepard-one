<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\ChurchGroup;
use App\Models\ChurchGroupMembership;
use App\Models\ChurchService;
use App\Models\Household;
use App\Models\HouseholdMembership;
use App\Models\Member;
use App\Models\MemberNotification;
use App\Models\Organization;
use App\Models\PrayerRequest;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\WelfareRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 12.2: member web and mobile dashboard.
 */
class MemberDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $hq = Organization::create([
            'name' => 'HQ',
            'type' => 'headquarters',
            'identifier' => 'MD-HQ',
            'primary_contact' => ['name' => 'Pastor Grace', 'email' => 'pastor@church.test'],
        ]);
        $this->branch = Organization::create([
            'name' => 'Central Assembly',
            'type' => 'branch',
            'identifier' => 'MD-A',
            'parent_id' => $hq->id,
            'primary_contact' => ['name' => 'Pastor James', 'email' => 'james@church.test'],
        ]);
    }

    private function grant(User $user, array $actions): void
    {
        $role = Role::create(['name' => 'member_dash_' . $user->id]);
        foreach ($actions as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);
    }

    private function memberUser(?array $permissions = null): array
    {
        $user = User::factory()->create([
            'branch_id' => $this->branch->id,
            'has_mfa_enrolled' => false,
        ]);

        $member = Member::create([
            'user_id' => $user->id,
            'membership_id' => 'MD-' . $user->id,
            'branch_id' => $this->branch->id,
            'registration_channel' => 'web',
            'first_name' => 'Ada',
            'last_name' => 'Member',
            'preferred_name' => 'Ada',
            'membership_status' => 'active',
            'lifecycle_status' => 'active',
            'consent_data_processing' => true,
        ]);

        $defaults = [
            'notifications.inbox',
            'payments.giving.self',
            'welfare.requests.read.self',
            'welfare.requests.submit.self',
            'prayer.requests.read.self',
            'prayer.requests.submit.self',
        ];

        $this->grant($user, $permissions ?? $defaults);

        return [$user, $member];
    }

    public function test_member_dashboard_aggregates_permitted_sections_and_hides_unauthorized_data(): void
    {
        [$user, $member] = $this->memberUser();

        $household = Household::create([
            'branch_id' => $this->branch->id,
            'name' => 'Ada Household',
            'head_member_id' => $member->id,
            'created_by' => $user->id,
        ]);
        HouseholdMembership::create([
            'household_id' => $household->id,
            'member_id' => $member->id,
            'relationship_type' => HouseholdMembership::TYPE_HEAD,
            'started_at' => now(),
            'created_by' => $user->id,
        ]);

        ChurchService::create([
            'branch_id' => $this->branch->id,
            'service_type' => 'sunday_service',
            'title' => 'Sunday Celebration',
            'service_date' => now()->addDays(3)->toDateString(),
            'start_time' => '09:00',
            'venue' => 'Main Auditorium',
            'status' => ChurchService::STATUS_PUBLISHED,
            'published_at' => now(),
            'created_by' => $user->id,
        ]);

        $group = ChurchGroup::create([
            'branch_id' => $this->branch->id,
            'name' => 'Young Adults',
            'group_type' => 'fellowship',
            'status' => 'active',
            'leaders' => [],
            'meeting_pattern' => ['frequency' => 'weekly', 'day' => 'sunday'],
            'eligibility' => ['lifecycle_stages' => ['member']],
            'communication_settings' => [],
            'reporting_settings' => [],
            'created_by' => $user->id,
        ]);
        ChurchGroupMembership::create([
            'church_group_id' => $group->id,
            'member_id' => $member->id,
            'role' => ChurchGroupMembership::ROLE_MEMBER,
            'status' => ChurchGroupMembership::STATUS_ACTIVE,
            'effective_from' => now()->toDateString(),
            'assigned_by' => $user->id,
        ]);

        AttendanceRecord::create([
            'subject_type' => Member::class,
            'subject_id' => $member->id,
            'branch_id' => $this->branch->id,
            'service_type' => 'sunday_service',
            'gathering_date' => now()->subDays(7)->toDateString(),
            'status' => 'present',
            'capture_method' => 'manual',
            'captured_at' => now()->subDays(7),
            'recorded_by' => $user->id,
        ]);

        WelfareRequest::create([
            'case_number' => 'WR-MD-001',
            'branch_id' => $this->branch->id,
            'beneficiary_member_id' => $member->id,
            'requester_member_id' => $member->id,
            'requester_user_id' => $user->id,
            'request_type' => 'food',
            'description' => 'Support needed',
            'priority' => 'normal',
            'consent_data_processing' => true,
            'consent_welfare_review' => true,
            'status' => WelfareRequest::STATUS_SUBMITTED,
            'submitted_at' => now(),
            'created_by' => $user->id,
        ]);

        PrayerRequest::create([
            'reference' => 'PR-MD-001',
            'branch_id' => $this->branch->id,
            'requester_member_id' => $member->id,
            'requester_user_id' => $user->id,
            'category' => 'health',
            'priority' => 'normal',
            'request_body' => encrypt('Please pray for healing'),
            'confidentiality' => PrayerRequest::SCOPE_PRIVATE,
            'data_classification' => 'restricted_sensitive',
            'consent_prayer_processing' => true,
            'status' => PrayerRequest::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);

        MemberNotification::create([
            'user_id' => $user->id,
            'member_id' => $member->id,
            'type' => 'team_roster.published',
            'category' => 'assignments',
            'message' => 'You are scheduled this Sunday.',
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/me/dashboard')
            ->assertOk()
            ->json('data');

        $this->assertTrue($response['member_linked']);
        $this->assertArrayHasKey('session_policy', $response);
        $this->assertNotEmpty($response['quick_actions']);

        $sections = collect($response['sections'])->keyBy('key');

        $this->assertSame('ready', $sections['profile']['state']);
        $this->assertSame('Pastor James', $sections['profile']['summary']['pastor']);
        $this->assertSame('Central Assembly', $sections['profile']['summary']['branch']);

        $this->assertSame('ready', $sections['family']['state']);
        $this->assertSame('Ada Household', $sections['family']['summary']['household_name']);

        $this->assertSame('ready', $sections['schedule']['state']);
        $this->assertGreaterThanOrEqual(1, $sections['schedule']['summary']['upcoming_services']);

        $this->assertSame('ready', $sections['groups']['state']);
        $this->assertSame(1, $sections['groups']['summary']['active_groups']);

        $this->assertSame('ready', $sections['attendance']['state']);
        $this->assertSame('ready', $sections['giving']['state']);
        $this->assertSame('ready', $sections['welfare']['state']);
        $this->assertSame('ready', $sections['messages']['state']);
        $this->assertSame(1, $sections['messages']['summary']['unread_count']);
        $this->assertSame('ready', $sections['prayer']['state']);
        $this->assertSame('ready', $sections['care']['state']);

        // Staff-only care listing must not leak when member lacks care.cases.read
        $this->assertArrayNotHasKey('staff_care_access', $sections['care']['summary'] ?? []);

        // Unauthorized giving section omits sensitive summary fields
        [$restrictedUser] = $this->memberUser([
            'notifications.inbox',
            'welfare.requests.read.self',
            'prayer.requests.read.self',
        ]);

        $givingSection = collect(
            $this->actingAs($restrictedUser)->getJson('/api/me/dashboard')->json('data.sections')
        )->firstWhere('key', 'giving');

        $this->assertSame('unauthorized', $givingSection['state']);
        $this->assertFalse($givingSection['available']);
        $this->assertSame([], $givingSection['summary']);
    }

    public function test_unlinked_user_gets_unavailable_member_sections_and_session_policy_metadata(): void
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id, 'has_mfa_enrolled' => false]);
        $this->grant($user, ['notifications.inbox']);

        MemberNotification::create([
            'user_id' => $user->id,
            'member_id' => Member::create([
                'membership_id' => 'MD-UNLINKED',
                'branch_id' => $this->branch->id,
                'registration_channel' => 'web',
                'first_name' => 'Ghost',
                'last_name' => 'User',
                'consent_data_processing' => true,
                'lifecycle_status' => 'active',
            ])->id,
            'type' => 'announcement.posted',
            'category' => 'announcements',
            'message' => 'Welcome to ShepardOne.',
        ]);

        $data = $this->actingAs($user)
            ->getJson('/api/me/dashboard?device=mobile')
            ->assertOk()
            ->json('data');

        $this->assertFalse($data['member_linked']);
        $this->assertSame('mobile', $data['device']);
        $this->assertTrue($data['session_policy']['clear_cache_on_logout']);
        $this->assertContains('giving', $data['session_policy']['sensitive_sections']);

        $profile = collect($data['sections'])->firstWhere('key', 'profile');
        $this->assertSame('unavailable', $profile['state']);
        $this->assertFalse($profile['available']);

        $messages = collect($data['sections'])->firstWhere('key', 'messages');
        $this->assertSame('ready', $messages['state']);
        $this->assertSame(1, $messages['summary']['unread_count']);
    }
}
