<?php

namespace Tests\Feature;

use App\Models\Communication;
use App\Models\CommunicationDelivery;
use App\Models\Member;
use App\Models\MessageTemplate;
use App\Models\MessageTemplateVersion;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\MessageTemplateService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 10.3: Create Reusable Message Templates.
 */
class MessageTemplateTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'TPL-HQ']);
        $this->branch = Organization::create(['name' => 'Branch A', 'type' => 'branch', 'identifier' => 'TPL-A', 'parent_id' => $hq->id]);
    }

    private function grant(User $user, array $actions): void
    {
        $role = Role::create(['name' => 'tpl_' . $user->id . '_' . substr(md5(implode(',', $actions)), 0, 6)]);
        foreach ($actions as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);
    }

    private function admin(): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $this->grant($user, [
            'message_templates.read',
            'message_templates.manage',
            'message_templates.publish',
        ]);

        return $user;
    }

    public function test_admin_can_create_preview_and_publish_template(): void
    {
        $admin = $this->admin();

        $created = $this->actingAsMfaVerified($admin)
            ->postJson('/api/message-templates', [
                'name' => 'Birthday email',
                'scenario' => 'birthday',
                'channel' => 'email',
                'language' => 'en',
                'branch_id' => $this->branch->id,
                'subject' => 'Happy birthday {{first_name}}!',
                'body' => 'Dear {{preferred_name}}, blessings from {{branch_name}} on {{date}}.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->json('data');

        $id = $created['id'];

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/message-templates/{$id}/validate")
            ->assertOk()
            ->assertJsonPath('data.validation.valid', true);

        $preview = $this->actingAsMfaVerified($admin)
            ->postJson("/api/message-templates/{$id}/preview", [
                'sample' => ['first_name' => 'Jordan', 'ssn' => 'should-be-stripped'],
            ])
            ->assertOk()
            ->assertJsonPath('data.passed', true)
            ->json('data');

        $this->assertStringContainsString('Jordan', $preview['rendered']['subject']);
        $this->assertArrayNotHasKey('ssn', $preview['sample']);

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/message-templates/{$id}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.current_version', 1);

        $this->assertDatabaseHas('message_template_versions', [
            'message_template_id' => $id,
            'version' => 1,
            'status' => 'published',
        ]);
    }

    public function test_rejects_unknown_variables_unsafe_markup_prohibited_links_and_length(): void
    {
        $admin = $this->admin();

        $this->actingAsMfaVerified($admin)
            ->postJson('/api/message-templates', [
                'name' => 'Bad vars',
                'scenario' => 'birthday',
                'channel' => 'email',
                'subject' => 'Hi {{first_name}}',
                'body' => 'Secret {{salary}} amount',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_content');

        $this->actingAsMfaVerified($admin)
            ->postJson('/api/message-templates', [
                'name' => 'Unsafe html',
                'scenario' => 'announcement',
                'channel' => 'email',
                'subject' => 'Hello',
                'body' => '<script>alert(1)</script> Welcome {{first_name}}',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_content');

        $this->actingAsMfaVerified($admin)
            ->postJson('/api/message-templates', [
                'name' => 'Bad link',
                'scenario' => 'announcement',
                'channel' => 'email',
                'subject' => 'Click',
                'body' => 'Open javascript:alert(1) {{first_name}}',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_content');

        $long = str_repeat('x', 321);
        $this->actingAsMfaVerified($admin)
            ->postJson('/api/message-templates', [
                'name' => 'SMS too long',
                'scenario' => 'reminder',
                'channel' => 'sms',
                'body' => $long . ' {{first_name}}',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_content');
    }

    public function test_edit_and_retire_preserve_prior_version_for_deliveries(): void
    {
        $admin = $this->admin();
        $service = app(MessageTemplateService::class);

        $id = $this->actingAsMfaVerified($admin)
            ->postJson('/api/message-templates', [
                'name' => 'Welcome SMS',
                'scenario' => 'welcome',
                'channel' => 'sms',
                'body' => 'Welcome {{first_name}} to {{branch_name}}!',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/message-templates/{$id}/publish")
            ->assertOk();

        $template = MessageTemplate::query()->findOrFail($id);
        $v1 = MessageTemplateVersion::query()->where('message_template_id', $id)->where('version', 1)->firstOrFail();

        $rendered = $service->renderForSend($template, [
            'first_name' => 'Sam',
            'branch_name' => 'Central',
        ]);
        $this->assertSame($v1->id, $rendered['version_id']);
        $this->assertStringContainsString('Sam', $rendered['body']);

        // Simulate a delivery retaining the rendered version reference
        $member = Member::create([
            'membership_id' => 'TPL-M-1',
            'branch_id' => $this->branch->id,
            'registration_channel' => 'web',
            'first_name' => 'Sam',
            'last_name' => 'Member',
            'consent_data_processing' => true,
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
        ]);
        $communication = Communication::create([
            'reference' => 'COM-TPL-1',
            'branch_id' => $this->branch->id,
            'name' => 'Welcome blast',
            'subject' => 'Welcome',
            'body' => $rendered['body'],
            'purpose' => 'engagement',
            'channels' => ['sms'],
            'audience_type' => 'members',
            'audience_params' => ['member_ids' => [$member->id]],
            'schedule_type' => 'immediate',
            'status' => Communication::STATUS_COMPLETED,
            'created_by' => $admin->id,
        ]);
        $delivery = CommunicationDelivery::create([
            'communication_id' => $communication->id,
            'message_template_version_id' => $rendered['version_id'],
            'member_id' => $member->id,
            'channel' => 'sms',
            'destination' => '+15550001111',
            'status' => CommunicationDelivery::STATUS_SENT,
            'sent_at' => now(),
            'result' => ['template_version_id' => $rendered['version_id']],
        ]);

        // Edit creates draft v2 and publish supersedes v1 with effective window
        $this->actingAsMfaVerified($admin)
            ->putJson("/api/message-templates/{$id}/draft", [
                'body' => 'Hello {{preferred_name}}, welcome to {{church_name}}!',
            ])
            ->assertOk()
            ->assertJsonPath('data.draft_version', 2);

        Carbon::setTestNow(now()->addMinute());
        $this->actingAsMfaVerified($admin)
            ->postJson("/api/message-templates/{$id}/publish")
            ->assertOk()
            ->assertJsonPath('data.current_version', 2);

        $v1->refresh();
        $this->assertSame(MessageTemplateVersion::STATUS_SUPERSEDED, $v1->status);
        $this->assertNotNull($v1->effective_to);

        // Historical send still resolves v1 for the original time
        $historical = $service->resolveEffectiveVersion($template->fresh(), $delivery->sent_at);
        $this->assertNotNull($historical);
        $this->assertSame($v1->id, $historical->id);

        // Current send uses v2
        $current = $service->renderForSend($template->fresh(), [
            'preferred_name' => 'Sam',
            'church_name' => 'ShepardOne',
        ]);
        $this->assertNotSame($v1->id, $current['version_id']);
        $this->assertStringContainsString('ShepardOne', $current['body']);

        // Delivery still points at original version
        $this->assertSame($v1->id, $delivery->fresh()->message_template_version_id);

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/message-templates/{$id}/retire")
            ->assertOk()
            ->assertJsonPath('data.status', 'retired');

        Carbon::setTestNow();
    }
}
