<?php

namespace Tests\Feature;

use App\Models\ExternalAdapterOperation;
use App\Models\ExternalServiceAdapter;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Story 15.5: configure approved external service adapters.
 */
class ExternalAdapterTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;

    private string $healthBase = 'https://api.sendgrid.test';

    protected function setUp(): void
    {
        parent::setUp();

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'ADP-HQ']);
        $this->branch = Organization::create([
            'name' => 'Branch A',
            'type' => 'branch',
            'identifier' => 'ADP-A',
            'parent_id' => $hq->id,
        ]);
    }

    /**
     * @param  list<string>  $actions
     */
    private function grant(User $user, array $actions): void
    {
        $role = Role::create(['name' => 'adp_' . $user->id . '_' . substr(md5(implode(',', $actions)), 0, 6)]);
        foreach ($actions as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);
    }

    private function admin(): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $this->grant($user, ['adapters.read', 'adapters.manage']);

        return $user;
    }

    public function test_admin_configures_tests_and_activates_adapter_without_exposing_secrets(): void
    {
        $admin = $this->admin();

        Http::fake([
            $this->healthBase . '/v3/user/profile' => Http::response(['username' => 'church'], 200),
            'https://api.example.test/invoke' => Http::response(['accepted' => true], 200),
        ]);

        $created = $this->actingAsMfaVerified($admin)
            ->postJson('/api/external-adapters', [
                'name' => 'Primary email',
                'adapter_type' => 'email',
                'provider' => 'sendgrid',
                'environment' => 'sandbox',
                'branch_id' => $this->branch->id,
                'credentials' => ['api_key' => 'SG.super_secret_sendgrid_key_12345'],
                'callback_urls' => [
                    'health_base_url' => $this->healthBase,
                    'invoke_url' => 'https://api.example.test/invoke',
                ],
                'mappings' => ['from_email' => 'noreply@church.test'],
                'quotas' => ['per_minute' => 120],
                'feature_flags' => ['track_opens' => true],
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame('draft', $created['status']);
        $this->assertSame('…2345', $created['credential_hints']['api_key']);
        $this->assertArrayNotHasKey('credentials', $created);
        $this->assertArrayNotHasKey('credentials_encrypted', $created);

        $adapter = ExternalServiceAdapter::query()->findOrFail($created['id']);
        $this->assertNotSame('SG.super_secret_sendgrid_key_12345', $adapter->getRawOriginal('credentials_encrypted'));

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/external-adapters/{$adapter->id}/test")
            ->assertOk()
            ->assertJsonPath('data.passed', true);

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/external-adapters/{$adapter->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $invoke = $this->actingAsMfaVerified($admin)
            ->postJson('/api/external-adapters/invoke', [
                'capability' => 'send_email',
                'branch_id' => $this->branch->id,
                'payload' => ['to' => 'member@example.com', 'subject' => 'Welcome'],
                'idempotency_key' => 'email-welcome-001',
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame('pending', $invoke['status']);

        $this->actingAsMfaVerified($admin)
            ->postJson('/api/external-adapters/process-due')
            ->assertOk()
            ->assertJsonPath('data.completed', 1);

        $this->actingAsMfaVerified($admin)
            ->postJson('/api/external-adapters/invoke', [
                'capability' => 'send_email',
                'branch_id' => $this->branch->id,
                'payload' => ['to' => 'member@example.com', 'subject' => 'Welcome'],
                'idempotency_key' => 'email-welcome-001',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'completed');

        $this->assertSame(1, ExternalAdapterOperation::query()->count());
    }

    public function test_provider_replacement_drains_inflight_work_and_preserves_historical_reference(): void
    {
        $admin = $this->admin();

        Http::fake([
            $this->healthBase . '/v3/user/profile' => Http::response(['username' => 'church'], 200),
            'https://api.mailgun.test/v3/domains' => Http::response(['items' => []], 200),
            'https://api.example.test/invoke' => Http::sequence()->push(['accepted' => true], 200)->push(['accepted' => true], 200),
        ]);

        $original = $this->actingAsMfaVerified($admin)
            ->postJson('/api/external-adapters', [
                'name' => 'SendGrid email',
                'adapter_type' => 'email',
                'provider' => 'sendgrid',
                'branch_id' => $this->branch->id,
                'credentials' => ['api_key' => 'SG.original_secret_key_9999'],
                'callback_urls' => ['health_base_url' => $this->healthBase, 'invoke_url' => 'https://api.example.test/invoke'],
            ])
            ->assertCreated()
            ->json('data');

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/external-adapters/{$original['id']}/test")
            ->assertOk();

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/external-adapters/{$original['id']}/activate")
            ->assertOk();

        $this->actingAsMfaVerified($admin)
            ->postJson('/api/external-adapters/invoke', [
                'capability' => 'send_email',
                'branch_id' => $this->branch->id,
                'payload' => ['to' => 'member@example.com'],
                'idempotency_key' => 'drain-op-1',
            ])
            ->assertCreated();

        $replacement = $this->actingAsMfaVerified($admin)
            ->postJson("/api/external-adapters/{$original['id']}/replace", [
                'name' => 'Mailgun email',
                'provider' => 'mailgun',
                'credentials' => ['api_key' => 'key-mailgun-1234', 'domain' => 'church.test'],
                'callback_urls' => ['health_base_url' => 'https://api.mailgun.test', 'invoke_url' => 'https://api.example.test/invoke'],
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame('active', $replacement['status']);
        $this->assertSame('mailgun', $replacement['provider']);

        $old = ExternalServiceAdapter::query()->findOrFail($original['id']);
        $this->assertSame(ExternalServiceAdapter::STATUS_DRAINING, $old->status);
        $this->assertSame($replacement['id'], $old->replaced_by_id);

        $this->actingAsMfaVerified($admin)
            ->postJson('/api/external-adapters/process-due')
            ->assertOk()
            ->assertJsonPath('data.completed', 1);

        $old->refresh();
        $this->assertSame(ExternalServiceAdapter::STATUS_DISABLED, $old->status);
        $this->assertDatabaseHas('audit_events', ['action' => 'external_adapter.replaced']);
    }
}
