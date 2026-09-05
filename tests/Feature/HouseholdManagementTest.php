<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\HouseholdMembership;
use App\Models\HouseholdRelationshipHistory;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 2.3: Organize Members into Households.
 */
class HouseholdManagementTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branchA;

    protected function setUp(): void
    {
        parent::setUp();

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'IDX-HQ']);
        $this->branchA = Organization::create(['name' => 'Branch A', 'type' => 'branch', 'identifier' => 'IDX-A', 'parent_id' => $hq->id]);
    }

    private function officer(): User
    {
        $user = $this->privilegedUser(['branch_id' => null]);
        $role = Role::create(['name' => 'household_officer_' . $user->id]);
        foreach (['households.read', 'households.manage', 'members.read', 'members.sensitive'] as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);

        return $user;
    }

    private function member(string $suffix, array $attrs = []): Member
    {
        return Member::create(array_merge([
            'membership_id' => 'S1-M-' . $suffix,
            'branch_id' => $this->branchA->id,
            'registration_channel' => 'web',
            'first_name' => 'Member',
            'last_name' => $suffix,
            'email' => strtolower($suffix) . '@example.com',
            'phone' => '0801000' . str_pad($suffix, 4, '0', STR_PAD_LEFT),
            'consent_data_processing' => true,
        ], $attrs));
    }

    // ------------------------------------------------------------------
    // AC1 — create household with relationships, reject conflicts
    // ------------------------------------------------------------------

    public function test_officer_can_create_household_with_distinct_member_relationships()
    {
        $officer = $this->officer();
        $head = $this->member('0001');
        $spouse = $this->member('0002');
        $child = $this->member('0003');

        $this->actingAsMfaVerified($officer)
            ->postJson('/api/households', [
                'name' => 'Adeyemi Family',
                'branch_id' => $this->branchA->id,
                'members' => [
                    ['member_id' => $head->id, 'relationship_type' => 'head'],
                    ['member_id' => $spouse->id, 'relationship_type' => 'spouse'],
                    ['member_id' => $child->id, 'relationship_type' => 'child'],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Adeyemi Family')
            ->assertJsonCount(3, 'data.members');

        $this->assertDatabaseHas('household_memberships', [
            'member_id' => $head->id,
            'relationship_type' => 'head',
        ]);
    }

    public function test_duplicate_active_household_membership_is_rejected()
    {
        $officer = $this->officer();
        $head = $this->member('0010');
        $spouse = $this->member('0011');

        $this->actingAsMfaVerified($officer)
            ->postJson('/api/households', [
                'name' => 'First Family',
                'branch_id' => $this->branchA->id,
                'members' => [
                    ['member_id' => $head->id, 'relationship_type' => 'head'],
                    ['member_id' => $spouse->id, 'relationship_type' => 'spouse'],
                ],
            ])
            ->assertCreated();

        $this->actingAsMfaVerified($officer)
            ->postJson('/api/households', [
                'name' => 'Second Family',
                'branch_id' => $this->branchA->id,
                'members' => [
                    ['member_id' => $head->id, 'relationship_type' => 'head'],
                ],
            ])
            ->assertStatus(409);
    }

    public function test_contradictory_second_head_in_same_household_is_rejected()
    {
        $officer = $this->officer();
        $head = $this->member('0020');
        $other = $this->member('0021');

        $create = $this->actingAsMfaVerified($officer)
            ->postJson('/api/households', [
                'name' => 'Conflict Family',
                'branch_id' => $this->branchA->id,
                'members' => [
                    ['member_id' => $head->id, 'relationship_type' => 'head'],
                ],
            ])
            ->assertCreated();

        $householdId = $create->json('data.id');

        $this->actingAsMfaVerified($officer)
            ->postJson("/api/households/{$householdId}/members", [
                'member_id' => $other->id,
                'relationship_type' => 'head',
            ])
            ->assertStatus(409);
    }

    // ------------------------------------------------------------------
    // AC2 — scoped view with protected summaries
    // ------------------------------------------------------------------

    public function test_household_view_shows_members_and_hides_restricted_summaries_without_permission()
    {
        $officer = $this->officer();
        $head = $this->member('0030', ['restricted_summaries' => ['welfare' => 'restricted']]);

        $household = Household::create([
            'name' => 'View Family',
            'branch_id' => $this->branchA->id,
            'head_member_id' => $head->id,
            'welfare_references' => ['case' => 'open'],
            'created_by' => $officer->id,
        ]);

        HouseholdMembership::create([
            'household_id' => $household->id,
            'member_id' => $head->id,
            'relationship_type' => 'head',
            'started_at' => now(),
            'created_by' => $officer->id,
        ]);

        $viewer = $this->privilegedUser(['branch_id' => null]);
        $role = Role::create(['name' => 'household_viewer']);
        RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => 'households.read']);
        RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => 'members.read']);
        RoleAssignment::create(['user_id' => $viewer->id, 'role_id' => $role->id, 'granted_by' => $viewer->id]);

        $this->actingAsMfaVerified($viewer)
            ->getJson("/api/households/{$household->id}")
            ->assertOk()
            ->assertJsonPath('data.members.0.full_name', $head->fullName())
            ->assertJsonMissingPath('data.welfare_references');
    }

    public function test_sensitive_viewer_sees_welfare_references()
    {
        $officer = $this->officer();
        $head = $this->member('0031');

        $household = Household::create([
            'name' => 'Sensitive Family',
            'branch_id' => $this->branchA->id,
            'head_member_id' => $head->id,
            'welfare_references' => ['case' => 'open'],
            'created_by' => $officer->id,
        ]);

        HouseholdMembership::create([
            'household_id' => $household->id,
            'member_id' => $head->id,
            'relationship_type' => 'head',
            'started_at' => now(),
            'created_by' => $officer->id,
        ]);

        $this->actingAsMfaVerified($officer)
            ->getJson("/api/households/{$household->id}")
            ->assertOk()
            ->assertJsonPath('data.welfare_references.case', 'open');
    }

    // ------------------------------------------------------------------
    // AC3 — relationship changes preserve history, contact overwrite guarded
    // ------------------------------------------------------------------

    public function test_relationship_change_preserves_history_without_deleting_membership_row()
    {
        $officer = $this->officer();
        $member = $this->member('0040');

        $create = $this->actingAsMfaVerified($officer)
            ->postJson('/api/households', [
                'name' => 'History Family',
                'branch_id' => $this->branchA->id,
                'members' => [
                    ['member_id' => $member->id, 'relationship_type' => 'dependant'],
                ],
            ])
            ->assertCreated();

        $householdId = $create->json('data.id');

        $this->actingAsMfaVerified($officer)
            ->postJson("/api/households/{$householdId}/members/{$member->id}/relationship", [
                'relationship_type' => 'child',
            ])
            ->assertOk()
            ->assertJsonPath('data.members.0.relationship_type', 'child');

        $this->assertDatabaseHas('household_relationship_history', [
            'household_id' => $householdId,
            'member_id' => $member->id,
            'action' => 'relationship.changed',
        ]);

        $this->assertSame(1, HouseholdMembership::where('member_id', $member->id)->whereNull('ended_at')->count());
    }

    public function test_shared_contact_update_requires_confirmation_before_overwriting_member_data()
    {
        $officer = $this->officer();
        $head = $this->member('0050', ['phone' => '08055554444']);

        $create = $this->actingAsMfaVerified($officer)
            ->postJson('/api/households', [
                'name' => 'Contact Family',
                'branch_id' => $this->branchA->id,
                'members' => [
                    ['member_id' => $head->id, 'relationship_type' => 'head'],
                ],
            ])
            ->assertCreated();

        $householdId = $create->json('data.id');

        $this->actingAsMfaVerified($officer)
            ->putJson("/api/households/{$householdId}", [
                'shared_phone' => '08011112222',
            ])
            ->assertStatus(409)
            ->assertJsonPath('confirm_overwrite_required', true);

        $this->assertDatabaseHas('members', [
            'id' => $head->id,
            'phone' => '08055554444',
        ]);

        $this->actingAsMfaVerified($officer)
            ->putJson("/api/households/{$householdId}", [
                'shared_phone' => '08011112222',
                'confirm_overwrite' => true,
            ])
            ->assertOk();

        $this->assertDatabaseHas('members', [
            'id' => $head->id,
            'phone' => '08011112222',
        ]);

        $this->assertDatabaseHas('household_relationship_history', [
            'household_id' => $householdId,
            'action' => 'household.updated',
        ]);
    }

    public function test_removing_member_ends_active_relationship_and_records_history()
    {
        $officer = $this->officer();
        $head = $this->member('0060');
        $child = $this->member('0061');

        $create = $this->actingAsMfaVerified($officer)
            ->postJson('/api/households', [
                'name' => 'Separation Family',
                'branch_id' => $this->branchA->id,
                'members' => [
                    ['member_id' => $head->id, 'relationship_type' => 'head'],
                    ['member_id' => $child->id, 'relationship_type' => 'child'],
                ],
            ])
            ->assertCreated();

        $householdId = $create->json('data.id');

        $this->actingAsMfaVerified($officer)
            ->postJson("/api/households/{$householdId}/members/{$child->id}/remove", [
                'reason' => 'Moved out',
            ])
            ->assertOk()
            ->assertJsonCount(1, 'data.members');

        $this->assertNotNull(HouseholdMembership::where('member_id', $child->id)->value('ended_at'));
        $this->assertDatabaseHas('household_relationship_history', [
            'household_id' => $householdId,
            'member_id' => $child->id,
            'action' => 'member.removed',
        ]);
    }
}
