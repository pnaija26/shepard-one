<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\CareCase;
use App\Models\Contribution;
use App\Models\Member;
use App\Models\MemberMilestone;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\Visitor;
use App\Models\WelfareRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 12.5: HQ leadership consolidated dashboard.
 */
class HqLeadershipDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Organization $hq;

    private Organization $branchA;

    private Organization $branchB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'HQ-MAIN']);
        $this->branchA = Organization::create([
            'name' => 'Branch Alpha',
            'type' => 'branch',
            'identifier' => 'HQ-A',
            'parent_id' => $this->hq->id,
        ]);
        $this->branchB = Organization::create([
            'name' => 'Branch Beta',
            'type' => 'branch',
            'identifier' => 'HQ-B',
            'parent_id' => $this->hq->id,
        ]);
    }

    /**
     * @param  list<string>  $actions
     */
    private function hqLeader(array $actions = []): User
    {
        $user = $this->privilegedUser(['branch_id' => null]);
        $role = Role::create(['name' => 'hq_leader_' . $user->id]);

        foreach (array_merge([
            'hq.dashboard.read',
            'organizations.read',
            'members.read',
            'visitors.read',
            'members.lifecycle.read',
            'attendance.read',
            'teams.read',
            'volunteers.read',
            'welfare.reports.read',
            'care.cases.read',
            'events.read',
            'payments.giving.reports',
            'followups.read',
        ], $actions) as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }

        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);

        return $user;
    }

    private function seedBranchData(Organization $branch, User $actor, int $memberCount = 10): Member
    {
        $member = Member::create([
            'membership_id' => 'HQ-' . $branch->identifier . '-001',
            'branch_id' => $branch->id,
            'registration_channel' => 'web',
            'first_name' => 'Leader',
            'last_name' => 'Seed',
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
            'consent_data_processing' => true,
            'created_at' => now()->subDays(3),
        ]);

        for ($i = 0; $i < $memberCount - 1; $i++) {
            Member::create([
                'membership_id' => 'HQ-' . $branch->identifier . '-' . ($i + 2),
                'branch_id' => $branch->id,
                'registration_channel' => 'web',
                'first_name' => 'Member',
                'last_name' => (string) $i,
                'lifecycle_status' => 'active',
                'consent_data_processing' => true,
                'created_at' => now()->subDays(2),
            ]);
        }

        Visitor::create([
            'branch_id' => $branch->id,
            'first_name' => 'Guest',
            'last_name' => $branch->identifier,
            'original_source' => 'service',
            'first_visit_at' => now()->subDay(),
            'created_by' => $actor->id,
        ]);

        AttendanceRecord::create([
            'subject_type' => Member::class,
            'subject_id' => $member->id,
            'branch_id' => $branch->id,
            'service_type' => 'sunday_service',
            'gathering_date' => now()->subDays(2)->toDateString(),
            'status' => 'present',
            'capture_method' => 'manual',
            'captured_at' => now()->subDays(2),
            'recorded_by' => $actor->id,
        ]);

        MemberMilestone::create([
            'member_id' => $member->id,
            'type' => 'baptism',
            'occurred_on' => now()->subDays(5)->toDateString(),
            'active' => true,
            'created_by' => $actor->id,
        ]);

        Contribution::create([
            'reference' => 'HQ-GIV-' . $branch->identifier,
            'provider' => 'manual',
            'source_type' => Contribution::SOURCE_MANUAL,
            'provider_payment_reference' => 'REF-' . $branch->identifier,
            'payment_reference' => 'REF-' . $branch->identifier,
            'status' => Contribution::STATUS_SUCCEEDED,
            'amount_cents' => 10000,
            'currency' => 'USD',
            'category' => 'tithe',
            'branch_id' => $branch->id,
            'member_id' => $member->id,
            'payer_linked' => true,
            'reconciliation_status' => Contribution::RECON_RECONCILED,
            'receipt_eligible' => true,
            'occurred_at' => now()->subDays(4),
            'provider_evidence' => ['source' => 'test'],
        ]);

        return $member;
    }

    public function test_hq_dashboard_consolidates_kpis_with_definitions_and_branch_comparison(): void
    {
        $leader = $this->hqLeader();
        $this->seedBranchData($this->branchA, $leader, 10);
        $this->seedBranchData($this->branchB, $leader, 8);

        $data = $this->actingAsMfaVerified($leader)
            ->getJson('/api/me/hq-dashboard')
            ->assertOk()
            ->json('data');

        $this->assertSame('hq_leadership', $data['layout']);
        $this->assertSame('church_wide', $data['scope']);
        $this->assertNotEmpty($data['definitions']['members']);
        $this->assertNotEmpty($data['definitions']['baptisms']);
        $this->assertArrayHasKey('disclosure_policy', $data);

        $metrics = $data['metrics'];
        $this->assertSame('ready', $metrics['members']['state']);
        $this->assertGreaterThanOrEqual(18, $metrics['members']['total']);
        $this->assertSame('ready', $metrics['baptisms']['state']);
        $this->assertGreaterThanOrEqual(2, $metrics['baptisms']['total']);
        $this->assertSame('ready', $metrics['giving']['state']);
        $this->assertGreaterThanOrEqual(20000, $metrics['giving']['total']);

        $comparison = $data['branch_comparison'];
        $this->assertSame('ready', $comparison['state']);
        $this->assertCount(2, $comparison['branches']);
        $alpha = collect($comparison['branches'])->firstWhere('identifier', 'HQ-A');
        $this->assertNotNull($alpha);
        $this->assertSame(10, $alpha['metrics']['members']['value']);

        $drill = $this->actingAsMfaVerified($leader)
            ->getJson('/api/me/hq-dashboard/drill-down/baptisms?branch_id=' . $this->branchA->id)
            ->assertOk()
            ->json('data');

        $this->assertGreaterThanOrEqual(1, $drill['widget_total']);
        $this->assertGreaterThanOrEqual(1, $drill['record_count']);
        $this->assertNotEmpty($drill['definition']);
    }

    public function test_small_group_suppression_hides_sensitive_branch_counts_and_unauthorized_drill_down_forbidden(): void
    {
        $leader = $this->hqLeader();
        $member = $this->seedBranchData($this->branchA, $leader, 3);

        CareCase::create([
            'case_number' => 'CC-SMALL-001',
            'branch_id' => $this->branchA->id,
            'beneficiary_member_id' => $member->id,
            'category' => 'bereavement',
            'description' => 'Sensitive',
            'priority' => 'high',
            'status' => CareCase::STATUS_OPEN,
            'consent_basis' => 'member_request',
            'confidentiality' => 'assigned_only',
            'data_classification' => 'restricted_sensitive',
            'assigned_officer_id' => $leader->id,
            'created_by' => $leader->id,
            'updated_by' => $leader->id,
        ]);

        WelfareRequest::create([
            'case_number' => 'WR-SMALL-001',
            'branch_id' => $this->branchA->id,
            'beneficiary_member_id' => $member->id,
            'request_type' => 'food',
            'description' => 'Small cohort',
            'priority' => 'normal',
            'consent_data_processing' => true,
            'consent_welfare_review' => true,
            'status' => WelfareRequest::STATUS_SUBMITTED,
            'submitted_at' => now(),
            'created_by' => $leader->id,
        ]);

        $comparison = $this->actingAsMfaVerified($leader)
            ->getJson('/api/me/hq-dashboard')
            ->json('data.branch_comparison');

        $alpha = collect($comparison['branches'])->firstWhere('identifier', 'HQ-A');
        $this->assertTrue($alpha['metrics']['care']['suppressed']);
        $this->assertNull($alpha['metrics']['care']['value']);
        $this->assertSame('small_group_suppression', $alpha['metrics']['care']['reason']);
        $this->assertTrue($alpha['metrics']['welfare']['suppressed']);

        $restricted = $this->hqLeader();
        $role = Role::query()->where('name', 'hq_leader_' . $restricted->id)->firstOrFail();
        RolePermission::query()->where('role_id', $role->id)->where('action', 'payments.giving.reports')->delete();

        $metrics = $this->actingAsMfaVerified($restricted)
            ->getJson('/api/me/hq-dashboard')
            ->json('data.metrics');

        $this->assertSame('unauthorized', $metrics['giving']['state']);
        $this->assertArrayNotHasKey('total', $metrics['giving']);

        $this->actingAsMfaVerified($restricted)
            ->getJson('/api/me/hq-dashboard/drill-down/giving')
            ->assertForbidden();
    }
}
