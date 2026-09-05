<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\ExternalServiceAdapter;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Story 15.5: configure approved external service adapters.
 */
class ExternalAdapterService
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
        $this->assertCan($actor, 'adapters.read');

        return [
            'adapter_types' => config('external_adapters.adapter_types', []),
            'providers' => config('external_adapters.providers', []),
            'environments' => config('external_adapters.environments', []),
            'drain_policies' => config('external_adapters.drain_policies', []),
        ];
    }

    /**
     * @return Collection<int, ExternalServiceAdapter>
     */
    public function list(User $actor): Collection
    {
        $this->assertCan($actor, 'adapters.read');

        $query = ExternalServiceAdapter::query()->orderByDesc('id');
        $this->applyBranchScope($query, $actor);

        return $query->limit(100)->get();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(User $actor, array $payload): ExternalServiceAdapter
    {
        $this->assertCan($actor, 'adapters.manage');

        $validated = $this->validatePayload($payload);
        $credentials = $validated['credentials'];
        unset($validated['credentials']);

        $adapter = ExternalServiceAdapter::create([
            'reference' => (string) Str::uuid(),
            'name' => $validated['name'],
            'adapter_type' => $validated['adapter_type'],
            'provider' => $validated['provider'],
            'environment' => $validated['environment'] ?? 'sandbox',
            'branch_id' => $validated['branch_id'] ?? $actor->branch_id,
            'credentials_encrypted' => $credentials,
            'credential_hints' => $this->hints($credentials),
            'mappings' => $validated['mappings'] ?? [],
            'quotas' => $validated['quotas'] ?? [],
            'callback_urls' => $validated['callback_urls'] ?? [],
            'feature_flags' => $validated['feature_flags'] ?? [],
            'status' => ExternalServiceAdapter::STATUS_DRAFT,
            'drain_policy' => $validated['drain_policy'] ?? 'drain',
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        $this->audit($actor, 'external_adapter.created', $adapter, [
            'provider' => $adapter->provider,
            'adapter_type' => $adapter->adapter_type,
            'credential_keys' => array_keys($credentials),
        ]);

        return $adapter->fresh();
    }

    public function show(User $actor, ExternalServiceAdapter $adapter): ExternalServiceAdapter
    {
        $this->assertCan($actor, 'adapters.read');
        $this->assertInScope($actor, $adapter);

        return $adapter->load([
            'operations' => fn ($query) => $query->orderByDesc('id')->limit(20),
            'replacement',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(User $actor, ExternalServiceAdapter $adapter, array $payload): ExternalServiceAdapter
    {
        $this->assertCan($actor, 'adapters.manage');
        $this->assertInScope($actor, $adapter);

        $validated = $this->validatePayload($payload, partial: true);
        $updates = collect($validated)->except(['credentials'])->all();

        if (isset($validated['credentials'])) {
            $updates['credentials_encrypted'] = $validated['credentials'];
            $updates['credential_hints'] = $this->hints($validated['credentials']);
        }

        $updates['updated_by'] = $actor->id;
        $adapter->update($updates);

        $this->audit($actor, 'external_adapter.updated', $adapter, [
            'credentials_rotated' => isset($validated['credentials']),
        ]);

        return $adapter->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function testConnection(User $actor, ExternalServiceAdapter $adapter): array
    {
        $this->assertCan($actor, 'adapters.manage');
        $this->assertInScope($actor, $adapter);

        $provider = $this->providerMeta($adapter->provider);
        $credentials = $adapter->credentials_encrypted ?? [];
        $baseUrl = (string) ($adapter->callback_urls['health_base_url'] ?? 'https://api.example.test');
        $healthPath = (string) ($provider['health_path'] ?? '/health');

        $required = $provider['credential_fields'] ?? [];
        $credentialsPresent = collect($required)->every(
            fn (string $field) => isset($credentials[$field]) && (string) $credentials[$field] !== '',
        );

        $pingOk = false;
        if ($credentialsPresent) {
            try {
                $response = Http::timeout(5)->get(rtrim($baseUrl, '/') . $healthPath);
                $pingOk = $response->successful();
            } catch (\Throwable) {
                $pingOk = false;
            }
        }

        $passed = $credentialsPresent && $pingOk;
        $details = [
            'provider' => $adapter->provider,
            'adapter_type' => $adapter->adapter_type,
            'environment' => $adapter->environment,
            'credentials_present' => $credentialsPresent,
            'provider_ping' => $pingOk ? 'ok' : 'failed',
        ];

        $adapter->update([
            'last_tested_at' => now(),
            'last_test_result' => $passed ? 'passed' : 'failed',
            'last_test_details' => $details,
            'status' => $passed ? ExternalServiceAdapter::STATUS_TESTED : $adapter->status,
            'updated_by' => $actor->id,
        ]);

        Log::info('external_adapter.connection_tested', [
            'adapter_id' => $adapter->id,
            'provider' => $adapter->provider,
            'result' => $passed ? 'passed' : 'failed',
        ]);

        $this->audit($actor, 'external_adapter.tested', $adapter, ['result' => $passed ? 'passed' : 'failed']);

        if (! $passed) {
            throw new ExternalAdapterException('Connection test failed.', 'test_failed', 422, $details);
        }

        return ['passed' => true, 'details' => $details, 'status' => $adapter->fresh()->status];
    }

    public function activate(User $actor, ExternalServiceAdapter $adapter): ExternalServiceAdapter
    {
        $this->assertCan($actor, 'adapters.manage');
        $this->assertInScope($actor, $adapter);

        if ($adapter->status !== ExternalServiceAdapter::STATUS_TESTED) {
            throw new ExternalAdapterException('Adapter must pass a connection test before activation.', 'activation_requires_test', 422);
        }

        ExternalServiceAdapter::query()
            ->where('adapter_type', $adapter->adapter_type)
            ->where('branch_id', $adapter->branch_id)
            ->where('status', ExternalServiceAdapter::STATUS_ACTIVE)
            ->update([
                'status' => ExternalServiceAdapter::STATUS_DRAINING,
                'disabled_at' => now(),
                'updated_by' => $actor->id,
            ]);

        $adapter->update([
            'status' => ExternalServiceAdapter::STATUS_ACTIVE,
            'effective_at' => now(),
            'disabled_at' => null,
            'updated_by' => $actor->id,
        ]);

        $this->audit($actor, 'external_adapter.activated', $adapter);

        return $adapter->fresh();
    }

    public function disable(User $actor, ExternalServiceAdapter $adapter, string $drainPolicy = 'drain'): ExternalServiceAdapter
    {
        $this->assertCan($actor, 'adapters.manage');
        $this->assertInScope($actor, $adapter);

        $adapter->update([
            'status' => ExternalServiceAdapter::STATUS_DRAINING,
            'drain_policy' => $drainPolicy,
            'disabled_at' => now(),
            'updated_by' => $actor->id,
        ]);

        $this->audit($actor, 'external_adapter.disabled', $adapter, ['drain_policy' => $drainPolicy]);

        return $adapter->fresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function replace(User $actor, ExternalServiceAdapter $adapter, array $payload): ExternalServiceAdapter
    {
        $replacement = $this->create($actor, array_merge($payload, [
            'adapter_type' => $adapter->adapter_type,
            'branch_id' => $adapter->branch_id,
        ]));

        $this->testConnection($actor, $replacement);
        $replacement = $this->activate($actor, $replacement);

        $adapter->update([
            'status' => ExternalServiceAdapter::STATUS_DRAINING,
            'replaced_by_id' => $replacement->id,
            'disabled_at' => now(),
            'updated_by' => $actor->id,
        ]);

        $this->audit($actor, 'external_adapter.replaced', $adapter, [
            'replacement_reference' => $replacement->reference,
        ]);

        return $replacement->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function format(ExternalServiceAdapter $adapter): array
    {
        return [
            'id' => $adapter->id,
            'reference' => $adapter->reference,
            'name' => $adapter->name,
            'adapter_type' => $adapter->adapter_type,
            'provider' => $adapter->provider,
            'environment' => $adapter->environment,
            'branch_id' => $adapter->branch_id,
            'status' => $adapter->status,
            'drain_policy' => $adapter->drain_policy,
            'credential_hints' => $adapter->credential_hints,
            'mappings' => $adapter->mappings,
            'quotas' => $adapter->quotas,
            'callback_urls' => $adapter->callback_urls,
            'feature_flags' => $adapter->feature_flags,
            'replaced_by_id' => $adapter->replaced_by_id,
            'effective_at' => $adapter->effective_at?->toIso8601String(),
            'disabled_at' => $adapter->disabled_at?->toIso8601String(),
            'last_tested_at' => $adapter->last_tested_at?->toIso8601String(),
            'last_test_result' => $adapter->last_test_result,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validatePayload(array $payload, bool $partial = false): array
    {
        $providers = array_keys(config('external_adapters.providers', []));
        $types = array_keys(config('external_adapters.adapter_types', []));

        $rules = [
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:180'],
            'adapter_type' => [$partial ? 'sometimes' : 'required', 'string', Rule::in($types)],
            'provider' => [$partial ? 'sometimes' : 'required', 'string', Rule::in($providers)],
            'environment' => ['nullable', 'string', Rule::in(config('external_adapters.environments', []))],
            'branch_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'credentials' => [$partial ? 'sometimes' : 'required', 'array', 'min:1'],
            'mappings' => ['nullable', 'array'],
            'quotas' => ['nullable', 'array'],
            'callback_urls' => ['nullable', 'array'],
            'feature_flags' => ['nullable', 'array'],
            'drain_policy' => ['nullable', 'string', Rule::in(config('external_adapters.drain_policies', []))],
        ];

        $validated = validator($payload, $rules)->validate();

        if (isset($validated['provider'], $validated['adapter_type'])) {
            $providerType = config('external_adapters.providers', [])[$validated['provider']]['adapter_type'] ?? null;
            if ($providerType !== $validated['adapter_type']) {
                throw ValidationException::withMessages([
                    'provider' => ['Provider does not match the selected adapter type.'],
                ]);
            }
        } elseif (isset($validated['provider'])) {
            $validated['adapter_type'] = config('external_adapters.providers', [])[$validated['provider']]['adapter_type'] ?? null;
        }

        if (isset($validated['credentials'], $validated['provider'])) {
            $this->assertCredentialFields($validated['provider'], $validated['credentials']);
        }

        return $validated;
    }

    /**
     * @param  array<string, string>  $credentials
     */
    private function assertCredentialFields(string $provider, array $credentials): void
    {
        $required = config('external_adapters.providers', [])[$provider]['credential_fields'] ?? [];
        foreach ($required as $field) {
            if (! isset($credentials[$field]) || (string) $credentials[$field] === '') {
                throw ValidationException::withMessages([
                    "credentials.{$field}" => ["The {$field} credential is required for {$provider}."],
                ]);
            }
        }
    }

    /**
     * @param  array<string, string>  $credentials
     * @return array<string, string>
     */
    private function hints(array $credentials): array
    {
        $hints = [];
        foreach ($credentials as $key => $value) {
            $hints[$key] = is_string($value) && strlen($value) >= 4
                ? '…' . substr($value, -4)
                : 'set';
        }

        return $hints;
    }

    /**
     * @return array<string, mixed>
     */
    private function providerMeta(string $provider): array
    {
        return config('external_adapters.providers', [])[$provider] ?? [];
    }

    private function applyBranchScope(Builder $query, User $actor): void
    {
        if ($this->authorization->allows($actor, 'organizations.read')) {
            return;
        }

        if ($actor->branch_id !== null) {
            $query->where(function (Builder $builder) use ($actor): void {
                $builder->whereNull('branch_id')->orWhere('branch_id', $actor->branch_id);
            });
        }
    }

    private function assertInScope(User $actor, ExternalServiceAdapter $adapter): void
    {
        if ($adapter->branch_id === null) {
            return;
        }

        if ($actor->branch_id !== null && $adapter->branch_id !== $actor->branch_id
            && ! $this->authorization->allows($actor, 'organizations.read')) {
            throw new AuthorizationException('Forbidden.');
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function audit(User $actor, string $action, ExternalServiceAdapter $adapter, array $extra = []): void
    {
        $this->audit->record(
            actor: $actor,
            action: $action,
            category: AuditEvent::CATEGORY_SECURITY,
            module: 'external_adapters',
            branchId: $adapter->branch_id,
            subjectType: ExternalServiceAdapter::class,
            subjectId: $adapter->id,
            after: array_merge([
                'reference' => $adapter->reference,
                'provider' => $adapter->provider,
            ], $extra),
        );
    }

    private function assertCan(User $actor, string $action): void
    {
        if (! $this->authorization->allows($actor, $action)) {
            throw new AuthorizationException('Forbidden.');
        }
    }
}
