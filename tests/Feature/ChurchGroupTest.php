<?php

namespace Tests\Feature;

use App\Models\ChurchGroup;
use App\Models\ChurchGroupJoinRequest;
use App\Models\ChurchGroupMembership;
use App\Models\ChurchGroupMembershipHistory;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 6.1: Create and Organize Church Groups.
 */
class ChurchGroupTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;

  private Organization $branchB;

    protected function setUp(): void
    {
        parent::setUp();

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'IDX-HQ']);
        $this->branch = Organization::create(['name' => 'Branch A', 'type' => 'branch', 'identifier' => 'IDX-A', 'parent_id' => $hq->id]);
        $this->branchB = Organization::create(['name' => 'Branch B', 'type' => 'branch', 'identifier' => 'IDX-B', 'parent_id' => $hq->id]);
    }

    private function coordinator(): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $role = Role::create(['name' => 'group_coord_' . $user->id]);
        foreach ([
            'groups.read', 'groups.manage', 'groups.members.manage',
            'groups.join_requests.submit', 'groups.join_requests.review',
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

    private function groupPayload(User $leader, array $overrides = []): array
    {
        return array_merge([
            'name' => 'Young Adults Fellowship',
            'branch_id' => $this->branch->id,
            'group_type' => 'fellowship',
            'description' => 'Weekly fellowship for young adults.',
            'leaders' => [['user_id' => $leader->id, 'role' => 'lead']],
            'meeting_pattern' => [
                'frequency' => 'weekly',
                'day' => 'friday',
                'start_time' => '19:00',
                'end_time' => '21:00',
                'venue' => 'Fellowship hall',
            ],
            'capacity' => 20,
            'eligibility' => [
                'min_age' => 18,
                'max_age' => 35,
                'lifecycle_stages' => ['member'],
                'requires_consent' => true,
                'requires_safeguarding_clearance' => false,
            ],
            'communication_settings' => ['allow_member_posts' => true],
            'reporting_settings' => ['requires_weekly_report' => true],
        ], $overrides);
    }

    private function member(string $suffix, array $attrs = []): Member
    {
        return Member::create(array_merge([
            'membership_id' => 'S61-' . $suffix,
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

    // ------------------------------------------------------------------
    // AC1 — create groups in scope, reject invalid configuration
    // ------------------------------------------------------------------

    public function test_coordinator_creates_group_with_governance_settings(): void
    {
        $coordinator = $this->coordinator();
        $leader = $this->leaderUser();

        $created = $this->actingAsMfaVerified($coordinator)
            ->postJson('/api/groups', $this->groupPayload($leader))
            ->assertCreated()
            ->assertJsonPath('data.status', ChurchGroup::STATUS_DRAFT)
            ->assertJsonPath('data.group_type', 'fellowship');

        $groupId = $created->json('data.id');

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/groups/{$groupId}/activate")
            ->assertOk()
            ->assertJsonPath('data.status', ChurchGroup::STATUS_ACTIVE);

        $this->assertDatabaseHas('church_groups', [
            'id' => $groupId,
            'branch_id' => $this->branch->id,
            'status' => ChurchGroup::STATUS_ACTIVE,
        ]);
    }

    public function test_invalid_leader_and_schedule_are_rejected(): void
    {
        $coordinator = $this->coordinator();
        $outsider = User::factory()->create(['branch_id' => $this->branchB->id, 'has_mfa_enrolled' => true]);

        $this->actingAsMfaVerified($coordinator)
            ->postJson('/api/groups', $this->groupPayload($outsider))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['leaders']);

        $leader = $this->leaderUser();
        $payload = $this->groupPayload($leader);
        $payload['meeting_pattern']['end_time'] = '18:00';

        $this->actingAsMfaVerified($coordinator)
            ->postJson('/api/groups', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['meeting_pattern.end_time']);
    }

    public function test_cross_branch_member_assignment_is_rejected(): void
    {
        $coordinator = $this->coordinator();
        $leader = $this->leaderUser();

        $groupId = $this->actingAsMfaVerified($coordinator)
            ->postJson('/api/groups', $this->groupPayload($leader))
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/groups/{$groupId}/activate")
            ->assertOk();

        $otherBranchMember = Member::create([
            'membership_id' => 'S61-OTHER',
            'branch_id' => $this->branchB->id,
            'registration_channel' => 'web',
            'first_name' => 'Other',
            'last_name' => 'Branch',
            'consent_data_processing' => true,
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
        ]);

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/groups/{$groupId}/members", [
                'member_id' => $otherBranchMember->id,
                'role' => 'member',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['member_id']);
    }

    // ------------------------------------------------------------------
    // AC2 — membership governance, capacity, join requests, history
    // ------------------------------------------------------------------

    public function test_capacity_and_age_rules_are_enforced_on_assignment(): void
    {
        $coordinator = $this->coordinator();
        $leader = $this->leaderUser();

        $groupId = $this->actingAsMfaVerified($coordinator)
            ->postJson('/api/groups', $this->groupPayload($leader, ['capacity' => 1]))
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/groups/{$groupId}/activate")
            ->assertOk();

        $eligible = $this->member('ELIG');
        $tooYoung = $this->member('YOUNG', ['date_of_birth' => now()->subYears(10)->toDateString()]);

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/groups/{$groupId}/members", ['member_id' => $eligible->id, 'role' => 'member'])
            ->assertCreated();

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/groups/{$groupId}/members", ['member_id' => $tooYoung->id, 'role' => 'member'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['member_id']);

        $second = $this->member('FULL');
        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/groups/{$groupId}/members", ['member_id' => $second->id, 'role' => 'member'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['capacity']);
    }

    public function test_join_request_approval_creates_membership_with_history(): void
    {
        $coordinator = $this->coordinator();
        $leader = $this->leaderUser();

        $groupId = $this->actingAsMfaVerified($coordinator)
            ->postJson('/api/groups', $this->groupPayload($leader))
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/groups/{$groupId}/activate")
            ->assertOk();

        $member = $this->member('JOIN');

        $requestId = $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/groups/{$groupId}/join-requests", [
                'member_id' => $member->id,
                'message' => 'I would like to join this fellowship.',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/group-join-requests/{$requestId}/review", [
                'decision' => 'approved',
                'role' => 'member',
            ])
            ->assertOk()
            ->assertJsonPath('data.decision', 'approved');

        $this->assertDatabaseHas('church_group_memberships', [
            'church_group_id' => $groupId,
            'member_id' => $member->id,
            'status' => ChurchGroupMembership::STATUS_ACTIVE,
        ]);

        $this->assertDatabaseHas('church_group_membership_history', [
            'church_group_id' => $groupId,
            'member_id' => $member->id,
            'change_type' => 'join_request.approved',
        ]);
    }

    public function test_transfer_and_remove_retain_membership_history(): void
    {
        $coordinator = $this->coordinator();
        $leader = $this->leaderUser();

        $sourceId = $this->actingAsMfaVerified($coordinator)
            ->postJson('/api/groups', $this->groupPayload($leader, ['name' => 'Source Group']))
            ->assertCreated()
            ->json('data.id');

        $targetId = $this->actingAsMfaVerified($coordinator)
            ->postJson('/api/groups', $this->groupPayload($leader, ['name' => 'Target Group']))
            ->assertCreated()
            ->json('data.id');

        foreach ([$sourceId, $targetId] as $groupId) {
            $this->actingAsMfaVerified($coordinator)->postJson("/api/groups/{$groupId}/activate")->assertOk();
        }

        $member = $this->member('MOVE');

        $membershipId = $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/groups/{$sourceId}/members", ['member_id' => $member->id, 'role' => 'member'])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/groups/{$sourceId}/members/{$membershipId}/transfer", [
                'target_group_id' => $targetId,
                'role' => 'member',
            ])
            ->assertOk();

        $targetMembership = ChurchGroupMembership::query()
            ->where('church_group_id', $targetId)
            ->where('member_id', $member->id)
            ->where('status', ChurchGroupMembership::STATUS_ACTIVE)
            ->firstOrFail();

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/groups/{$targetId}/members/{$targetMembership->id}/remove", [
                'reason' => 'Relocated to another city.',
            ])
            ->assertOk();

        $this->assertDatabaseHas('church_group_membership_history', [
            'church_group_id' => $sourceId,
            'member_id' => $member->id,
            'change_type' => 'member.transferred',
        ]);

        $this->assertDatabaseHas('church_group_membership_history', [
            'church_group_id' => $targetId,
            'member_id' => $member->id,
            'change_type' => 'member.removed',
        ]);
    }
}
