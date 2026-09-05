<?php

namespace Tests\Feature;

use App\Models\CommunitySpaceModerationEvent;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 10.6: Operate Moderated Community Spaces.
 */
class CommunitySpaceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'CS-HQ']);
        $this->branch = Organization::create(['name' => 'Branch A', 'type' => 'branch', 'identifier' => 'CS-A', 'parent_id' => $hq->id]);
    }

    private function grant(User $user, array $actions): void
    {
        $role = Role::create(['name' => 'cs_' . $user->id . '_' . substr(md5(implode(',', $actions)), 0, 6)]);
        foreach ($actions as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);
    }

    private function allActions(): array
    {
        return [
            'community_spaces.read',
            'community_spaces.manage',
            'community_spaces.post',
            'community_spaces.moderate',
            'community_spaces.integrate',
        ];
    }

    private function officer(): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $this->grant($user, $this->allActions());

        Member::create([
            'membership_id' => 'CS-OFF-' . $user->id,
            'branch_id' => $this->branch->id,
            'user_id' => $user->id,
            'registration_channel' => 'web',
            'first_name' => 'Officer',
            'last_name' => 'User' . $user->id,
            'email' => $user->email,
            'consent_data_processing' => true,
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
        ]);

        return $user;
    }

    private function participantUser(array $memberOverrides = []): array
    {
        $user = User::factory()->create([
            'branch_id' => $this->branch->id,
            'email' => 'cs' . uniqid() . '@church.test',
        ]);
        $this->grant($user, [
            'community_spaces.read',
            'community_spaces.post',
        ]);

        $member = Member::create(array_merge([
            'membership_id' => 'CS-M-' . $user->id,
            'branch_id' => $this->branch->id,
            'user_id' => $user->id,
            'registration_channel' => 'web',
            'first_name' => 'Pat',
            'last_name' => 'Member' . $user->id,
            'email' => $user->email,
            'consent_data_processing' => true,
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
        ], $memberOverrides));

        return [$user, $member];
    }

    public function test_participants_can_post_permitted_types_with_file_and_consent_rules(): void
    {
        $admin = $this->officer();
        [$memberUser] = $this->participantUser();

        $spaceId = $this->actingAsMfaVerified($admin)
            ->postJson('/api/community-spaces', [
                'name' => 'Youth Cell Chat',
                'space_type' => 'cell',
                'branch_id' => $this->branch->id,
                'description' => 'Coordination for the cell',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/community-spaces/{$spaceId}/members", [
                'user_id' => $memberUser->id,
                'role' => 'member',
            ])
            ->assertCreated();

        $text = $this->actingAsMfaVerified($memberUser)
            ->postJson("/api/community-spaces/{$spaceId}/messages", [
                'message_type' => 'text',
                'body' => 'Hello everyone, see you Sunday.',
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame('text', $text['message_type']);
        $this->assertSame($memberUser->id, $text['sender_user_id']);
        $this->assertNotEmpty($text['created_at']);

        $this->actingAsMfaVerified($memberUser)
            ->postJson("/api/community-spaces/{$spaceId}/messages", [
                'message_type' => 'image',
                'body' => 'Flyer',
                'attachments' => [[
                    'name' => 'flyer.jpg',
                    'mime' => 'image/jpeg',
                    'size_bytes' => 1200,
                    'storage_key' => 'uploads/flyer.jpg',
                ]],
            ])
            ->assertCreated();

        $this->actingAsMfaVerified($memberUser)
            ->postJson("/api/community-spaces/{$spaceId}/messages", [
                'message_type' => 'image',
                'body' => 'Too big',
                'attachments' => [[
                    'name' => 'huge.jpg',
                    'mime' => 'image/jpeg',
                    'size_bytes' => 20 * 1024 * 1024,
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'file_too_large');

        $this->actingAsMfaVerified($memberUser)
            ->postJson("/api/community-spaces/{$spaceId}/messages", [
                'message_type' => 'announcement',
                'body' => 'Only mods',
            ])
            ->assertStatus(403);

        $this->actingAsMfaVerified($memberUser)
            ->getJson("/api/community-spaces/{$spaceId}/messages")
            ->assertOk()
            ->assertJsonPath('data.0.sender.id', $memberUser->id);
    }

    public function test_moderation_actions_are_audited_and_removed_content_hidden_from_search(): void
    {
        $admin = $this->officer();
        [$memberUser] = $this->participantUser();

        $spaceId = $this->actingAsMfaVerified($admin)
            ->postJson('/api/community-spaces', [
                'name' => 'Ministry Space',
                'space_type' => 'ministry',
                'branch_id' => $this->branch->id,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/community-spaces/{$spaceId}/members", ['user_id' => $memberUser->id])
            ->assertCreated();

        $messageId = $this->actingAsMfaVerified($memberUser)
            ->postJson("/api/community-spaces/{$spaceId}/messages", [
                'message_type' => 'text',
                'body' => 'Sensitive prayer need about a private matter',
                'is_sensitive' => true,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/community-spaces/{$spaceId}/messages/{$messageId}/pin")
            ->assertOk()
            ->assertJsonPath('data.is_pinned', true);

        $this->actingAsMfaVerified($memberUser)
            ->postJson("/api/community-spaces/{$spaceId}/messages/{$messageId}/report", [
                'reason' => 'Inappropriate for this space',
            ])
            ->assertCreated();

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/community-spaces/{$spaceId}/messages/{$messageId}/remove", [
                'reason' => 'Policy violation',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'removed')
            ->assertJsonPath('data.body', null)
            ->assertJsonPath('data.preview', null);

        $this->assertDatabaseHas('community_space_moderation_events', [
            'community_space_id' => $spaceId,
            'community_space_message_id' => $messageId,
            'action' => 'remove',
        ]);
        $this->assertGreaterThanOrEqual(3, CommunitySpaceModerationEvent::query()->where('community_space_id', $spaceId)->count());

        $this->actingAsMfaVerified($memberUser)
            ->getJson("/api/community-spaces/{$spaceId}/search?q=Sensitive")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAsMfaVerified($memberUser)
            ->getJson("/api/community-spaces/{$spaceId}/messages")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/community-spaces/{$spaceId}/members/{$memberUser->id}/moderate", [
                'action' => 'mute',
                'reason' => 'Cooling off',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'muted');

        $this->actingAsMfaVerified($memberUser)
            ->postJson("/api/community-spaces/{$spaceId}/messages", [
                'message_type' => 'text',
                'body' => 'Should fail while muted',
            ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'membership_restricted');
    }

    public function test_only_approved_integrations_with_consent_and_boundaries_are_allowed(): void
    {
        $admin = $this->officer();

        $spaceId = $this->actingAsMfaVerified($admin)
            ->postJson('/api/community-spaces', [
                'name' => 'Large Branch Broadcast',
                'space_type' => 'branch',
                'branch_id' => $this->branch->id,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/community-spaces/{$spaceId}/integrations", [
                'provider' => 'discord',
                'consent_documented' => true,
                'identity_mapping' => ['strategy' => 'email'],
                'moderation_boundary' => 'Church moderators remain authoritative.',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'unsupported_integration');

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/community-spaces/{$spaceId}/integrations", [
                'provider' => 'slack',
                'enabled' => true,
                'consent_documented' => false,
                'identity_mapping' => ['strategy' => 'membership_id'],
                'moderation_boundary' => 'Moderation remains in ShepardOne; Slack is relay only.',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'consent_required');

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/community-spaces/{$spaceId}/integrations", [
                'provider' => 'slack',
                'enabled' => true,
                'consent_documented' => true,
                'identity_mapping' => ['strategy' => 'membership_id'],
                'moderation_boundary' => 'Moderation remains in ShepardOne; Slack is relay only.',
                'config' => ['workspace' => 'church', 'api_key' => 'should-be-stripped'],
            ])
            ->assertOk()
            ->assertJsonPath('data.provider', 'slack')
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.consent_documented', true);

        $this->assertDatabaseHas('community_space_integrations', [
            'community_space_id' => $spaceId,
            'provider' => 'slack',
            'enabled' => true,
        ]);
    }
}
