<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\Setting;
use App\Models\SettingReference;
use App\Models\User;
use App\Services\ConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 1.7: Configure Governed Platform Settings.
 */
class ConfigurationManagementTest extends TestCase
{
    use RefreshDatabase;

    private function hqActor(): User
    {
        return $this->privilegedUser(['branch_id' => null]);
    }

    private function branchActor(Organization $branch): User
    {
        return $this->privilegedUser(['branch_id' => $branch->id]);
    }

    private function grantConfig(User $user, bool $manage = true): void
    {
        $role = Role::create(['name' => 'config_admin_' . $user->id]);
        RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => 'config.read']);
        if ($manage) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => 'config.manage']);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);
    }

    private function seedSetting(string $key, array $attrs = []): Setting
    {
        return Setting::create(array_merge([
            'key' => $key,
            'value' => 'active',
            'type' => 'string',
            'category' => 'user',
            'description' => 'Test setting',
            'is_locked' => false,
        ], $attrs));
    }

    // ------------------------------------------------------------------
    // AC1 — scoped management + centrally locked settings
    // ------------------------------------------------------------------

    public function test_admin_with_config_permission_can_list_and_update_settings()
    {
        $actor = $this->hqActor();
        $this->grantConfig($actor);
        $this->seedSetting('member_status_active', ['category' => 'member_statuses']);

        $this->actingAsMfaVerified($actor)
            ->getJson('/api/config?category=member_statuses')
            ->assertOk()
            ->assertJsonPath('data.0.key', 'member_status_active');

        $this->actingAsMfaVerified($actor)
            ->putJson('/api/config/member_status_active', ['value' => 'draft-value'])
            ->assertOk()
            ->assertJsonPath('data.draft_value', 'draft-value')
            ->assertJsonPath('data.value', 'active');
    }

    public function test_branch_admin_cannot_override_centally_locked_setting()
    {
        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'IDX-HQ']);
        $branch = Organization::create(['name' => 'Branch A', 'type' => 'branch', 'identifier' => 'IDX-A', 'parent_id' => $hq->id]);
        $actor = $this->branchActor($branch);
        $this->grantConfig($actor);
        $this->seedSetting('system_name', ['is_locked' => true, 'category' => 'system']);

        $this->actingAsMfaVerified($actor)
            ->putJson('/api/config/system_name', ['value' => 'Local Override'])
            ->assertStatus(403);
    }

    // ------------------------------------------------------------------
    // AC2 — referenced settings cannot be deleted
    // ------------------------------------------------------------------

    public function test_referenced_setting_cannot_be_deleted_but_can_be_archived()
    {
        $actor = $this->hqActor();
        $this->grantConfig($actor);
        $setting = $this->seedSetting('attendance_type_sunday');
        SettingReference::create([
            'setting_id' => $setting->id,
            'reference_type' => 'attendance_record',
            'reference_id' => 42,
        ]);

        $this->actingAsMfaVerified($actor)
            ->deleteJson('/api/config/attendance_type_sunday')
            ->assertStatus(409);

        $this->actingAsMfaVerified($actor)
            ->deleteJson('/api/config/attendance_type_sunday', ['archive' => true])
            ->assertOk();

        $this->assertTrue($setting->fresh()->is_archived);
        $this->assertSame('active', $setting->fresh()->value);
    }

    // ------------------------------------------------------------------
    // AC3 — invalid publish rejected; last valid value remains
    // ------------------------------------------------------------------

    public function test_invalid_publish_is_rejected_and_active_value_unchanged()
    {
        $actor = $this->hqActor();
        $this->grantConfig($actor);
        $this->seedSetting('max_login_attempts', ['value' => '5', 'type' => 'integer', 'category' => 'security']);

        $this->actingAsMfaVerified($actor)
            ->putJson('/api/config/max_login_attempts', ['value' => 'not-a-number'])
            ->assertStatus(422);

        $this->assertSame('5', Setting::where('key', 'max_login_attempts')->value('value'));
        $this->assertNull(Setting::where('key', 'max_login_attempts')->value('draft_value'));
    }

    public function test_valid_draft_publish_becomes_active()
    {
        $actor = $this->hqActor();
        $this->grantConfig($actor);
        $this->seedSetting('notification_sender', ['value' => 'noreply@church.org', 'category' => 'notifications']);

        $this->actingAsMfaVerified($actor)
            ->putJson('/api/config/notification_sender', ['value' => 'hello@church.org'])
            ->assertOk();

        $this->actingAsMfaVerified($actor)
            ->postJson('/api/config/notification_sender/publish')
            ->assertOk()
            ->assertJsonPath('data.value', 'hello@church.org');

        $this->assertSame('hello@church.org', Setting::where('key', 'notification_sender')->value('value'));
    }

    public function test_configuration_service_get_active_uses_published_value()
    {
        $setting = $this->seedSetting('template_welcome', ['value' => 'Welcome!', 'type' => 'string']);
        $service = app(ConfigurationService::class);

        $this->assertSame('Welcome!', $service->getActive('template_welcome'));
    }
}
