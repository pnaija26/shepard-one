<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\CareCase;
use App\Models\Contribution;
use App\Models\FollowUp;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\Visitor;
use App\Models\VolunteerProfile;
use App\Models\WelfareRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 12.4: branch administrator web and mobile dashboard.
 */
class BranchAdministratorDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Organization $hq;

    private Organization $branch;

    private Organization $otherBranch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'BA-HQ']);
        $this->branch = Organization::create([
            'name' => 'Branch Alpha',
            'type' => 'branch',
            'identifier' => 'BA-A',
            'parent_id' => $this->hq->id,
        ]);
        $this->otherBranch = Organization::create([
            'name' => 'Branch Beta',
            'type' => 'branch',
            'identifier' => 'BA-B',
            'parent_id' => $this->hq->id,
        ]);
    }

  /**
     * @param  list<string>  $actions
     */
    private function branchAdmin(array $actions = []): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $role = Role::create(['name' => 'branch_admin_' . $user->id]);

        foreach (array_merge([
            'branch.dashboard.read',
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

    private function seedBranchMetrics(User $admin): Member
    {
        $member = Member::create([
            'membership_id' => 'BA-M-001',
            'branch_id' => $this->branch->id,
            'registration_channel' => 'web',
            'first_name' => 'Grace',
            'last_name' => 'Member',
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
            'consent_data_processing' => true,
            'created_at' => now()->subDays(5),
        ]);

        Member::create([
            'membership_id' => 'BA-C-001',
            'branch_id' => $this->branch->id,
            'registration_channel' => 'web',
            'first_name' => 'Sam',
            'last_name' => 'Convert',
            'lifecycle_stage' => 'convert',
            'lifecycle_status' => 'active',
            'consent_data_processing' => true,
        ]);

        Visitor::create([
            'branch_id' => $this->branch->id,
            'first_name' => 'New',
            'last_name' => 'Visitor',
            'original_source' => 'service',
            'first_visit_at' => now()->subDays(2),
            'created_by' => $admin->id,
        ]);

        AttendanceRecord::create([
            'subject_type' => Member::class,
            'subject_id' => $member->id,
            'branch_id' => $this->branch->id,
            'service_type' => 'sunday_service',
            'gathering_date' => now()->subDays(3)->toDateString(),
            'status' => 'present',
            'capture_method' => 'manual',
            'captured_at' => now()->subDays(3),
            'recorded_by' => $admin->id,
        ]);

        VolunteerProfile::create([
            'member_id' => $member->id,
            'branch_id' => $this->branch->id,
            'skills' => ['music'],
            'status' => VolunteerProfile::STATUS_ACTIVE,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        WelfareRequest::create([
            'case_number' => 'WR-BA-001',
            'branch_id' => $this->branch->id,
            'beneficiary_member_id' => $member->id,
            'request_type' => 'food',
            'description' => 'Support needed',
            'priority' => 'normal',
            'consent_data_processing' => true,
            'consent_welfare_review' => true,
            'status' => WelfareRequest::STATUS_SUBMITTED,
            'submitted_at' => now()->subDay(),
            'created_by' => $admin->id,
        ]);

        CareCase::create([
            'case_number' => 'CC-BA-001',
            'branch_id' => $this->branch->id,
            'beneficiary_member_id' => $member->id,
            'category' => 'bereavement',
            'description' => 'Pastoral support',
            'priority' => 'high',
            'status' => CareCase::STATUS_OPEN,
            'consent_basis' => 'member_request',
            'confidentiality' => 'assigned_only',
            'data_classification' => 'restricted_sensitive',
            'assigned_officer_id' => $admin->id,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        Contribution::create([
            'reference' => 'GIV-BA-001',
            'provider' => 'manual',
            'source_type' => Contribution::SOURCE_MANUAL,
            'provider_payment_reference' => 'REF-BA-001',
            'payment_reference' => 'REF-BA-001',
            'status' => Contribution::STATUS_SUCCEEDED,
            'amount_cents' => 5000,
            'currency' => 'USD',
            'category' => 'tithe',
            'branch_id' => $this->branch->id,
            'member_id' => $member->id,
            'payer_linked' => true,
            'reconciliation_status' => Contribution::RECON_RECONCILED,
            'receipt_eligible' => true,
            'occurred_at' => now()->subDays(4),
            'provider_evidence' => ['source' => 'test'],
        ]);

        FollowUp::create([
            'person_type' => Member::class,
            'person_id' => $member->id,
            'branch_id' => $this->branch->id,
            'reason' => 'Welcome visit',
            'assignee_id' => $admin->id,
            'due_date' => now()->subDay()->toDateString(),
            'contact_method' => 'phone',
            'priority' => 'high',
            'status' => FollowUp::STATUS_ASSIGNED,
            'created_by' => $admin->id,
        ]);

        // Out-of-scope branch data must not affect metrics
        Member::create([
            'membership_id' => 'BA-OTHER',
            'branch_id' => $this->otherBranch->id,
            'registration_channel' => 'web',
            'first_name' => 'Other',
            'last_name' => 'Branch',
            'lifecycle_status' => 'active',
            'consent_data_processing' => true,
        ]);

        return $member;
    }

    public function test_branch_dashboard_shows_permitted_metrics_with_trends_and_freshness(): void
    {
        $admin = $this->branchAdmin();
        $this->seedBranchMetrics($admin);

        $data = $this->actingAsMfaVerified($admin)
            ->getJson("/api/org/organizations/{$this->branch->id}/dashboard")
            ->assertOk()
            ->json('data');

        $this->assertSame('branch_administrator', $data['layout']);
        $this->assertSame('Branch Alpha', $data['branch']['name']);
        $this->assertArrayHasKey('period', $data);
        $this->assertNotEmpty($data['generated_at']);

        $metrics = $data['metrics'];
        $this->assertSame('ready', $metrics['members']['state']);
        $this->assertGreaterThanOrEqual(2, $metrics['members']['total']);
        $this->assertArrayHasKey('trend', $metrics['members']);
        $this->assertArrayHasKey('data_as_of', $metrics['members']);
        $this->assertArrayHasKey('freshness', $metrics['members']);

        $this->assertSame('ready', $metrics['visitors']['state']);
        $this->assertSame(1, $metrics['visitors']['total']);
        $this->assertSame('ready', $metrics['converts']['state']);
        $this->assertSame(1, $metrics['converts']['total']);
        $this->assertSame('ready', $metrics['attendance']['state']);
        $this->assertSame(1, $metrics['attendance']['total']);
        $this->assertSame('ready', $metrics['volunteers']['state']);
        $this->assertSame(1, $metrics['volunteers']['total']);
        $this->assertSame('ready', $metrics['welfare']['state']);
        $this->assertGreaterThanOrEqual(1, $metrics['welfare']['total']);
        $this->assertSame('ready', $metrics['care']['state']);
        $this->assertSame(1, $metrics['care']['total']);
        $this->assertSame('ready', $metrics['giving']['state']);
        $this->assertSame(5000, $metrics['giving']['total']);
        $this->assertTrue($metrics['giving']['summary']['identity_minimized']);
        $this->assertSame('ready', $metrics['follow_up']['state']);
        $this->assertSame(1, $metrics['follow_up']['total']);

        $drill = $this->actingAsMfaVerified($admin)
            ->getJson("/api/org/organizations/{$this->branch->id}/dashboard/drill-down/visitors")
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $drill['widget_total']);
        $this->assertSame(1, $drill['record_count']);
        $this->assertSame('New Visitor', $drill['records'][0]['name']);
    }

    public function test_unauthorized_metrics_omit_counts_and_drill_down_is_forbidden(): void
    {
        $admin = $this->branchAdmin([
            'members.read',
            'visitors.read',
        ]);
        // Remove giving by not granting payments.giving.reports (excluded from default merge above)
        $role = Role::query()->where('name', 'branch_admin_' . $admin->id)->firstOrFail();
        RolePermission::query()->where('role_id', $role->id)->where('action', 'payments.giving.reports')->delete();
        RolePermission::query()->where('role_id', $role->id)->where('action', 'care.cases.read')->delete();

        $this->seedBranchMetrics($admin);

        $metrics = $this->actingAsMfaVerified($admin)
            ->getJson("/api/org/organizations/{$this->branch->id}/dashboard")
            ->assertOk()
            ->json('data.metrics');

        $this->assertSame('ready', $metrics['visitors']['state']);
        $this->assertSame('unauthorized', $metrics['giving']['state']);
        $this->assertArrayNotHasKey('total', $metrics['giving']);
        $this->assertArrayNotHasKey('summary', $metrics['giving']);
        $this->assertSame('unauthorized', $metrics['care']['state']);
        $this->assertArrayNotHasKey('total', $metrics['care']);

        $this->actingAsMfaVerified($admin)
            ->getJson("/api/org/organizations/{$this->branch->id}/dashboard/drill-down/giving")
            ->assertForbidden();

        $this->actingAsMfaVerified($admin)
            ->getJson("/api/org/organizations/{$this->otherBranch->id}/dashboard")
            ->assertForbidden();
    }
}
