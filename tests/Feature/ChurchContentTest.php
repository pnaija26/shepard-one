<?php

namespace Tests\Feature;

use App\Models\ChurchContent;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 10.7: Publish Church Content.
 */
class ChurchContentTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'CC-HQ']);
        $this->branch = Organization::create(['name' => 'Branch A', 'type' => 'branch', 'identifier' => 'CC-A', 'parent_id' => $hq->id]);
    }

    private function grant(User $user, array $actions): void
    {
        $role = Role::create(['name' => 'cc_' . $user->id . '_' . substr(md5(implode(',', $actions)), 0, 6)]);
        foreach ($actions as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);
    }

    private function editor(): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $this->grant($user, [
            'church_content.read',
            'church_content.manage',
            'church_content.approve',
            'church_content.view',
        ]);

        return $user;
    }

    private function viewer(): User
    {
        $user = User::factory()->create([
            'branch_id' => $this->branch->id,
            'email' => 'ccview' . uniqid() . '@church.test',
        ]);
        $this->grant($user, ['church_content.view']);

        Member::create([
            'membership_id' => 'CC-M-' . $user->id,
            'branch_id' => $this->branch->id,
            'user_id' => $user->id,
            'registration_channel' => 'web',
            'first_name' => 'View',
            'last_name' => 'Member' . $user->id,
            'email' => $user->email,
            'consent_data_processing' => true,
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
        ]);

        return $user;
    }

    public function test_draft_preview_rejects_unsafe_uploads_invalid_links_and_missing_accessibility(): void
    {
        $admin = $this->editor();

        $created = $this->actingAsMfaVerified($admin)
            ->postJson('/api/church-content', [
                'content_type' => 'article',
                'title' => 'Weekly Reflection',
                'body' => 'Grace and peace to you.',
                'branch_id' => $this->branch->id,
                'visibility' => 'members',
                'audience_type' => 'all',
                'device_target' => 'all',
                'media' => [[
                    'name' => 'cover.jpg',
                    'mime' => 'image/jpeg',
                    'size_bytes' => 2048,
                    'url' => '/media/cover.jpg',
                    'alt' => 'Sunrise over the chapel',
                ]],
                'links' => [[
                    'label' => 'Read more',
                    'href' => 'https://church.test/reflect',
                ]],
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame('draft', $created['status']);
        $id = $created['id'];

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/church-content/{$id}/preview", ['devices' => ['web', 'mobile']])
            ->assertOk()
            ->assertJsonPath('data.passed', true)
            ->assertJsonCount(2, 'data.previews');

        $this->actingAsMfaVerified($admin)
            ->postJson('/api/church-content', [
                'content_type' => 'media',
                'title' => 'Bad image',
                'body' => 'Missing alt',
                'branch_id' => $this->branch->id,
                'media' => [[
                    'name' => 'x.jpg',
                    'mime' => 'image/jpeg',
                    'size_bytes' => 100,
                    'url' => '/x.jpg',
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_content');

        $this->actingAsMfaVerified($admin)
            ->postJson('/api/church-content', [
                'content_type' => 'news',
                'title' => 'Bad link',
                'body' => 'Nope',
                'branch_id' => $this->branch->id,
                'links' => [['label' => 'Hack', 'href' => 'javascript:alert(1)']],
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_content');

        $this->actingAsMfaVerified($admin)
            ->postJson('/api/church-content', [
                'content_type' => 'download',
                'title' => 'Unsafe file',
                'body' => 'exe',
                'branch_id' => $this->branch->id,
                'media' => [[
                    'name' => 'malware.exe',
                    'mime' => 'application/x-msdownload',
                    'size_bytes' => 10,
                    'label' => 'Download',
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_content');
    }

    public function test_approved_content_appears_in_feed_within_window_and_omits_expired_withdrawn_unauthorized(): void
    {
        $admin = $this->editor();
        $viewer = $this->viewer();
        $otherBranch = Organization::create([
            'name' => 'Branch B',
            'type' => 'branch',
            'identifier' => 'CC-B',
            'parent_id' => Organization::query()->where('identifier', 'CC-HQ')->value('id'),
        ]);

        $liveId = $this->actingAsMfaVerified($admin)
            ->postJson('/api/church-content', [
                'content_type' => 'announcement',
                'title' => 'Sunday Service Times',
                'body' => 'Join us at 9am and 11am this Sunday.',
                'branch_id' => $this->branch->id,
                'visibility' => 'members',
                'audience_type' => 'branch',
                'audience_params' => ['branch_id' => $this->branch->id],
                'device_target' => 'all',
                'publish_from' => Carbon::now()->subHour()->toIso8601String(),
                'publish_to' => Carbon::now()->addDays(7)->toIso8601String(),
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($admin)->postJson("/api/church-content/{$liveId}/submit")->assertOk();
        $this->actingAsMfaVerified($admin)
            ->postJson("/api/church-content/{$liveId}/approve", ['publish_now' => true])
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        $expiredId = $this->actingAsMfaVerified($admin)
            ->postJson('/api/church-content', [
                'content_type' => 'news',
                'title' => 'Old Campaign',
                'body' => 'This should expire.',
                'branch_id' => $this->branch->id,
                'visibility' => 'members',
                'audience_type' => 'all',
                'publish_from' => Carbon::now()->subDays(10)->toIso8601String(),
                'publish_to' => Carbon::now()->subDay()->toIso8601String(),
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($admin)->postJson("/api/church-content/{$expiredId}/submit")->assertOk();
        // Approve without publishing because window already ended.
        $this->actingAsMfaVerified($admin)
            ->postJson("/api/church-content/{$expiredId}/approve", ['publish_now' => true])
            ->assertOk();

        $this->actingAsMfaVerified($admin)
            ->postJson('/api/church-content/process-windows')
            ->assertOk()
            ->assertJsonPath('data.expired', 1);

        $this->assertSame(ChurchContent::STATUS_EXPIRED, ChurchContent::query()->find($expiredId)->status);

        $withdrawId = $this->actingAsMfaVerified($admin)
            ->postJson('/api/church-content', [
                'content_type' => 'verse',
                'title' => 'Verse of the Day',
                'body' => 'John 3:16',
                'branch_id' => $this->branch->id,
                'visibility' => 'members',
                'audience_type' => 'all',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($admin)->postJson("/api/church-content/{$withdrawId}/submit")->assertOk();
        $this->actingAsMfaVerified($admin)->postJson("/api/church-content/{$withdrawId}/approve")->assertOk();
        $this->actingAsMfaVerified($admin)
            ->postJson("/api/church-content/{$withdrawId}/withdraw", ['reason' => 'Superseded'])
            ->assertOk()
            ->assertJsonPath('data.status', 'withdrawn');

        $restrictedId = $this->actingAsMfaVerified($admin)
            ->postJson('/api/church-content', [
                'content_type' => 'sermon',
                'title' => 'Leaders Only Notes',
                'body' => 'Confidential leadership briefing.',
                'branch_id' => $this->branch->id,
                'visibility' => 'role',
                'audience_type' => 'role',
                'audience_params' => ['role_id' => 999999],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($admin)->postJson("/api/church-content/{$restrictedId}/submit")->assertOk();
        $this->actingAsMfaVerified($admin)->postJson("/api/church-content/{$restrictedId}/approve")->assertOk();

        $feed = $this->actingAsMfaVerified($viewer)
            ->getJson('/api/church-content/feed?device=web')
            ->assertOk()
            ->json('data');

        $ids = collect($feed)->pluck('id')->all();
        $this->assertContains($liveId, $ids);
        $this->assertNotContains($expiredId, $ids);
        $this->assertNotContains($withdrawId, $ids);
        $this->assertNotContains($restrictedId, $ids);

        $this->actingAsMfaVerified($viewer)
            ->getJson('/api/church-content/search?q=Sunday')
            ->assertOk()
            ->assertJsonPath('data.0.id', $liveId);

        $this->actingAsMfaVerified($viewer)
            ->getJson('/api/church-content/search?q=Confidential')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAsMfaVerified($viewer)
            ->getJson('/api/church-content/search?q=Old')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Other-branch content stays out of this viewer's feed scope.
        unset($otherBranch);
    }
}
