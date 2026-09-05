<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\ExternalAdapterOperation;
use App\Models\ExternalServiceAdapter;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Story 15.5: runtime external adapter invocation with idempotency and drain handling.
 */
class ExternalAdapterRuntimeService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function invoke(
        ?User $actor,
        string $capability,
        array $payload,
        ?int $branchId = null,
        ?string $idempotencyKey = null,
    ): array {
        if ($actor !== null) {
            $this->assertCan($actor, 'adapters.manage');
        }

        $adapterType = $this->capabilityType($capability);
        if ($adapterType === null) {
            throw new ExternalAdapterException('Unknown adapter capability.', 'unknown_capability', 422);
        }

        $adapter = ExternalServiceAdapter::query()
            ->where('adapter_type', $adapterType)
            ->where('status', ExternalServiceAdapter::STATUS_ACTIVE)
            ->when($branchId !== null, fn ($query) => $query->where(function ($builder) use ($branchId): void {
                $builder->whereNull('branch_id')->orWhere('branch_id', $branchId);
            }))
            ->orderByDesc('effective_at')
            ->first();

        if ($adapter === null) {
            throw new ExternalAdapterException('No active adapter is configured for this capability.', 'adapter_unavailable', 422);
        }

        $idempotencyKey ??= hash('sha256', $capability . '|' . json_encode($payload));
        $existing = ExternalAdapterOperation::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return $this->formatOperation($existing);
        }

        $operation = ExternalAdapterOperation::create([
            'external_service_adapter_id' => $adapter->id,
            'correlation_id' => (string) Str::uuid(),
            'idempotency_key' => $idempotencyKey,
            'capability' => $capability,
            'request_payload' => $this->translateRequest($adapter, $capability, $payload),
            'status' => ExternalAdapterOperation::STATUS_PENDING,
            'timeout_ms' => (int) config('external_adapters.default_timeout_ms', 10000),
        ]);

        return $this->formatOperation($operation);
    }

    /**
     * @return array{processed: int, completed: int, retried: int, cancelled: int, failed: int}
     */
    public function processDue(?User $actor = null): array
    {
        if ($actor !== null) {
            $this->assertCan($actor, 'adapters.manage');
        }

        $counts = ['processed' => 0, 'completed' => 0, 'retried' => 0, 'cancelled' => 0, 'failed' => 0];

        $operations = ExternalAdapterOperation::query()
            ->whereIn('status', [ExternalAdapterOperation::STATUS_PENDING, ExternalAdapterOperation::STATUS_FAILED])
            ->where(function ($query): void {
                $query->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now());
            })
            ->orderBy('id')
            ->limit(100)
            ->get();

        foreach ($operations as $operation) {
            $counts['processed']++;
            $adapter = $operation->adapter;
            if ($adapter === null) {
                continue;
            }

            if (in_array($adapter->status, [ExternalServiceAdapter::STATUS_DRAINING, ExternalServiceAdapter::STATUS_DISABLED], true)) {
                $outcome = $this->applyDrainPolicy($operation, $adapter);
                $counts[$outcome]++;

                continue;
            }

            if ($adapter->status !== ExternalServiceAdapter::STATUS_ACTIVE) {
                $counts['failed']++;

                continue;
            }

            $result = $this->execute($operation);
            $counts[$result->status === ExternalAdapterOperation::STATUS_COMPLETED ? 'completed' : 'retried']++;
        }

        $this->finalizeDrainedAdapters();

        return $counts;
    }

    public function execute(ExternalAdapterOperation $operation): ExternalAdapterOperation
    {
        $adapter = $operation->adapter;
        if ($adapter === null) {
            return $operation;
        }

        $operation->update([
            'status' => ExternalAdapterOperation::STATUS_PROCESSING,
            'attempt' => $operation->attempt + 1,
        ]);

        $endpoint = (string) ($adapter->callback_urls['invoke_url'] ?? 'https://api.example.test/invoke');
        $timeout = (int) ($operation->timeout_ms ?? config('external_adapters.default_timeout_ms', 10000));

        try {
            $response = Http::timeout((int) ceil($timeout / 1000))
                ->withHeaders([
                    'X-Correlation-Id' => $operation->correlation_id,
                    'X-Idempotency-Key' => $operation->idempotency_key,
                    'X-Adapter-Provider' => $adapter->provider,
                ])
                ->post($endpoint, [
                    'capability' => $operation->capability,
                    'payload' => $operation->request_payload,
                    'mappings' => $adapter->mappings,
                ]);

            if ($response->successful()) {
                $operation->update([
                    'status' => ExternalAdapterOperation::STATUS_COMPLETED,
                    'response_payload' => [
                        'provider' => $adapter->provider,
                        'http_status' => $response->status(),
                        'body' => $response->json() ?? [],
                    ],
                    'completed_at' => now(),
                    'error_code' => null,
                    'next_retry_at' => null,
                ]);

                return $operation->fresh();
            }

            return $this->markFailure($operation, 'http_' . $response->status(), $response->body());
        } catch (\Throwable $exception) {
            return $this->markFailure($operation, 'transport_error', $exception->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function formatOperation(ExternalAdapterOperation $operation): array
    {
        return [
            'id' => $operation->id,
            'correlation_id' => $operation->correlation_id,
            'idempotency_key' => $operation->idempotency_key,
            'capability' => $operation->capability,
            'status' => $operation->status,
            'attempt' => $operation->attempt,
            'adapter_id' => $operation->external_service_adapter_id,
            'response' => $operation->response_payload,
            'error_code' => $operation->error_code,
            'completed_at' => $operation->completed_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function translateRequest(ExternalServiceAdapter $adapter, string $capability, array $payload): array
    {
        $mapping = $adapter->mappings ?? [];

        return [
            'capability' => $capability,
            'provider' => $adapter->provider,
            'mapped' => array_merge($payload, $mapping),
        ];
    }

    private function capabilityType(string $capability): ?string
    {
        foreach (config('external_adapters.adapter_types', []) as $type => $meta) {
            if (in_array($capability, $meta['capabilities'] ?? [], true)) {
                return $type;
            }
        }

        return null;
    }

    private function markFailure(ExternalAdapterOperation $operation, string $errorCode, string $message): ExternalAdapterOperation
    {
        $maxAttempts = (int) config('external_adapters.max_attempts', 3);
        $backoff = config('external_adapters.backoff_seconds', [30, 120, 600]);

        if ($operation->attempt >= $maxAttempts) {
            $operation->update([
                'status' => ExternalAdapterOperation::STATUS_FAILED,
                'error_code' => $errorCode,
                'response_payload' => ['message' => Str::limit($message, 500, '')],
                'next_retry_at' => null,
            ]);

            return $operation->fresh();
        }

        $delay = $backoff[min($operation->attempt - 1, count($backoff) - 1)] ?? 600;
        $operation->update([
            'status' => ExternalAdapterOperation::STATUS_FAILED,
            'error_code' => $errorCode,
            'response_payload' => ['message' => Str::limit($message, 500, '')],
            'next_retry_at' => now()->addSeconds($delay),
        ]);

        return $operation->fresh();
    }

    private function applyDrainPolicy(ExternalAdapterOperation $operation, ExternalServiceAdapter $adapter): string
    {
        return match ($adapter->drain_policy) {
            'cancel' => $this->cancelOperation($operation),
            'retry' => $this->execute($operation)->status === ExternalAdapterOperation::STATUS_COMPLETED ? 'completed' : 'retried',
            default => $this->execute($operation)->status === ExternalAdapterOperation::STATUS_COMPLETED ? 'completed' : 'retried',
        };
    }

    private function cancelOperation(ExternalAdapterOperation $operation): string
    {
        $operation->update([
            'status' => ExternalAdapterOperation::STATUS_CANCELLED,
            'error_code' => 'adapter_draining',
            'completed_at' => now(),
        ]);

        return 'cancelled';
    }

    private function finalizeDrainedAdapters(): void
    {
        ExternalServiceAdapter::query()
            ->where('status', ExternalServiceAdapter::STATUS_DRAINING)
            ->each(function (ExternalServiceAdapter $adapter): void {
                $pending = ExternalAdapterOperation::query()
                    ->where('external_service_adapter_id', $adapter->id)
                    ->whereIn('status', [
                        ExternalAdapterOperation::STATUS_PENDING,
                        ExternalAdapterOperation::STATUS_FAILED,
                        ExternalAdapterOperation::STATUS_PROCESSING,
                    ])
                    ->exists();

                if (! $pending) {
                    $adapter->update(['status' => ExternalServiceAdapter::STATUS_DISABLED]);
                    $this->audit->record(
                        actor: null,
                        action: 'external_adapter.drain_complete',
                        category: AuditEvent::CATEGORY_BUSINESS,
                        module: 'external_adapters',
                        branchId: $adapter->branch_id,
                        subjectType: ExternalServiceAdapter::class,
                        subjectId: $adapter->id,
                        after: ['reference' => $adapter->reference],
                    );
                }
            });
    }

    private function assertCan(User $actor, string $action): void
    {
        if (! $this->authorization->allows($actor, $action)) {
            throw new AuthorizationException('Forbidden.');
        }
    }
}
