<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Story 15.4: signed outbound webhook delivery with retries and quarantine.
 */
class WebhookDeliveryService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{queued: int, skipped: int}
     */
    public function dispatchEvent(
        ?User $actor,
        string $eventType,
        array $payload,
        ?int $branchId = null,
        ?string $idempotencyKey = null,
    ): array {
        if ($actor !== null) {
            $this->assertCan($actor, 'webhooks.manage');
        }

        $meta = config('outbound_webhooks.event_types', [])[$eventType] ?? null;
        if ($meta === null) {
            throw new WebhookException('Unknown webhook event type.', 'unknown_event_type', 422);
        }

        $eventId = (string) Str::uuid();
        $filtered = $this->filterPayload($eventType, $payload);
        $counts = ['queued' => 0, 'skipped' => 0];

        $subscriptions = WebhookSubscription::query()
            ->where('status', WebhookSubscription::STATUS_ACTIVE)
            ->whereNull('revoked_at')
            ->whereNotNull('verified_at')
            ->whereJsonContains('allowed_event_types', $eventType)
            ->when($branchId !== null, fn ($query) => $query->where(function ($builder) use ($branchId): void {
                $builder->whereNull('branch_id')->orWhere('branch_id', $branchId);
            }))
            ->get();

        foreach ($subscriptions as $subscription) {
            if (($meta['requires_sensitive_approval'] ?? false) && ! $subscription->sensitive_payload_approved) {
                $counts['skipped']++;

                continue;
            }

            $deliveryKey = $idempotencyKey
                ?? hash('sha256', $eventType . '|' . $eventId . '|' . $subscription->id);

            if (WebhookDelivery::query()->where('idempotency_key', $deliveryKey)->exists()) {
                $counts['skipped']++;

                continue;
            }

            WebhookDelivery::create([
                'webhook_subscription_id' => $subscription->id,
                'event_id' => $eventId,
                'idempotency_key' => $deliveryKey,
                'event_type' => $eventType,
                'payload_version' => (string) config('outbound_webhooks.payload_version', '1'),
                'payload' => $this->envelope($eventId, $eventType, $deliveryKey, $filtered),
                'status' => WebhookDelivery::STATUS_PENDING,
            ]);

            $counts['queued']++;
        }

        if ($actor !== null) {
            $this->audit->record(
                actor: $actor,
                action: 'webhook.event_dispatched',
                category: AuditEvent::CATEGORY_BUSINESS,
                module: 'webhooks',
                branchId: $branchId,
                after: [
                    'event_type' => $eventType,
                    'event_id' => $eventId,
                    'queued' => $counts['queued'],
                    'skipped' => $counts['skipped'],
                ],
            );
        }

        return $counts;
    }

    /**
     * @return array{processed: int, delivered: int, retried: int, quarantined: int, failed: int}
     */
    public function processDue(?User $actor = null): array
    {
        if ($actor !== null) {
            $this->assertCan($actor, 'webhooks.manage');
        }

        $counts = ['processed' => 0, 'delivered' => 0, 'retried' => 0, 'quarantined' => 0, 'failed' => 0];

        $deliveries = WebhookDelivery::query()
            ->whereIn('status', [WebhookDelivery::STATUS_PENDING, WebhookDelivery::STATUS_FAILED])
            ->where(function ($query): void {
                $query->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now());
            })
            ->whereHas('subscription', fn ($query) => $query
                ->where('status', WebhookSubscription::STATUS_ACTIVE)
                ->whereNull('revoked_at'))
            ->orderBy('id')
            ->limit(100)
            ->get();

        foreach ($deliveries as $delivery) {
            $counts['processed']++;
            $outcome = $this->attemptDelivery($delivery);
            $counts[$outcome]++;
        }

        return $counts;
    }

    public function attemptDelivery(WebhookDelivery $delivery): string
    {
        $subscription = $delivery->subscription;
        if ($subscription === null || ! $subscription->isDeliverable()) {
            $delivery->update([
                'status' => WebhookDelivery::STATUS_SKIPPED,
                'last_error_code' => 'subscription_inactive',
            ]);

            return 'failed';
        }

        $delivery->update([
            'status' => WebhookDelivery::STATUS_DELIVERING,
            'attempt' => $delivery->attempt + 1,
        ]);

        $body = json_encode($delivery->payload, JSON_THROW_ON_ERROR);
        $timestamp = now()->timestamp;
        $signature = $this->sign($subscription->signing_secret_encrypted, $timestamp, $body);
        $timeout = (int) config('outbound_webhooks.timeout_seconds', 10);
        $started = microtime(true);

        try {
            $response = Http::timeout($timeout)
                ->withHeaders([
                    config('outbound_webhooks.signature_header', 'X-Webhook-Signature') => 't=' . $timestamp . ',v1=' . $signature,
                    config('outbound_webhooks.idempotency_header', 'X-Webhook-Idempotency-Key') => $delivery->idempotency_key,
                    config('outbound_webhooks.event_id_header', 'X-Webhook-Event-Id') => $delivery->event_id,
                    config('outbound_webhooks.event_type_header', 'X-Webhook-Event-Type') => $delivery->event_type,
                    config('outbound_webhooks.version_header', 'X-Webhook-Version') => $delivery->payload_version,
                    'Content-Type' => 'application/json',
                ])
                ->withBody($body, 'application/json')
                ->post($subscription->endpoint_url);
        } catch (\Throwable $exception) {
            return $this->markFailure($delivery, $subscription, null, 'transport_error', $exception->getMessage(), (int) round((microtime(true) - $started) * 1000));
        }

        $durationMs = (int) round((microtime(true) - $started) * 1000);

        if ($response->successful()) {
            $delivery->update([
                'status' => WebhookDelivery::STATUS_DELIVERED,
                'http_status' => $response->status(),
                'response_excerpt' => Str::limit($response->body(), 500, ''),
                'duration_ms' => $durationMs,
                'delivered_at' => now(),
                'last_error_code' => null,
                'next_retry_at' => null,
            ]);
            $subscription->update(['consecutive_failures' => 0]);

            return 'delivered';
        }

        return $this->markFailure(
            $delivery,
            $subscription,
            $response->status(),
            'http_' . $response->status(),
            Str::limit($response->body(), 500, ''),
            $durationMs,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function formatDelivery(WebhookDelivery $delivery): array
    {
        return [
            'id' => $delivery->id,
            'event_id' => $delivery->event_id,
            'idempotency_key' => $delivery->idempotency_key,
            'event_type' => $delivery->event_type,
            'status' => $delivery->status,
            'attempt' => $delivery->attempt,
            'http_status' => $delivery->http_status,
            'duration_ms' => $delivery->duration_ms,
            'last_error_code' => $delivery->last_error_code,
            'next_retry_at' => $delivery->next_retry_at?->toIso8601String(),
            'delivered_at' => $delivery->delivered_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function filterPayload(string $eventType, array $payload): array
    {
        $allowed = config('outbound_webhooks.event_types', [])[$eventType]['fields'] ?? [];

        return array_intersect_key($payload, array_flip($allowed));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function envelope(string $eventId, string $eventType, string $idempotencyKey, array $data): array
    {
        return [
            'id' => $eventId,
            'type' => $eventType,
            'version' => (string) config('outbound_webhooks.payload_version', '1'),
            'occurred_at' => now()->toIso8601String(),
            'idempotency_key' => $idempotencyKey,
            'data' => $data,
        ];
    }

    private function sign(string $secret, int $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    private function markFailure(
        WebhookDelivery $delivery,
        WebhookSubscription $subscription,
        ?int $httpStatus,
        string $errorCode,
        string $responseExcerpt,
        int $durationMs,
    ): string {
        $maxAttempts = (int) config('outbound_webhooks.max_attempts', 5);
        $backoff = config('outbound_webhooks.backoff_seconds', [60, 300, 900]);
        $attempt = $delivery->attempt;
        $failures = $subscription->consecutive_failures + 1;

        $subscription->update(['consecutive_failures' => $failures]);

        if ($attempt >= $maxAttempts || $failures >= (int) config('outbound_webhooks.quarantine_after_failures', 5)) {
            $delivery->update([
                'status' => WebhookDelivery::STATUS_QUARANTINED,
                'http_status' => $httpStatus,
                'response_excerpt' => $responseExcerpt,
                'duration_ms' => $durationMs,
                'last_error_code' => $errorCode,
                'next_retry_at' => null,
            ]);

            $subscription->update([
                'status' => WebhookSubscription::STATUS_QUARANTINED,
                'quarantined_at' => now(),
            ]);

            $this->audit->record(
                actor: null,
                action: 'webhook.subscription_quarantined',
                category: AuditEvent::CATEGORY_SECURITY,
                module: 'webhooks',
                branchId: $subscription->branch_id,
                subjectType: WebhookSubscription::class,
                subjectId: $subscription->id,
                after: [
                    'reference' => $subscription->reference,
                    'consecutive_failures' => $failures,
                    'last_error_code' => $errorCode,
                ],
            );

            return 'quarantined';
        }

        $delay = $backoff[min($attempt - 1, count($backoff) - 1)] ?? 3600;
        $delivery->update([
            'status' => WebhookDelivery::STATUS_FAILED,
            'http_status' => $httpStatus,
            'response_excerpt' => $responseExcerpt,
            'duration_ms' => $durationMs,
            'last_error_code' => $errorCode,
            'next_retry_at' => now()->addSeconds($delay),
        ]);

        return 'retried';
    }

    private function assertCan(User $actor, string $action): void
    {
        if (! $this->authorization->allows($actor, $action)) {
            throw new AuthorizationException('Forbidden.');
        }
    }
}
