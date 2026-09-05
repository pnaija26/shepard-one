<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Member;
use App\Models\MemberDuplicateReview;
use App\Models\MemberProfileHistory;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 2.1: Register and Maintain Member Profiles.
 */
class MemberRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $hq;

    private Organization $branchA;

    private Organization $branchB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'IDX-HQ']);
        $this->branchA = Organization::create(['name' => 'Branch A', 'type' => 'branch', 'identifier' => 'IDX-A', 'parent_id' => $this->hq->id]);
        $this->branchB = Organization::create(['name' => 'Branch B', 'type' => 'branch', 'identifier' => 'IDX-B', 'parent_id' => $this->hq->id]);
    }

    private function officer(array $attrs = []): User
    {
        return $this->privilegedUser(array_merge(['branch_id' => null], $attrs));
    }

    private function grantMembers(User $user, bool $preferences = true, bool $sensitive = false): void
    {
        $role = Role::create(['name' => 'members_officer_' . $user->id]);
        foreach (['members.read', 'members.write', 'members.archive'] as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        if ($preferences) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => 'members.preferences']);
        }
        if ($sensitive) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => 'members.sensitive']);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'branch_id' => $this->branchA->id,
            'registration_channel' => 'reception',
            'first_name' => 'Ada',
            'last_name' => 'Okafor',
            'email' => 'ada.okafor@example.com',
            'phone' => '08012345678',
            'date_of_birth' => '1990-04-12',
            'consent_data_processing' => true,
            'consent_directory' => false,
            'spiritual_gifts' => ['hospitality'],
            'skills' => ['music'],
        ], $overrides);
    }

    // ------------------------------------------------------------------
    // AC1 — registration with membership ID and optional preferences
    // ------------------------------------------------------------------

    public function test_officer_can_register_member_with_membership_id_in_scope()
    {
        $officer = $this->officer();
        $this->grantMembers($officer);

        $response = $this->actingAsMfaVerified($officer)
            ->postJson('/api/members', $this->validPayload())
            ->assertCreated()
            ->assertJsonPath('data.first_name', 'Ada')
            ->assertJsonPath('data.branch_id', $this->branchA->id)
            ->assertJsonPath('data.spiritual_gifts', ['hospitality']);

        $membershipId = $response->json('data.membership_id');
        $this->assertMatchesRegularExpression('/^S1-M-\d{6}$/', $membershipId);

        $this->assertDatabaseHas('members', [
            'membership_id' => $membershipId,
            'branch_id' => $this->branchA->id,
        ]);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'member.created',
            'subject_type' => Member::class,
        ]);
    }

    // ------------------------------------------------------------------
    // AC2 — duplicate detection preserves input
    // ------------------------------------------------------------------

    public function test_duplicate_registration_is_blocked_and_input_preserved()
    {
        $officer = $this->officer();
        $this->grantMembers($officer);

        Member::create([
            'membership_id' => 'S1-M-000001',
            'branch_id' => $this->branchA->id,
            'registration_channel' => 'web',
            'first_name' => 'Ada',
            'last_name' => 'Okafor',
            'email' => 'ada.okafor@example.com',
            'consent_data_processing' => true,
        ]);

        $payload = $this->validPayload();

        $this->actingAsMfaVerified($officer)
            ->postJson('/api/members', $payload)
            ->assertStatus(422)
            ->assertJsonPath('duplicate_review_required', true)
            ->assertJsonPath('preserved_input.email', 'ada.okafor@example.com')
            ->assertJsonCount(1, 'potential_matches');

        $this->assertDatabaseHas('member_duplicate_reviews', [
            'matched_member_id' => Member::first()->id,
            'status' => MemberDuplicateReview::STATUS_PENDING,
        ]);

        $this->assertSame(1, Member::count());
    }

    public function test_incomplete_registration_returns_field_specific_errors()
    {
        $officer = $this->officer();
        $this->grantMembers($officer);

        $this->actingAsMfaVerified($officer)
            ->postJson('/api/members', [
                'branch_id' => $this->branchA->id,
                'registration_channel' => 'web',
                'first_name' => 'Incomplete',
                'last_name' => 'Member',
                'consent_data_processing' => false,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['consent_data_processing']);
    }

    // ------------------------------------------------------------------
    // AC3 — permitted updates, history, audit, restricted omission
    // ------------------------------------------------------------------

    public function test_officer_can_update_permitted_fields_with_history_and_audit()
    {
        $officer = $this->officer();
        $this->grantMembers($officer);

        $create = $this->actingAsMfaVerified($officer)
            ->postJson('/api/members', $this->validPayload())
            ->assertCreated();

        $memberId = $create->json('data.id');

        $this->actingAsMfaVerified($officer)
            ->putJson("/api/members/{$memberId}", ['phone' => '08099998888'])
            ->assertOk()
            ->assertJsonPath('data.phone', '08099998888');

        $this->assertDatabaseHas('member_profile_history', [
            'member_id' => $memberId,
            'action' => 'updated',
        ]);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'member.updated',
            'subject_id' => $memberId,
        ]);
    }

    public function test_restricted_summaries_omitted_without_sensitive_permission()
    {
        $officer = $this->officer();
        $this->grantMembers($officer, preferences: true, sensitive: false);

        $member = Member::create([
            'membership_id' => 'S1-M-000010',
            'branch_id' => $this->branchA->id,
            'registration_channel' => 'web',
            'first_name' => 'Sam',
            'last_name' => 'Udo',
            'email' => 'sam@example.com',
            'consent_data_processing' => true,
            'restricted_summaries' => ['giving' => 'restricted'],
        ]);

        $this->actingAsMfaVerified($officer)
            ->getJson("/api/members/{$member->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.restricted_summaries');
    }

    public function test_sensitive_summaries_visible_with_permission()
    {
        $officer = $this->officer();
        $this->grantMembers($officer, sensitive: true);

        $member = Member::create([
            'membership_id' => 'S1-M-000011',
            'branch_id' => $this->branchA->id,
            'registration_channel' => 'web',
            'first_name' => 'Sam',
            'last_name' => 'Udo',
            'email' => 'sam2@example.com',
            'consent_data_processing' => true,
            'restricted_summaries' => ['welfare' => 'case open'],
        ]);

        $this->actingAsMfaVerified($officer)
            ->getJson("/api/members/{$member->id}")
            ->assertOk()
            ->assertJsonPath('data.restricted_summaries.welfare', 'case open');
    }

    public function test_archive_records_history_and_blocks_further_updates()
    {
        $officer = $this->officer();
        $this->grantMembers($officer);

        $member = Member::create([
            'membership_id' => 'S1-M-000012',
            'branch_id' => $this->branchA->id,
            'registration_channel' => 'web',
            'first_name' => 'Tunde',
            'last_name' => 'Balogun',
            'email' => 'tunde@example.com',
            'consent_data_processing' => true,
        ]);

        $this->actingAsMfaVerified($officer)
            ->postJson("/api/members/{$member->id}/archive", ['reason' => 'Relocated'])
            ->assertOk()
            ->assertJsonPath('data.membership_status', 'archived');

        $this->assertDatabaseHas('member_profile_history', [
            'member_id' => $member->id,
            'action' => 'archived',
        ]);

        $this->actingAsMfaVerified($officer)
            ->putJson("/api/members/{$member->id}", ['phone' => '08011112222'])
            ->assertStatus(422);
    }

    public function test_branch_officer_cannot_view_member_outside_scope()
    {
        $branchOfficer = $this->privilegedUser(['branch_id' => $this->branchA->id]);
        $this->grantMembers($branchOfficer);

        $member = Member::create([
            'membership_id' => 'S1-M-000013',
            'branch_id' => $this->branchB->id,
            'registration_channel' => 'web',
            'first_name' => 'Other',
            'last_name' => 'Branch',
            'email' => 'other@example.com',
            'consent_data_processing' => true,
        ]);

        $this->actingAsMfaVerified($branchOfficer)
            ->getJson("/api/members/{$member->id}")
            ->assertForbidden();
    }
}
