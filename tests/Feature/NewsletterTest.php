<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Newsletter;
use App\Models\NewsletterDelivery;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 10.5: Build, Approve, and Measure Newsletters.
 */
class NewsletterTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'NL-HQ']);
        $this->branch = Organization::create(['name' => 'Branch A', 'type' => 'branch', 'identifier' => 'NL-A', 'parent_id' => $hq->id]);
    }

    private function grant(User $user, array $actions): void
    {
        $role = Role::create(['name' => 'nl_' . $user->id . '_' . substr(md5(implode(',', $actions)), 0, 6)]);
        foreach ($actions as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);
    }

    private function officer(): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $this->grant($user, [
            'newsletters.read',
            'newsletters.manage',
            'newsletters.approve',
            'newsletters.process',
            'newsletters.analytics',
        ]);

        return $user;
    }

    private function member(array $overrides = []): Member
    {
        $user = User::factory()->create([
            'branch_id' => $this->branch->id,
            'email' => $overrides['email'] ?? ('nl' . uniqid() . '@church.test'),
        ]);

        return Member::create(array_merge([
            'membership_id' => 'NL-M-' . $user->id,
            'branch_id' => $this->branch->id,
            'user_id' => $user->id,
            'registration_channel' => 'web',
            'first_name' => 'Pat',
            'last_name' => 'Member' . $user->id,
            'email' => $user->email,
            'consent_data_processing' => true,
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
            'communication_preferences' => [
                'email' => true,
                'in_app' => true,
            ],
        ], $overrides));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function validSections(): array
    {
        return [
            ['type' => 'text', 'body' => 'Welcome to this week at church.'],
            ['type' => 'button', 'label' => 'View events', 'href' => 'https://church.test/events'],
            ['type' => 'unsubscribe', 'label' => 'Unsubscribe'],
        ];
    }

    public function test_compose_preview_flags_inaccessible_and_missing_unsubscribe(): void
    {
        $admin = $this->officer();

        $created = $this->actingAsMfaVerified($admin)
            ->postJson('/api/newsletters', [
                'name' => 'Sunday Digest',
                'branch_id' => $this->branch->id,
                'subject' => 'This Sunday',
                'preview_text' => 'Join us',
                'sections' => $this->validSections(),
                'audience_type' => 'branch',
                'audience_params' => ['branch_id' => $this->branch->id],
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame('draft', $created['status']);
        $id = $created['id'];

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/newsletters/{$id}/preview")
            ->assertOk()
            ->assertJsonPath('data.passed', true)
            ->assertJsonCount(3, 'data.previews');

        $this->actingAsMfaVerified($admin)
            ->postJson('/api/newsletters', [
                'name' => 'Bad Digest',
                'branch_id' => $this->branch->id,
                'subject' => 'Oops',
                'sections' => [
                    ['type' => 'image', 'src' => 'https://church.test/a.jpg'],
                    ['type' => 'text', 'body' => '<script>alert(1)</script>'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_content');

        $this->actingAsMfaVerified($admin)
            ->postJson('/api/newsletters', [
                'name' => 'No Unsubscribe',
                'branch_id' => $this->branch->id,
                'subject' => 'Missing footer',
                'sections' => [
                    ['type' => 'text', 'body' => 'Hello'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_content');
    }

    public function test_approve_schedule_material_edit_requires_renewed_approval_then_deliver_and_analytics(): void
    {
        $admin = $this->officer();
        $recipient = $this->member();
        $noConsent = $this->member([
            'email' => 'noconsent' . uniqid() . '@church.test',
            'consent_data_processing' => false,
        ]);

        $id = $this->actingAsMfaVerified($admin)
            ->postJson('/api/newsletters', [
                'name' => 'Scheduled Digest',
                'branch_id' => $this->branch->id,
                'subject' => 'Scheduled news',
                'sections' => $this->validSections(),
                'audience_type' => 'members',
                'audience_params' => ['member_ids' => [$recipient->id, $noConsent->id]],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/newsletters/{$id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_approval');

        $scheduledAt = Carbon::now()->subMinute()->toIso8601String();
        $this->actingAsMfaVerified($admin)
            ->postJson("/api/newsletters/{$id}/approve", ['scheduled_at' => $scheduledAt])
            ->assertOk()
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.approved_version', 1);

        // Material edit of approved version creates a new draft that must be re-approved.
        $this->actingAsMfaVerified($admin)
            ->putJson("/api/newsletters/{$id}/draft", [
                'subject' => 'Revised scheduled news',
                'sections' => array_merge($this->validSections(), [
                    ['type' => 'verse', 'reference' => 'John 3:16', 'text' => 'For God so loved the world'],
                ]),
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.draft_version', 2)
            ->assertJsonPath('data.approved_version', 1);

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/newsletters/{$id}/submit")
            ->assertOk();

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/newsletters/{$id}/approve", [
                'scheduled_at' => Carbon::now()->subMinute()->toIso8601String(),
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.approved_version', 2);

        $process = $this->actingAsMfaVerified($admin)
            ->postJson('/api/newsletters/process-due?branch_id=' . $this->branch->id)
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $process['processed']);
        $this->assertSame(1, $process['sent']);
        $this->assertSame(1, $process['skipped']);

        $this->assertDatabaseHas('newsletter_deliveries', [
            'newsletter_id' => $id,
            'member_id' => $recipient->id,
            'status' => NewsletterDelivery::STATUS_SENT,
            'is_test' => false,
        ]);
        $this->assertDatabaseHas('newsletter_deliveries', [
            'newsletter_id' => $id,
            'member_id' => $noConsent->id,
            'status' => NewsletterDelivery::STATUS_SKIPPED,
            'skip_reason' => 'missing_consent',
        ]);

        $deliveryId = NewsletterDelivery::query()
            ->where('newsletter_id', $id)
            ->where('member_id', $recipient->id)
            ->where('is_test', false)
            ->value('id');

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/newsletters/{$id}/events", [
                'event_type' => 'opened',
                'delivery_id' => $deliveryId,
                'provider' => 'simulated',
            ])
            ->assertCreated();

        $analytics = $this->actingAsMfaVerified($admin)
            ->getJson("/api/newsletters/{$id}/analytics")
            ->assertOk()
            ->json('data');

        $this->assertGreaterThanOrEqual(1, $analytics['totals']['sent']);
        $this->assertGreaterThanOrEqual(1, $analytics['totals']['delivered']);
        $this->assertSame(1, $analytics['totals']['opened']);
        $this->assertArrayHasKey('opened', $analytics['provider_limitations']);
        $this->assertNotEmpty($analytics['privacy_note']);

        $this->assertSame(Newsletter::STATUS_SENT, Newsletter::query()->find($id)->status);
    }

    public function test_test_send_requires_valid_content(): void
    {
        $admin = $this->officer();
        $recipient = $this->member();

        $id = $this->actingAsMfaVerified($admin)
            ->postJson('/api/newsletters', [
                'name' => 'Test Digest',
                'branch_id' => $this->branch->id,
                'subject' => 'Test subject',
                'sections' => $this->validSections(),
                'audience_type' => 'branch',
                'audience_params' => ['branch_id' => $this->branch->id],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/newsletters/{$id}/test-send", [
                'member_ids' => [$recipient->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.sent', 1);

        $this->assertDatabaseHas('newsletter_deliveries', [
            'newsletter_id' => $id,
            'member_id' => $recipient->id,
            'is_test' => true,
            'status' => NewsletterDelivery::STATUS_SENT,
        ]);
    }
}
