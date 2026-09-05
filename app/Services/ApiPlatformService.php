<?php

namespace App\Services;

use App\Models\ApiAccessEvent;
use App\Models\ApiClient;
use App\Models\AuditEvent;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Story 15.3: API clients, machine principals, rate limits, and access logging.
 */
class ApiPlatformService
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
        $this->assertCan($actor, 'api.platform.read');

        return [
            'version' => config('api_platform.version', '1'),
            'auth_methods' => config('api_platform.auth_methods', []),
            'rate_limits' => config('api_platform.rate_limits', []),
            'deprecation_policy' => config('api_platform.deprecation_policy', []),
            'pagination' => config('api_platform.pagination', []),
            'error_codes' => config('api_platform.error_codes', []),
            'scopes' => $this->scopeCatalog(),
        ];
    }

    /**
     * @return Collection<int, ApiClient>
     */
    public function listClients(User $actor): Collection
    {
        $this->assertCan($actor, 'api.platform.read');

        return ApiClient::query()
            ->with(['user', 'branch'])
            ->orderByDesc('id')
            ->limit(100)
            ->get();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{client: ApiClient, client_secret: string}
     */
    public function createClient(User $actor, array $payload): array
    {
        $this->assertCan($actor, 'api.platform.manage');

        $validated = validator($payload, [
            'name' => ['required', 'string', 'max:180'],
            'allowed_scopes' => ['required', 'array', 'min:1'],
            'allowed_scopes.*' => ['string', 'in:' . implode(',', array_keys($this->scopeCatalog()))],
            'branch_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'rate_limit_per_minute' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ])->validate();

        $clientId = 'cli_' . Str::lower(Str::random(24));
        $clientSecret = 'sec_' . Str::random(40);

        return DB::transaction(function () use ($actor, $validated, $clientId, $clientSecret): array {
            $serviceUser = User::factory()->create([
                'email' => 'api+' . Str::lower(Str::random(12)) . '@integrations.shepardone.local',
                'password' => bcrypt(Str::random(32)),
                'roles' => ['api_integration'],
                'branch_id' => $validated['branch_id'] ?? $actor->branch_id,
                'has_mfa_enrolled' => false,
            ]);

            $role = Role::create(['name' => 'api_client_' . $serviceUser->id]);
            foreach ($validated['allowed_scopes'] as $scope) {
                RolePermission::create([
                    'role_id' => $role->id,
                    'scope_type' => 'global',
                    'action' => $scope,
                ]);
            }
            RoleAssignment::create([
                'user_id' => $serviceUser->id,
                'role_id' => $role->id,
                'granted_by' => $actor->id,
            ]);

            $client = ApiClient::create([
                'reference' => (string) Str::uuid(),
                'name' => $validated['name'],
                'client_id' => $clientId,
                'secret_hash' => hash('sha256', $clientSecret),
                'principal_type' => ApiClient::PRINCIPAL_MACHINE,
                'allowed_scopes' => $validated['allowed_scopes'],
                'user_id' => $serviceUser->id,
                'branch_id' => $validated['branch_id'] ?? $actor->branch_id,
                'rate_limit_per_minute' => $validated['rate_limit_per_minute'] ?? null,
                'status' => ApiClient::STATUS_ACTIVE,
                'created_by' => $actor->id,
            ]);

            $this->audit->record(
                actor: $actor,
                action: 'api_platform.client_created',
                category: AuditEvent::CATEGORY_SECURITY,
                module: 'api_platform',
                branchId: $client->branch_id,
                subjectType: ApiClient::class,
                subjectId: $client->id,
                after: [
                    'reference' => $client->reference,
                    'client_id' => $client->client_id,
                    'allowed_scopes' => $client->allowed_scopes,
                ],
            );

            return [
                'client' => $client->fresh(['user', 'branch']),
                'client_secret' => $clientSecret,
            ];
        });
    }

    public function revokeClient(User $actor, ApiClient $client): ApiClient
    {
        $this->assertCan($actor, 'api.platform.manage');

        $client->update([
            'status' => ApiClient::STATUS_REVOKED,
            'revoked_at' => now(),
        ]);

        $this->audit->record(
            actor: $actor,
            action: 'api_platform.client_revoked',
            category: AuditEvent::CATEGORY_SECURITY,
            module: 'api_platform',
            branchId: $client->branch_id,
            subjectType: ApiClient::class,
            subjectId: $client->id,
            after: ['reference' => $client->reference, 'client_id' => $client->client_id],
        );

        return $client->fresh(['user', 'branch']);
    }

    public function authenticateClient(string $credential, Request $request): ApiClient
    {
        if (! str_contains($credential, '.')) {
            $this->logDenied($request, null, null, 'credential_malformed', 401);
            throw new ApiPlatformException(
                $this->errorMessage('credential_malformed'),
                'credential_malformed',
                401,
            );
        }

        [$clientId, $secret] = explode('.', $credential, 2);
        if ($clientId === '' || $secret === '') {
            $this->logDenied($request, null, null, 'credential_malformed', 401);
            throw new ApiPlatformException(
                $this->errorMessage('credential_malformed'),
                'credential_malformed',
                401,
            );
        }

        $client = ApiClient::query()->where('client_id', $clientId)->first();
        if ($client === null || ! $client->isActive() || ! hash_equals($client->secret_hash, hash('sha256', $secret))) {
            $this->logDenied($request, $client, null, 'credential_revoked', 401);
            throw new ApiPlatformException(
                $this->errorMessage('credential_revoked'),
                'credential_revoked',
                401,
            );
        }

        $client->update(['last_used_at' => now()]);

        return $client->load('user');
    }

    public function correlationId(Request $request): string
    {
        $header = config('api_platform.correlation_header', 'X-Correlation-Id');
        $incoming = $request->header($header);

        if (is_string($incoming) && $incoming !== '') {
            return Str::limit($incoming, 64, '');
        }

        return (string) Str::uuid();
    }

    public function enforceRateLimit(Request $request, ?ApiClient $client, ?User $user): void
    {
        $limit = $client?->rate_limit_per_minute
            ?? ($client !== null
                ? config('api_platform.rate_limits.machine_principal_per_minute', 60)
                : config('api_platform.rate_limits.default_per_minute', 120));

        $key = 'api_rate:' . ($client?->id ?? $user?->id ?? $request->ip());
        $count = (int) Cache::get($key, 0);

        if ($count >= $limit) {
            $this->logDenied($request, $client, $user, 'rate_limited', 429);
            throw new ApiPlatformException($this->errorMessage('rate_limited'), 'rate_limited', 429);
        }

        Cache::put($key, $count + 1, now()->addMinute());
    }

    /**
     * @param  list<string>  $scopes
     */
    public function assertScopes(User $user, ?ApiClient $client, array $scopes, ?int $branchId = null): void
    {
        foreach ($scopes as $scope) {
            if ($client !== null && ! in_array($scope, $client->allowed_scopes ?? [], true)) {
                throw new ApiPlatformException($this->errorMessage('scope_insufficient'), 'scope_insufficient', 403);
            }

            if (! $this->authorization->allows($user, $scope, $branchId)) {
                throw new AuthorizationException($this->errorMessage('forbidden'));
            }
        }
    }

    public function logOutcome(
        Request $request,
        string $correlationId,
        int $statusCode,
        string $outcome,
        ?ApiClient $client = null,
        ?User $user = null,
        ?string $errorCode = null,
    ): void {
        ApiAccessEvent::create([
            'correlation_id' => $correlationId,
            'api_client_id' => $client?->id,
            'user_id' => $user?->id,
            'route_name' => $request->route()?->getName(),
            'method' => $request->method(),
            'path' => '/' . ltrim($request->path(), '/'),
            'status_code' => $statusCode,
            'outcome' => $outcome,
            'error_code' => $errorCode,
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function formatClient(ApiClient $client): array
    {
        return [
            'id' => $client->id,
            'reference' => $client->reference,
            'name' => $client->name,
            'client_id' => $client->client_id,
            'principal_type' => $client->principal_type,
            'allowed_scopes' => $client->allowed_scopes,
            'branch_id' => $client->branch_id,
            'rate_limit_per_minute' => $client->rate_limit_per_minute,
            'status' => $client->status,
            'last_used_at' => $client->last_used_at?->toIso8601String(),
            'revoked_at' => $client->revoked_at?->toIso8601String(),
        ];
    }

    public function errorMessage(string $codeKey): string
    {
        return (string) (config("api_platform.error_codes.{$codeKey}.message") ?? 'Request could not be completed.');
    }

    public function errorResponse(string $codeKey, string $correlationId, int $status): array
    {
        return [
            'message' => $this->errorMessage($codeKey),
            'code' => $codeKey,
            'correlation_id' => $correlationId,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function scopeCatalog(): array
    {
        return array_intersect_key(config('authz.actions', []), array_flip([
            'members.read',
            'organizations.read',
            'organizations.write',
            'search.global',
        ]));
    }

    private function logDenied(Request $request, ?ApiClient $client, ?User $user, string $codeKey, int $status): void
    {
        $correlationId = $this->correlationId($request);

        $this->logOutcome(
            request: $request,
            correlationId: $correlationId,
            statusCode: $status,
            outcome: 'denied',
            client: $client,
            user: $user,
            errorCode: $codeKey,
        );

        $this->audit->recordSecurityEvent($user, 'api_platform.access_denied', $request, [
            'correlation_id' => $correlationId,
            'code' => $codeKey,
            'client_id' => $client?->client_id,
            'route' => $request->route()?->getName(),
        ]);
    }

    private function assertCan(User $actor, string $action): void
    {
        if (! $this->authorization->allows($actor, $action)) {
            throw new AuthorizationException('Forbidden.');
        }
    }
}
