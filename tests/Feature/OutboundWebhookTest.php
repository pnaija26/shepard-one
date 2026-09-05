<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Story 15.4: reliable outbound webhook delivery.
 */
class OutboundWebhookTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;

    private string $endpoint = 'https://integrations.example.test/hooks/shepard';

    protected function setUp(): void
    {
        parent::setUp();

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'WH-HQ']);
        $this->branch = Organization::create([
            'name' => 'Branch A',
            'type' => 'branch',
            'identifier' => 'WH-A',
            'parent_id' => $hq->id,
        ]);
    }

    /**
     * @param  list<string>  $actions
     */
    private function grant(User $user, array $actions): void
    {
        $role = Role::create(['name' => 'wh_' . $user->id . '_' . substr(md5(implode(',', $actions)), 0, 6)]);
        foreach ($actions as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);
    }

    private function admin(): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $this->grant($user, ['webhooks.read', 'webhooks.manage']);

        return $user;
    }

    public function test_admin_configures_verifies_and_delivers_signed_idempotent_webhook(): void
    {
        $admin = $this->admin();

        $created = $this->actingAsMfaVerified($admin)
            ->postJson('/api/outbound-webhooks/subscriptions', [
                'name' => 'Directory sync',
                'endpoint_url' => $this->endpoint,
                'allowed_event_types' => ['member.created'],
                'branch_id' => $this->branch->id,
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame('draft', $created['status']);
        $this->assertArrayHasKey('signing_secret', $created);
        $this->assertArrayNotHasKey('signing_secret_encrypted', $created);
        $this->assertStringStartsWith('…', $created['signing_secret_hint']);

        $subscription = WebhookSubscription::query()->findOrFail($created['id']);

        Http::fake([
            $this->endpoint => Http::sequence()
                ->push(['challenge' => $subscription->verification_token], 200)
                ->push(['received' => true], 200)
                ->push(['received' => true], 200),
        ]);

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/outbound-webhooks/subscriptions/{$subscription->id}/verify")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $dispatch = $this->actingAsMfaVerified($admin)
            ->postJson('/api/outbound-webhooks/dispatch', [
                'event_type' => 'member.created',
                'branch_id' => $this->branch->id,
                'payload' => [
                    'id' => 10,
                    'membership_id' => 'M-10',
                    'first_name' => 'Ada',
                    'last_name' => 'Member',
                    'branch_id' => $this->branch->id,
                    'lifecycle_status' => 'active',
                    'email' => 'should-be-filtered@example.com',
                ],
                'idempotency_key' => 'member-created-10',
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame(1, $dispatch['queued']);

        $this->actingAsMfaVerified($admin)
            ->postJson('/api/outbound-webhooks/dispatch', [
                'event_type' => 'member.created',
                'branch_id' => $this->branch->id,
                'payload' => ['id' => 10, 'membership_id' => 'M-10'],
                'idempotency_key' => 'member-created-10',
            ])
            ->assertCreated()
            ->assertJsonPath('data.skipped', 1);

        $result = $this->actingAsMfaVerified($admin)
            ->postJson('/api/outbound-webhooks/process-due')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $result['delivered']);

        Http::assertSent(function ($request) {
            if ($request->url() !== $this->endpoint || $request->method() !== 'POST') {
                return false;
            }

            $payload = json_decode($request->body(), true);
            if (($payload['type'] ?? null) !== 'member.created') {
                return false;
            }

            $this->assertArrayNotHasKey('email', $payload['data'] ?? []);
            $this->assertNotEmpty($request->header('X-Webhook-Signature'));
            $this->assertSame('member-created-10', $request->header('X-Webhook-Idempotency-Key')[0] ?? null);

            return true;
        });

        $this->assertDatabaseHas('webhook_deliveries', [
            'status' => 'delivered',
            'idempotency_key' => 'member-created-10',
        ]);
    }

    public function test_sensitive_events_require_approval_and_failures_quarantine_revoked_subscriptions(): void
    {
        $admin = $this->admin();

        $this->actingAsMfaVerified($admin)
            ->postJson('/api/outbound-webhooks/subscriptions', [
                'name' => 'Finance feed',
                'endpoint_url' => $this->endpoint,
                'allowed_event_types' => ['contribution.reconciled'],
                'branch_id' => $this->branch->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'sensitive_approval_required');

        $created = $this->actingAsMfaVerified($admin)
            ->postJson('/api/outbound-webhooks/subscriptions', [
                'name' => 'Finance feed',
                'endpoint_url' => $this->endpoint,
                'allowed_event_types' => ['contribution.reconciled'],
                'branch_id' => $this->branch->id,
                'sensitive_payload_approved' => true,
            ])
            ->assertCreated()
            ->json('data');

        $subscription = WebhookSubscription::query()->findOrFail($created['id']);

        Http::fake([
            $this->endpoint => Http::sequence()
                ->push(['challenge' => $subscription->verification_token], 200)
                ->push('error', 500)
                ->push('error', 500)
                ->push('error', 500)
                ->push('error', 500)
                ->push('error', 500),
        ]);

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/outbound-webhooks/subscriptions/{$subscription->id}/verify")
            ->assertOk();

        $this->actingAsMfaVerified($admin)
            ->postJson('/api/outbound-webhooks/dispatch', [
                'event_type' => 'contribution.reconciled',
                'branch_id' => $this->branch->id,
                'payload' => [
                    'id' => 55,
                    'amount' => '100.00',
                    'currency' => 'NGN',
                    'category' => 'tithe',
                    'branch_id' => $this->branch->id,
                    'reconciled_at' => now()->toIso8601String(),
                ],
            ])
            ->assertCreated();

        config(['outbound_webhooks.max_attempts' => 1, 'outbound_webhooks.quarantine_after_failures' => 1]);

        $this->actingAsMfaVerified($admin)
            ->postJson('/api/outbound-webhooks/process-due')
            ->assertOk()
            ->assertJsonPath('data.quarantined', 1);

        $subscription->refresh();
        $this->assertSame(WebhookSubscription::STATUS_QUARANTINED, $subscription->status);
        $this->assertDatabaseHas('audit_events', ['action' => 'webhook.subscription_quarantined']);

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/outbound-webhooks/subscriptions/{$subscription->id}/revoke")
            ->assertOk();

        $this->actingAsMfaVerified($admin)
            ->postJson('/api/outbound-webhooks/dispatch', [
                'event_type' => 'contribution.reconciled',
                'branch_id' => $this->branch->id,
                'payload' => ['id' => 99, 'amount' => '10.00', 'currency' => 'NGN', 'category' => 'tithe', 'branch_id' => $this->branch->id, 'reconciled_at' => now()->toIso8601String()],
            ])
            ->assertCreated()
            ->assertJsonPath('data.queued', 0);

        $this->assertSame(1, WebhookDelivery::query()->count());
    }
}
