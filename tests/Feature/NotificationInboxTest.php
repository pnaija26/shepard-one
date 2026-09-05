<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\MemberNotification;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Story 10.2: Manage My Notification Inbox.
 */
class NotificationInboxTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'INBOX-HQ']);
        $this->branch = Organization::create(['name' => 'Branch A', 'type' => 'branch', 'identifier' => 'INBOX-A', 'parent_id' => $hq->id]);
    }

    private function memberUser(): User
    {
        $user = User::factory()->create([
            'roles' => ['member'],
            'has_mfa_enrolled' => false,
            'branch_id' => $this->branch->id,
        ]);

        Member::create([
            'user_id' => $user->id,
            'membership_id' => 'INBOX-M-' . $user->id,
            'branch_id' => $this->branch->id,
            'registration_channel' => 'web',
            'first_name' => 'Inbox',
            'last_name' => 'User' . $user->id,
            'email' => 'inbox' . $user->id . '@example.com',
            'consent_data_processing' => true,
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
        ]);

        return $user;
    }

    private function notify(User $user, string $type, string $message, array $extra = []): MemberNotification
    {
        $member = Member::query()->where('user_id', $user->id)->firstOrFail();

        return MemberNotification::create(array_merge([
            'member_id' => $member->id,
            'user_id' => $user->id,
            'type' => $type,
            'message' => $message,
            'metadata' => [],
        ], $extra));
    }

    public function test_inbox_shows_only_own_permitted_categories_newest_first_with_unread_count(): void
    {
        $user = $this->memberUser();
        $other = $this->memberUser();

        $older = $this->notify($user, 'welfare.request.submitted', 'Welfare update');
        $this->notify($user, 'team_roster.published', 'Roster out');
        $this->notify($user, 'unknown.secret.type', 'Should be hidden');
        $this->notify($other, 'welfare.request.submitted', 'Other user welfare');
        // Created last so it is newest-first
        $newer = $this->notify($user, 'prayer.answered', 'Prayer answered');
        MemberNotification::query()->where('id', $older->id)->update([
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/me/notifications')
            ->assertOk()
            ->assertJsonPath('meta.unread_count', 3);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame($newer->id, $ids[0]);
        $this->assertSame($older->id, $ids[count($ids) - 1]);
        $this->assertCount(3, $ids);
        $this->assertNotContains(
            MemberNotification::query()->where('type', 'unknown.secret.type')->value('id'),
            $ids,
        );
        $this->assertNotContains(
            MemberNotification::query()->where('user_id', $other->id)->value('id'),
            $ids,
        );

        $categories = collect($response->json('data'))->pluck('category')->unique()->sort()->values()->all();
        foreach ($categories as $category) {
            $this->assertArrayHasKey($category, config('notifications.categories'));
        }

        $this->getJson('/api/me/notifications/summary')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 3);

        // Empty state for fresh user
        $fresh = $this->memberUser();
        Sanctum::actingAs($fresh);
        $this->getJson('/api/me/notifications')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.unread_count', 0);
    }

    public function test_mark_read_unread_archive_and_deep_link_rechecks_authorization(): void
    {
        $user = $this->memberUser();
        $note = $this->notify($user, 'task.overdue', 'Task overdue', [
            'deep_link' => '/tasks',
            'metadata' => ['deep_link' => '/tasks'],
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/me/notifications/{$note->id}/read")
            ->assertOk()
            ->assertJsonPath('data.is_read', true)
            ->assertJsonPath('meta.unread_count', 0);

        $this->postJson("/api/me/notifications/{$note->id}/unread")
            ->assertOk()
            ->assertJsonPath('data.is_read', false);

        // Deep link forbidden without tasks.read
        $this->postJson("/api/me/notifications/{$note->id}/open")
            ->assertStatus(403)
            ->assertJsonPath('code', 'deep_link_forbidden');

        $role = Role::create(['name' => 'task_reader_' . $user->id]);
        RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => 'tasks.read']);
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);
        app(\App\Services\AuthorizationService::class)->invalidate($user);

        $this->postJson("/api/me/notifications/{$note->id}/open")
            ->assertOk()
            ->assertJsonPath('data.deep_link', '/tasks')
            ->assertJsonPath('data.authorized', true);

        $note->refresh();
        $this->assertNotNull($note->read_at);

        $this->postJson("/api/me/notifications/{$note->id}/archive")
            ->assertOk()
            ->assertJsonPath('data.is_archived', true);

        $this->getJson('/api/me/notifications')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/me/notifications?include_archived=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $note->id);

        // Reject unapproved external deep link
        $bad = $this->notify($user, 'system.alert', 'External', [
            'deep_link' => 'https://evil.example/phish',
        ]);
        $this->postJson("/api/me/notifications/{$bad->id}/open")
            ->assertStatus(422);

        // Another user cannot mutate
        $other = $this->memberUser();
        Sanctum::actingAs($other);
        $this->postJson("/api/me/notifications/{$note->id}/read")
            ->assertStatus(404);
    }
}
