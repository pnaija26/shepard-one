<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\User;
use App\Models\WebhookSubscription;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Story 15.4: outbound webhook subscription management.
 */
class WebhookSubscriptionService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function catalog(User $actor): array
    {
        $this->assertCan($actor, 'webhooks.read');

        return [
            'payload_version' => config('outbound_webhooks.payload_version', '1'),
            'event_types' => config('outbound_webhooks.event_types', []),
            'sensitive_categories' => config('outbound_webhooks.sensitive_categories', []),
            'max_attempts' => config('outbound_webhooks.max_attempts', 5),
            'timeout_seconds' => config('outbound_webhooks.timeout_seconds', 10),
        ];
    }

    /**
     * @return Collection<int, WebhookSubscription>
     */
    public function list(User $actor): Collection
    {
        $this->assertCan($actor, 'webhooks.read');

        $query = WebhookSubscription::query()->orderByDesc('id');
        $this->applyBranchScope($query, $actor);

        return $query->limit(100)->get();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(User $actor, array $payload): array
    {
        $this->assertCan($actor, 'webhooks.manage');

        $validated = $this->validateSubscriptionPayload($payload);
        $this->assertEventPolicy($validated);

        $secret = $validated['signing_secret'] ?? ('whsec_' . Str::random(32));
        unset($validated['signing_secret']);

        $subscription = WebhookSubscription::create([
            'reference' => (string) Str::uuid(),
            'name' => $validated['name'],
            'endpoint_url' => $validated['endpoint_url'],
            'signing_secret_encrypted' => $secret,
            'signing_secret_hint' => $this->hint($secret),
            'allowed_event_types' => array_values($validated['allowed_event_types']),
            'branch_id' => $validated['branch_id'] ?? $actor->branch_id,
            'status' => WebhookSubscription::STATUS_DRAFT,
            'sensitive_payload_approved' => (bool) ($validated['sensitive_payload_approved'] ?? false),
            'verification_token' => Str::random(40),
            'created_by' => $actor->id,
        ]);

        $this->audit($actor, 'webhook.subscription_created', $subscription, [
            'endpoint_host' => parse_url($subscription->endpoint_url, PHP_URL_HOST),
            'event_types' => $subscription->allowed_event_types,
            'has_signing_secret' => true,
        ]);

        return [
            'subscription' => $subscription->fresh(),
            'signing_secret' => $secret,
        ];
    }

    public function show(User $actor, WebhookSubscription $subscription): WebhookSubscription
    {
        $this->assertCan($actor, 'webhooks.read');
        $this->assertInScope($actor, $subscription);

        return $subscription->load([
            'deliveries' => fn ($query) => $query->orderByDesc('id')->limit(20),
        ]);
    }

    public function verify(User $actor, WebhookSubscription $subscription): WebhookSubscription
    {
        $this->assertCan($actor, 'webhooks.manage');
        $this->assertInScope($actor, $subscription);

        if ($subscription->status === WebhookSubscription::STATUS_REVOKED) {
            throw new WebhookException('Revoked subscriptions cannot be verified.', 'subscription_revoked', 422);
        }

        $challenge = $subscription->verification_token;
        $timeout = (int) config('outbound_webhooks.timeout_seconds', 10);

        $response = Http::timeout($timeout)->post($subscription->endpoint_url, [
            'type' => 'webhook.verify',
            'challenge' => $challenge,
        ]);

        if (! $response->successful()) {
            throw new WebhookException('Endpoint verification failed.', 'verification_failed', 422, [
                'http_status' => $response->status(),
            ]);
        }

        $body = $response->json();
        if (! is_array($body) || ($body['challenge'] ?? null) !== $challenge) {
            throw new WebhookException('Endpoint did not echo the verification challenge.', 'verification_invalid', 422);
        }

        $subscription->update([
            'verified_at' => now(),
            'status' => WebhookSubscription::STATUS_ACTIVE,
            'consecutive_failures' => 0,
            'quarantined_at' => null,
        ]);

        $this->audit($actor, 'webhook.subscription_verified', $subscription);

        return $subscription->fresh();
    }

    public function revoke(User $actor, WebhookSubscription $subscription): WebhookSubscription
    {
        $this->assertCan($actor, 'webhooks.manage');
        $this->assertInScope($actor, $subscription);

        $subscription->update([
            'status' => WebhookSubscription::STATUS_REVOKED,
            'revoked_at' => now(),
            'revoked_by' => $actor->id,
        ]);

        $this->audit($actor, 'webhook.subscription_revoked', $subscription);

        return $subscription->fresh();
    }

    /**
     * @return array{subscription: WebhookSubscription, signing_secret: string}
     */
    public function rotateSecret(User $actor, WebhookSubscription $subscription): array
    {
        $this->assertCan($actor, 'webhooks.manage');
        $this->assertInScope($actor, $subscription);

        if ($subscription->status === WebhookSubscription::STATUS_REVOKED) {
            throw new WebhookException('Revoked subscriptions cannot rotate secrets.', 'subscription_revoked', 422);
        }

        $secret = 'whsec_' . Str::random(32);
        $subscription->update([
            'signing_secret_encrypted' => $secret,
            'signing_secret_hint' => $this->hint($secret),
        ]);

        $this->audit($actor, 'webhook.secret_rotated', $subscription);

        return [
            'subscription' => $subscription->fresh(),
            'signing_secret' => $secret,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function format(WebhookSubscription $subscription): array
    {
        return [
            'id' => $subscription->id,
            'reference' => $subscription->reference,
            'name' => $subscription->name,
            'endpoint_url' => $subscription->endpoint_url,
            'allowed_event_types' => $subscription->allowed_event_types,
            'branch_id' => $subscription->branch_id,
            'status' => $subscription->status,
            'sensitive_payload_approved' => $subscription->sensitive_payload_approved,
            'signing_secret_hint' => $subscription->signing_secret_hint,
            'verified_at' => $subscription->verified_at?->toIso8601String(),
            'consecutive_failures' => $subscription->consecutive_failures,
            'quarantined_at' => $subscription->quarantined_at?->toIso8601String(),
            'revoked_at' => $subscription->revoked_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateSubscriptionPayload(array $payload): array
    {
        return validator($payload, [
            'name' => ['required', 'string', 'max:180'],
            'endpoint_url' => ['required', 'url', 'regex:/^https:\/\//', 'max:2048'],
            'allowed_event_types' => ['required', 'array', 'min:1'],
            'allowed_event_types.*' => ['string', Rule::in(array_keys(config('outbound_webhooks.event_types', [])))],
            'branch_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'sensitive_payload_approved' => ['nullable', 'boolean'],
            'signing_secret' => ['nullable', 'string', 'min:16', 'max:500'],
        ])->validate();
    }

    /**
     * @return array<string, mixed>
     */
    private function eventTypeMeta(string $eventType): array
    {
        return config('outbound_webhooks.event_types', [])[$eventType] ?? [];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function assertEventPolicy(array $validated): void
    {
        foreach ($validated['allowed_event_types'] as $eventType) {
            $meta = $this->eventTypeMeta($eventType);
            if (($meta['requires_sensitive_approval'] ?? false) && ! ($validated['sensitive_payload_approved'] ?? false)) {
                throw new WebhookException(
                    'Sensitive event types require explicit policy approval.',
                    'sensitive_approval_required',
                    422,
                    ['event_type' => $eventType],
                );
            }
        }
    }

    private function hint(?string $secret): ?string
    {
        if ($secret === null || $secret === '') {
            return null;
        }

        return '…' . substr($secret, -4);
    }

    private function applyBranchScope($query, User $actor): void
    {
        if ($this->authorization->allows($actor, 'organizations.read')) {
            return;
        }

        if ($actor->branch_id !== null) {
            $query->where(function ($builder) use ($actor): void {
                $builder->whereNull('branch_id')->orWhere('branch_id', $actor->branch_id);
            });
        }
    }

    private function assertInScope(User $actor, WebhookSubscription $subscription): void
    {
        if ($subscription->branch_id === null) {
            return;
        }

        if ($actor->branch_id !== null && $subscription->branch_id !== $actor->branch_id
            && ! $this->authorization->allows($actor, 'organizations.read')) {
            throw new AuthorizationException('Forbidden.');
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function audit(User $actor, string $action, WebhookSubscription $subscription, array $extra = []): void
    {
        $this->audit->record(
            actor: $actor,
            action: $action,
            category: AuditEvent::CATEGORY_SECURITY,
            module: 'webhooks',
            branchId: $subscription->branch_id,
            subjectType: WebhookSubscription::class,
            subjectId: $subscription->id,
            after: array_merge(['reference' => $subscription->reference], $extra),
        );
    }

    private function assertCan(User $actor, string $action): void
    {
        if (! $this->authorization->allows($actor, $action)) {
            throw new AuthorizationException('Forbidden.');
        }
    }
}
