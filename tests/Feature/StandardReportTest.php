<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\WelfareRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 13.2: trusted standard church reports.
 */
class StandardReportTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'SR-HQ']);
        $this->branch = Organization::create([
            'name' => 'Branch A',
            'type' => 'branch',
            'identifier' => 'SR-A',
            'parent_id' => $hq->id,
        ]);
    }

    /**
     * @param  list<string>  $actions
     */
    private function grant(User $user, array $actions): void
    {
        $role = Role::create(['name' => 'sr_' . $user->id . '_' . substr(md5(implode(',', $actions)), 0, 6)]);
        foreach ($actions as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);
    }

    private function reportUser(array $extra = []): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $this->grant($user, array_merge([
            'reports.standard.read',
            'members.read',
            'attendance.read',
            'teams.read',
            'teams.reports.read',
            'welfare.reports.read',
            'care.cases.read',
            'communications.read',
        ], $extra));

        return $user;
    }

    public function test_authorized_user_runs_membership_report_with_definitions_and_reconciliation(): void
    {
        $user = $this->reportUser();

        Member::create([
            'membership_id' => 'SR-M-001',
            'branch_id' => $this->branch->id,
            'registration_channel' => 'web',
            'first_name' => 'Ada',
            'last_name' => 'Member',
            'lifecycle_status' => 'active',
            'lifecycle_stage' => 'member',
            'consent_data_processing' => true,
            'created_at' => now()->subDays(2),
        ]);

        Member::create([
            'membership_id' => 'SR-M-002',
            'branch_id' => $this->branch->id,
            'registration_channel' => 'web',
            'first_name' => 'Ben',
            'last_name' => 'Member',
            'lifecycle_status' => 'active',
            'lifecycle_stage' => 'convert',
            'consent_data_processing' => true,
            'created_at' => now()->subDays(1),
        ]);

        $catalog = $this->actingAsMfaVerified($user)
            ->getJson('/api/standard-reports/catalog')
            ->assertOk()
            ->json('data');

        $this->assertArrayHasKey('membership', $catalog['reports']);
        $this->assertNotEmpty($catalog['reports']['membership']['definition']);

        $report = $this->actingAsMfaVerified($user)
            ->getJson('/api/standard-reports/membership?branch_id=' . $this->branch->id . '&period_preset=monthly')
            ->assertOk()
            ->json('data');

        $this->assertSame('membership', $report['key']);
        $this->assertNotEmpty($report['definition']);
        $this->assertTrue($report['reconciliation']['reconciled']);
        $this->assertGreaterThanOrEqual(2, $report['reconciliation']['ready_sections']);

        $active = collect($report['sections'])->firstWhere('key', 'active_members');
        $this->assertNotNull($active);
        $this->assertSame('ready', $active['state']);
        $this->assertSame(2, $active['value']);
        $this->assertNotEmpty($active['definition']);
    }

    public function test_empty_period_shows_limitation_and_small_cohort_suppressed_with_support_on_failure(): void
    {
        $user = $this->reportUser();

        $empty = $this->actingAsMfaVerified($user)
            ->getJson('/api/standard-reports/attendance?branch_id=' . $this->branch->id . '&period_preset=weekly')
            ->assertOk()
            ->json('data');

        $this->assertSame('empty', $empty['state']);
        $this->assertNotEmpty($empty['limitations']);
        $attendance = collect($empty['sections'])->first();
        $this->assertNull($attendance['value']);
        $this->assertNotSame(0, $attendance['value']);

        WelfareRequest::create([
            'case_number' => 'WR-SR-001',
            'branch_id' => $this->branch->id,
            'beneficiary_member_id' => Member::create([
                'membership_id' => 'SR-M-W',
                'branch_id' => $this->branch->id,
                'registration_channel' => 'web',
                'first_name' => 'Welfare',
                'last_name' => 'Case',
                'lifecycle_status' => 'active',
                'consent_data_processing' => true,
            ])->id,
            'request_type' => 'food',
            'description' => 'Small cohort case',
            'priority' => 'normal',
            'consent_data_processing' => true,
            'consent_welfare_review' => true,
            'status' => WelfareRequest::STATUS_PENDING_APPROVAL,
            'requested_value' => 100,
            'submitted_at' => now(),
            'created_by' => $user->id,
        ]);

        $welfare = $this->actingAsMfaVerified($user)
            ->getJson('/api/standard-reports/welfare?branch_id=' . $this->branch->id . '&period_preset=monthly')
            ->assertOk()
            ->json('data');

        $open = collect($welfare['sections'])->firstWhere('key', 'welfare_open_cases');
        $this->assertNotNull($open);
        $this->assertTrue($open['suppressed']);
        $this->assertNull($open['value']);
        $this->assertTrue(collect($welfare['limitations'])->contains(fn (array $item) => $item['code'] === 'small_group_suppression'));

        $failed = $this->actingAsMfaVerified($user)
            ->getJson('/api/standard-reports/membership?branch_id=' . $this->branch->id . '&__test_force_failure=1')
            ->assertStatus(500)
            ->json();

        $this->assertSame('calculation_failed', $failed['code']);
        $this->assertNotEmpty($failed['details']['reference']);
        $this->assertTrue($failed['details']['retryable']);
        $this->assertArrayNotHasKey('trace', $failed['details']);
    }
}
