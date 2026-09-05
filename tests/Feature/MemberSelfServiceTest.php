<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Member;
use App\Models\MemberNotification;
use App\Models\MemberProfileChangeRequest;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Story 2.2: Update My Profile Safely.
 */
class MemberSelfServiceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'IDX-HQ']);
        $this->branch = Organization::create(['name' => 'Branch A', 'type' => 'branch', 'identifier' => 'IDX-A', 'parent_id' => $hq->id]);
    }

    private function memberUser(array $userAttrs = [], array $memberAttrs = []): User
    {
        $user = User::factory()->create(array_merge([
            'roles' => ['member'],
            'has_mfa_enrolled' => false,
        ], $userAttrs));

        Member::create(array_merge([
            'user_id' => $user->id,
            'membership_id' => 'S1-M-' . str_pad((string) $user->id, 6, '0', STR_PAD_LEFT),
            'branch_id' => $this->branch->id,
            'registration_channel' => 'web',
            'first_name' => 'Grace',
            'last_name' => 'Adeyemi',
            'email' => 'grace@example.com',
            'phone' => '08011112222',
            'consent_data_processing' => true,
        ], $memberAttrs));

        return $user;
    }

    private function officer(): User
    {
        $user = $this->privilegedUser(['branch_id' => null]);
        $role = Role::create(['name' => 'profile_reviewer_' . $user->id]);
        RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => 'members.changes.review']);
        RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => 'members.read']);
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);

        return $user;
    }

    // ------------------------------------------------------------------
    // AC1 — immediate vs approval-required updates with visible status
    // ------------------------------------------------------------------

    public function test_member_can_apply_immediate_phone_change_and_see_status()
    {
        $user = $this->memberUser();
        Sanctum::actingAs($user);

        $this->putJson('/api/me/profile', ['phone' => '08099990000'])
            ->assertOk()
            ->assertJsonPath('changes.applied.0.field', 'phone')
            ->assertJsonPath('changes.applied.0.status', 'applied')
            ->assertJsonPath('data.phone', '08099990000');

        $this->assertDatabaseHas('members', [
            'user_id' => $user->id,
            'phone' => '08099990000',
        ]);
    }

    public function test_email_change_is_submitted_for_approval_with_pending_status()
    {
        $user = $this->memberUser();
        Sanctum::actingAs($user);

        $this->putJson('/api/me/profile', ['email' => 'new.grace@example.com'])
            ->assertOk()
            ->assertJsonPath('changes.pending.0.field', 'email')
            ->assertJsonPath('changes.pending.0.status', 'pending_approval')
            ->assertJsonPath('data.email', 'grace@example.com')
            ->assertJsonPath('data.pending_changes.0.field', 'email');

        $this->assertDatabaseHas('member_profile_change_requests', [
            'member_id' => Member::where('user_id', $user->id)->value('id'),
            'field_name' => 'email',
            'status' => MemberProfileChangeRequest::STATUS_PENDING,
        ]);
    }

    // ------------------------------------------------------------------
    // AC2 — staff-controlled fields rejected, existing value unchanged
    // ------------------------------------------------------------------

    public function test_member_cannot_change_staff_controlled_fields()
    {
        $user = $this->memberUser();
        Sanctum::actingAs($user);

        $this->putJson('/api/me/profile', [
            'phone' => '08088887777',
            'membership_status' => 'inactive',
            'first_name' => 'Hacked',
        ])
            ->assertOk()
            ->assertJsonPath('changes.applied.0.field', 'phone')
            ->assertJsonPath('changes.rejected.0.field', 'membership_status')
            ->assertJsonPath('changes.rejected.1.field', 'first_name');

        $member = Member::where('user_id', $user->id)->first();
        $this->assertSame('Grace', $member->first_name);
        $this->assertSame('active', $member->membership_status);
        $this->assertSame('08088887777', $member->phone);
    }

    public function test_only_forbidden_changes_are_rejected_with_error()
    {
        $user = $this->memberUser();
        Sanctum::actingAs($user);

        $this->putJson('/api/me/profile', ['membership_status' => 'inactive'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['profile']);
    }

    // ------------------------------------------------------------------
    // AC3 — officer approve/reject with notification and audit
    // ------------------------------------------------------------------

    public function test_officer_approval_applies_value_notifies_member_and_audits()
    {
        $user = $this->memberUser();
        $officer = $this->officer();

        Sanctum::actingAs($user);
        $this->putJson('/api/me/profile', ['email' => 'approved@example.com'])->assertOk();

        $requestId = MemberProfileChangeRequest::first()->id;

        $this->actingAsMfaVerified($officer)
            ->postJson("/api/members/profile-changes/{$requestId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', MemberProfileChangeRequest::STATUS_APPROVED);

        $this->assertDatabaseHas('members', [
            'user_id' => $user->id,
            'email' => 'approved@example.com',
        ]);

        $this->assertDatabaseHas('member_notifications', [
            'user_id' => $user->id,
            'type' => 'profile_change_approved',
        ]);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'member.profile_change.approved',
            'actor_id' => $officer->id,
        ]);
    }

    public function test_officer_rejection_keeps_existing_value_and_notifies_member()
    {
        $user = $this->memberUser();
        $officer = $this->officer();

        Sanctum::actingAs($user);
        $this->putJson('/api/me/profile', ['preferred_name' => 'Gigi'])->assertOk();

        $requestId = MemberProfileChangeRequest::first()->id;

        $this->actingAsMfaVerified($officer)
            ->postJson("/api/members/profile-changes/{$requestId}/reject", ['reason' => 'Use legal name'])
            ->assertOk()
            ->assertJsonPath('data.status', MemberProfileChangeRequest::STATUS_REJECTED);

        $member = Member::where('user_id', $user->id)->first();
        $this->assertNull($member->preferred_name);

        $this->assertDatabaseHas('member_notifications', [
            'user_id' => $user->id,
            'type' => 'profile_change_rejected',
        ]);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'member.profile_change.rejected',
        ]);
    }
}
