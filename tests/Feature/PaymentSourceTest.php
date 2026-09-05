<?php

namespace Tests\Feature;

use App\Models\Contribution;
use App\Models\Member;
use App\Models\Organization;
use App\Models\PaymentSource;
use App\Models\PaymentWebhookEvent;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 11.1: Connect an Approved Payment Source.
 */
class PaymentSourceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'PAY-HQ']);
        $this->branch = Organization::create(['name' => 'Branch A', 'type' => 'branch', 'identifier' => 'PAY-A', 'parent_id' => $hq->id]);
    }

    private function grant(User $user, array $actions): void
    {
        $role = Role::create(['name' => 'pay_' . $user->id . '_' . substr(md5(implode(',', $actions)), 0, 6)]);
        foreach ($actions as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);
    }

    private function financeAdmin(): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $this->grant($user, [
            'payments.sources.read',
            'payments.sources.manage',
        ]);

        return $user;
    }

    private function sign(string $secret, array $payload, ?int $timestamp = null): array
    {
        $timestamp ??= now()->timestamp;
        $raw = json_encode($payload);
        $sig = hash_hmac('sha256', $timestamp . '.' . $raw, $secret);

        return [
            'raw' => $raw,
            'header' => 't=' . $timestamp . ',v1=' . $sig,
        ];
    }

    public function test_finance_admin_configures_and_tests_source_without_exposing_secrets(): void
    {
        $admin = $this->financeAdmin();

        $created = $this->actingAsMfaVerified($admin)
            ->postJson('/api/payment-sources', [
                'name' => 'Stripe Live',
                'provider' => 'stripe',
                'environment' => 'sandbox',
                'currency' => 'USD',
                'branch_id' => $this->branch->id,
                'supported_categories' => ['tithe', 'offering'],
                'branch_mapping' => ['A' => $this->branch->id],
                'api_key' => 'sk_test_super_secret_key_12345',
                'webhook_secret' => 'whsec_super_secret_webhook_999',
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame('draft', $created['status']);
        $this->assertTrue($created['has_api_key']);
        $this->assertTrue($created['has_webhook_secret']);
        $this->assertSame('…2345', $created['api_key_hint']);
        $this->assertArrayNotHasKey('api_key', $created);
        $this->assertArrayNotHasKey('api_key_encrypted', $created);
        $this->assertArrayNotHasKey('webhook_secret', $created);

        $source = PaymentSource::query()->find($created['id']);
        $this->assertNotSame('sk_test_super_secret_key_12345', $source->getRawOriginal('api_key_encrypted'));
        $this->assertSame('sk_test_super_secret_key_12345', $source->api_key_encrypted);

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/payment-sources/{$created['id']}/test")
            ->assertOk()
            ->assertJsonPath('data.passed', true)
            ->assertJsonPath('data.details.api_key_present', true)
            ->assertJsonMissing(['sk_test_super_secret_key_12345']);

        $this->actingAsMfaVerified($admin)
            ->putJson("/api/payment-sources/{$created['id']}", ['enabled' => true])
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.status', 'active');

        $this->actingAsMfaVerified($admin)
            ->postJson('/api/payment-sources', [
                'name' => 'Discord Pay',
                'provider' => 'discord',
                'supported_categories' => ['tithe'],
                'api_key' => 'not-allowed-key-123456',
                'webhook_secret' => 'not-allowed-secret-123',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'unsupported_provider');

        $outsider = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $this->actingAsMfaVerified($outsider)
            ->getJson('/api/payment-sources')
            ->assertForbidden();
    }

    public function test_signed_webhook_is_idempotent_and_rejects_invalid_signature_replay_and_amount_conflict(): void
    {
        $admin = $this->financeAdmin();
        $secret = 'whsec_test_signature_secret_abc';

        $member = Member::create([
            'membership_id' => 'PAY-M-1',
            'branch_id' => $this->branch->id,
            'user_id' => User::factory()->create(['branch_id' => $this->branch->id, 'email' => 'donor@church.test'])->id,
            'registration_channel' => 'web',
            'first_name' => 'Donor',
            'last_name' => 'One',
            'email' => 'donor@church.test',
            'consent_data_processing' => true,
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
        ]);

        $sourceId = $this->actingAsMfaVerified($admin)
            ->postJson('/api/payment-sources', [
                'name' => 'Paystack Sandbox',
                'provider' => 'paystack',
                'environment' => 'sandbox',
                'currency' => 'NGN',
                'branch_id' => $this->branch->id,
                'supported_categories' => ['tithe', 'offering'],
                'api_key' => 'pk_test_paystack_key_aaaa',
                'webhook_secret' => $secret,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($admin)->postJson("/api/payment-sources/{$sourceId}/test")->assertOk();
        $this->actingAsMfaVerified($admin)->putJson("/api/payment-sources/{$sourceId}", ['enabled' => true])->assertOk();

        $payload = [
            'event_id' => 'evt_001',
            'payment_reference' => 'txn_abc_001',
            'amount_cents' => 500000,
            'currency' => 'NGN',
            'category' => 'tithe',
            'status' => 'succeeded',
            'environment' => 'sandbox',
            'payment_source_id' => $sourceId,
            'payer_email' => 'donor@church.test',
            'api_key' => 'should-not-be-stored',
        ];
        $signed = $this->sign($secret, $payload);

        $this->call(
            'POST',
            '/api/webhooks/payments/paystack',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-Payment-Signature' => $signed['header'],
            ],
            $signed['raw'],
        )->assertCreated()
            ->assertJsonPath('data.status', 'processed');

        $contribution = Contribution::query()->first();
        $this->assertNotNull($contribution);
        $this->assertSame(500000, $contribution->amount_cents);
        $this->assertSame($member->id, $contribution->member_id);
        $this->assertTrue($contribution->payer_linked);
        $this->assertArrayNotHasKey('api_key', $contribution->provider_evidence ?? []);

        // Replay same event.
        $this->call(
            'POST',
            '/api/webhooks/payments/paystack',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-Payment-Signature' => $signed['header'],
            ],
            $signed['raw'],
        )->assertOk()
            ->assertJsonPath('data.status', 'replayed');

        $this->assertSame(1, Contribution::query()->count());
        $this->assertTrue(
            PaymentWebhookEvent::query()->where('status', PaymentWebhookEvent::STATUS_REPLAYED)->exists()
        );

        // Invalid signature.
        $bad = $payload;
        $bad['event_id'] = 'evt_bad_sig';
        $badRaw = json_encode($bad);
        $this->call(
            'POST',
            '/api/webhooks/payments/paystack',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-Payment-Signature' => 't=' . now()->timestamp . ',v1=deadbeef',
            ],
            $badRaw,
        )->assertStatus(401)
            ->assertJsonPath('code', 'invalid_signature');

        $this->assertTrue(
            PaymentWebhookEvent::query()->where('reject_reason', 'invalid_signature')->exists()
        );

        // Amount conflict on same payment reference, new event id.
        $conflict = $payload;
        $conflict['event_id'] = 'evt_002';
        $conflict['amount_cents'] = 999999;
        $conflictSigned = $this->sign($secret, $conflict);
        $this->call(
            'POST',
            '/api/webhooks/payments/paystack',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-Payment-Signature' => $conflictSigned['header'],
            ],
            $conflictSigned['raw'],
        )->assertStatus(409)
            ->assertJsonPath('code', 'amount_conflict');

        $this->assertTrue(
            PaymentWebhookEvent::query()->where('status', PaymentWebhookEvent::STATUS_CONFLICT)->exists()
        );
        $this->assertSame(1, Contribution::query()->count());
    }
}
