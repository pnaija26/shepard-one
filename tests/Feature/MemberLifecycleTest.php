<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\HouseholdMembership;
use App\Models\Member;
use App\Models\MemberLifecycleHistory;
use App\Models\MemberLifecyclePendingTransition;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 2.4: Track the Member Lifecycle.
 */
class MemberLifecycleTest extends TestCase
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
        $role = Role::create(['name' => 'lifecycle_officer_' . $user->id]);
        foreach ([
            'members.read', 'members.write', 'members.lifecycle.read',
            'members.lifecycle.manage', 'members.lifecycle.approve',
            'households.read',
        ] as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);

        return $user;
    }

    private function visitorMember(): Member
    {
        return Member::create([
            'membership_id' => 'S1-M-010000',
            'branch_id' => $this->branchA->id,
            'registration_channel' => 'reception',
            'first_name' => 'Visitor',
            'last_name' => 'One',
            'email' => 'visitor@example.com',
            'consent_data_processing' => true,
            'lifecycle_stage' => 'visitor',
            'lifecycle_status' => 'active',
            'membership_status' => 'active',
        ]);
    }

    // ------------------------------------------------------------------
    // AC1 — permitted transitions recorded with milestone metadata
    // ------------------------------------------------------------------

    public function test_officer_can_advance_visitor_to_convert_with_history()
    {
        $officer = $this->officer();
        $member = $this->visitorMember();

        $this->actingAsMfaVerified($officer)
            ->postJson("/api/members/{$member->id}/lifecycle/transition", [
                'to_stage' => 'convert',
                'effective_date' => now()->toDateString(),
                'reason' => 'Completed newcomers class',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'applied')
            ->assertJsonPath('data.current.stage', 'convert');

        $this->assertDatabaseHas('member_lifecycle_history', [
            'member_id' => $member->id,
            'stage' => 'convert',
            'reason' => 'Completed newcomers class',
        ]);
    }

    public function test_registration_creates_initial_visitor_lifecycle_state()
    {
        $officer = $this->officer();

        $response = $this->actingAsMfaVerified($officer)
            ->postJson('/api/members', [
                'branch_id' => $this->branchA->id,
                'registration_channel' => 'reception',
                'first_name' => 'New',
                'last_name' => 'Person',
                'email' => 'new@example.com',
                'consent_data_processing' => true,
            ])
            ->assertCreated();

        $memberId = $response->json('data.id');

        $this->assertDatabaseHas('members', [
            'id' => $memberId,
            'lifecycle_stage' => 'visitor',
            'lifecycle_status' => 'active',
        ]);
    }

    // ------------------------------------------------------------------
    // AC2 — missing evidence/approval blocks transition
    // ------------------------------------------------------------------

    public function test_transition_requiring_evidence_is_blocked_without_it()
    {
        $officer = $this->officer();
        $member = Member::create([
            'membership_id' => 'S1-M-010001',
            'branch_id' => $this->branchA->id,
            'registration_channel' => 'web',
            'first_name' => 'Active',
            'last_name' => 'Member',
            'consent_data_processing' => true,
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
            'membership_status' => 'active',
        ]);

        $this->actingAsMfaVerified($officer)
            ->postJson("/api/members/{$member->id}/lifecycle/transition", [
                'to_status' => 'transferred',
                'effective_date' => now()->toDateString(),
                'reason' => 'Moving branches',
            ])
            ->assertStatus(422)
            ->assertJsonPath('missing', ['evidence']);

        $member->refresh();
        $this->assertSame('active', $member->lifecycle_status);
    }

    public function test_transition_requiring_approval_stays_pending_until_approved()
    {
        $officer = $this->officer();
        $member = $this->visitorMember();

        $this->actingAsMfaVerified($officer)
            ->postJson("/api/members/{$member->id}/lifecycle/transition", [
                'to_stage' => 'member',
                'effective_date' => now()->toDateString(),
                'reason' => 'Ready for membership',
                'milestone' => ['type' => 'baptism', 'date' => now()->toDateString()],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_approval');

        $member->refresh();
        $this->assertSame('visitor', $member->lifecycle_stage);

        $pendingId = MemberLifecyclePendingTransition::first()->id;

        $this->actingAsMfaVerified($officer)
            ->postJson("/api/members/lifecycle/pending/{$pendingId}/approve")
            ->assertOk()
            ->assertJsonPath('data.current.stage', 'member');

        $member->refresh();
        $this->assertSame('member', $member->lifecycle_stage);
    }

    // ------------------------------------------------------------------
    // AC3 — terminal statuses apply policy and retain relationships
    // ------------------------------------------------------------------

    public function test_deceased_status_applies_policy_and_retains_household_link()
    {
        $officer = $this->officer();
        $member = Member::create([
            'membership_id' => 'S1-M-010002',
            'branch_id' => $this->branchA->id,
            'registration_channel' => 'web',
            'first_name' => 'Late',
            'last_name' => 'Member',
            'consent_data_processing' => true,
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
            'membership_status' => 'active',
        ]);

        $household = Household::create([
            'name' => 'Late Family',
            'branch_id' => $this->branchA->id,
            'head_member_id' => $member->id,
            'created_by' => $officer->id,
        ]);

        HouseholdMembership::create([
            'household_id' => $household->id,
            'member_id' => $member->id,
            'relationship_type' => 'head',
            'started_at' => now(),
            'created_by' => $officer->id,
        ]);

        $pending = MemberLifecyclePendingTransition::create([
            'member_id' => $member->id,
            'to_status' => 'deceased',
            'effective_date' => now()->toDateString(),
            'reason' => 'Recorded passing',
            'evidence' => ['source' => 'family_notification'],
            'status' => MemberLifecyclePendingTransition::STATUS_PENDING,
            'requested_by' => $officer->id,
        ]);

        $this->actingAsMfaVerified($officer)
            ->postJson("/api/members/lifecycle/pending/{$pending->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.current.status', 'deceased')
            ->assertJsonPath('data.current.policy.communications', 'none');

        $member->refresh();
        $this->assertSame('deceased', $member->lifecycle_status);
        $this->assertDatabaseHas('household_memberships', [
            'member_id' => $member->id,
            'household_id' => $household->id,
        ]);
        $this->assertDatabaseHas('member_lifecycle_history', [
            'member_id' => $member->id,
            'status' => 'deceased',
        ]);
    }
}
