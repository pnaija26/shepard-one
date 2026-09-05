<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Household;
use App\Models\HouseholdMembership;
use App\Models\Member;
use App\Models\MemberDuplicateFlag;
use App\Models\MemberMerge;
use App\Models\MemberProfileHistory;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 2.5: Review and Merge Duplicate Members.
 */
class MemberDuplicateMergeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branchA;

    protected function setUp(): void
    {
        parent::setUp();

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'IDX-HQ']);
        $this->branchA = Organization::create(['name' => 'Branch A', 'type' => 'branch', 'identifier' => 'IDX-A', 'parent_id' => $hq->id]);
    }

    private function officer(array $attrs = []): User
    {
        return $this->privilegedUser(array_merge(['branch_id' => null], $attrs));
    }

    private function grantDuplicatePermissions(User $user): void
    {
        $role = Role::create(['name' => 'dup_officer_' . $user->id]);
        foreach ([
            'members.read', 'members.write', 'members.duplicates.review', 'members.duplicates.merge',
            'households.read', 'households.manage',
        ] as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);
    }

    private function createMember(array $overrides = []): Member
    {
        return Member::create(array_merge([
            'membership_id' => 'S1-M-' . str_pad((string) (Member::count() + 1), 6, '0', STR_PAD_LEFT),
            'branch_id' => $this->branchA->id,
            'registration_channel' => 'reception',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane' . Member::count() . '@example.com',
            'phone' => '0801111222' . Member::count(),
            'date_of_birth' => '1985-06-01',
            'membership_status' => 'active',
            'consent_data_processing' => true,
            'consent_directory' => false,
        ], $overrides));
    }

    // ------------------------------------------------------------------
    // AC1 — flag duplicates with confidence, no auto-merge
    // ------------------------------------------------------------------

    public function test_profile_update_flags_duplicate_with_confidence_without_merging(): void
    {
        $officer = $this->officer();
        $this->grantDuplicatePermissions($officer);

        $original = $this->createMember(['email' => 'shared@example.com', 'phone' => '08099998888']);
        $candidate = $this->createMember(['email' => 'other@example.com', 'phone' => '08077776666']);

        $this->actingAsMfaVerified($officer)
            ->putJson("/api/members/{$candidate->id}", ['email' => 'shared@example.com'])
            ->assertOk();

        $this->assertDatabaseHas('member_duplicate_flags', [
            'member_a_id' => min($original->id, $candidate->id),
            'member_b_id' => max($original->id, $candidate->id),
            'confidence' => 'high',
            'match_reason' => 'email',
            'status' => MemberDuplicateFlag::STATUS_PENDING,
        ]);

        $this->assertDatabaseCount('member_merges', 0);
        $this->assertNull($candidate->fresh()->merged_into_id);
    }

    // ------------------------------------------------------------------
    // AC2 — merge re-links history; retired membership id traceable
    // ------------------------------------------------------------------

    public function test_reviewer_can_merge_duplicates_and_relink_history(): void
    {
        $officer = $this->officer();
        $this->grantDuplicatePermissions($officer);

        $survivor = $this->createMember([
            'first_name' => 'Ada',
            'last_name' => 'Okafor',
            'email' => 'ada@example.com',
            'phone' => '08012345678',
            'membership_id' => 'S1-M-000101',
        ]);
        $merged = $this->createMember([
            'first_name' => 'Ada',
            'last_name' => 'Okafor',
            'email' => 'ada@example.com',
            'phone' => '08087654321',
            'membership_id' => 'S1-M-000102',
        ]);

        $household = Household::create([
            'name' => 'Okafor Family',
            'branch_id' => $this->branchA->id,
            'head_member_id' => $merged->id,
        ]);
        HouseholdMembership::create([
            'household_id' => $household->id,
            'member_id' => $merged->id,
            'relationship_type' => HouseholdMembership::TYPE_HEAD,
            'started_at' => now(),
        ]);

        MemberProfileHistory::create([
            'member_id' => $merged->id,
            'actor_id' => $officer->id,
            'action' => 'updated',
            'before_values' => ['phone' => 'old'],
            'after_values' => ['phone' => '08087654321'],
            'created_at' => now(),
        ]);

        [$memberAId, $memberBId] = MemberDuplicateFlag::pairIds($survivor->id, $merged->id);
        $flag = MemberDuplicateFlag::create([
            'member_a_id' => $memberAId,
            'member_b_id' => $memberBId,
            'confidence' => 'high',
            'match_reason' => 'email',
            'status' => MemberDuplicateFlag::STATUS_PENDING,
        ]);

        $resolutions = [];
        foreach (['first_name', 'last_name', 'email', 'phone', 'date_of_birth'] as $field) {
            $resolutions[$field] = $field === 'phone' ? 'merged' : 'survivor';
        }

        $this->actingAsMfaVerified($officer)
            ->postJson('/api/members/duplicates/merge', [
                'survivor_id' => $survivor->id,
                'merged_member_id' => $merged->id,
                'field_resolutions' => $resolutions,
                'flag_id' => $flag->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.membership_id', 'S1-M-000101')
            ->assertJsonPath('data.phone', '08087654321');

        $this->assertDatabaseHas('member_merges', [
            'survivor_id' => $survivor->id,
            'merged_member_id' => $merged->id,
            'retired_membership_id' => 'S1-M-000102',
        ]);

        $merged->refresh();
        $this->assertSame($survivor->id, $merged->merged_into_id);
        $this->assertSame('archived', $merged->membership_status);

        $this->assertDatabaseHas('member_profile_history', [
            'member_id' => $survivor->id,
            'action' => 'merged',
        ]);
        $this->assertDatabaseMissing('member_profile_history', [
            'member_id' => $merged->id,
        ]);

        $this->assertDatabaseHas('household_memberships', [
            'household_id' => $household->id,
            'member_id' => $survivor->id,
            'ended_at' => null,
        ]);

        $this->assertDatabaseHas('member_duplicate_flags', [
            'id' => $flag->id,
            'status' => MemberDuplicateFlag::STATUS_MERGED,
        ]);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'member.merged',
            'module' => 'members',
        ]);
    }

    public function test_retired_membership_id_cannot_be_reused(): void
    {
        $officer = $this->officer();
        $this->grantDuplicatePermissions($officer);

        $survivor = $this->createMember(['membership_id' => 'S1-M-RETIRE-ME']);
        $merged = $this->createMember(['membership_id' => 'S1-M-RETIRED']);

        MemberMerge::create([
            'survivor_id' => $survivor->id,
            'merged_member_id' => $merged->id,
            'retired_membership_id' => 'S1-M-RETIRED',
            'field_resolutions' => ['email' => 'survivor'],
            'merged_by' => $officer->id,
            'created_at' => now(),
        ]);

        $this->assertTrue(app(\App\Services\MemberDuplicateService::class)->isMembershipIdRetired('S1-M-RETIRED'));
    }

    // ------------------------------------------------------------------
    // AC3 — conflicting restricted records block merge
    // ------------------------------------------------------------------

    public function test_merge_blocked_by_conflicting_restricted_summaries(): void
    {
        $officer = $this->officer();
        $this->grantDuplicatePermissions($officer);

        $survivor = $this->createMember([
            'email' => 'dup@example.com',
            'restricted_summaries' => ['pastoral_note' => 'Counselling in progress'],
        ]);
        $merged = $this->createMember([
            'email' => 'dup@example.com',
            'restricted_summaries' => ['pastoral_note' => 'Different pastoral context'],
        ]);

        $resolutions = ['email' => 'survivor', 'first_name' => 'survivor', 'last_name' => 'survivor'];

        $this->actingAsMfaVerified($officer)
            ->postJson('/api/members/duplicates/merge', [
                'survivor_id' => $survivor->id,
                'merged_member_id' => $merged->id,
                'field_resolutions' => $resolutions,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Merge blocked due to conflicting records.')
            ->assertJsonStructure(['conflicts']);

        $this->assertDatabaseCount('member_merges', 0);
        $this->assertNull($merged->fresh()->merged_into_id);

        $this->assertDatabaseMissing('audit_events', [
            'action' => 'member.merged',
        ]);
    }

    public function test_merge_blocked_when_members_in_different_active_households(): void
    {
        $officer = $this->officer();
        $this->grantDuplicatePermissions($officer);

        $survivor = $this->createMember(['email' => 'household.dup@example.com']);
        $merged = $this->createMember(['email' => 'household.dup@example.com']);

        $householdA = Household::create(['name' => 'Family A', 'branch_id' => $this->branchA->id]);
        $householdB = Household::create(['name' => 'Family B', 'branch_id' => $this->branchA->id]);

        foreach ([[$householdA, $survivor], [$householdB, $merged]] as [$household, $member]) {
            HouseholdMembership::create([
                'household_id' => $household->id,
                'member_id' => $member->id,
                'relationship_type' => HouseholdMembership::TYPE_HEAD,
                'started_at' => now(),
            ]);
        }

        $this->actingAsMfaVerified($officer)
            ->postJson('/api/members/duplicates/merge', [
                'survivor_id' => $survivor->id,
                'merged_member_id' => $merged->id,
                'field_resolutions' => ['email' => 'survivor'],
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['type' => 'household']);

        $this->assertDatabaseCount('member_merges', 0);
    }
}
