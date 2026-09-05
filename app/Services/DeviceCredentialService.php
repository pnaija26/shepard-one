<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\DeviceCredential;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Story 12.1: issue, rotate, and revoke hybrid device credentials.
 */
class DeviceCredentialService
{
    public function __construct(private AuditService $audit)
    {
    }

    public function isHybridClient(Request $request): bool
    {
        $client = strtolower((string) $request->input('client', $request->header('X-Client', 'web')));

        return in_array($client, ['hybrid', 'android', 'ios', 'capacitor'], true);
    }

    /**
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int, device_id: string}
     */
    public function issue(User $user, Request $request): array
    {
        $deviceId = $this->normalizeDeviceId($request->input('device_id'));
        $platform = $this->normalizePlatform($request->input('platform', $request->header('X-Device-Platform')));
        $deviceName = Str::limit((string) ($request->input('device_name') ?: $platform.' device'), 120);

        return DB::transaction(function () use ($user, $request, $deviceId, $platform, $deviceName) {
            $this->enforceDeviceLimit($user, $deviceId);

            $existing = DeviceCredential::query()
                ->where('user_id', $user->id)
                ->where('device_id', $deviceId)
                ->first();

            if ($existing?->access_token_id) {
                PersonalAccessToken::query()->whereKey($existing->access_token_id)->delete();
            }

            $access = $user->createToken('hybrid-device', ['*']);
            $refreshPlain = Str::random(64);
            $ttlDays = (int) config('hybrid.device_credentials.refresh_token_ttl_days', 30);

            $payload = [
                'device_name' => $deviceName,
                'platform' => $platform,
                'refresh_token_hash' => hash('sha256', $refreshPlain),
                'access_token_id' => $access->accessToken->id,
                'last_used_at' => now(),
                'expires_at' => now()->addDays($ttlDays),
                'revoked_at' => null,
            ];

            if ($existing) {
                $existing->fill($payload)->save();
                $credential = $existing;
            } else {
                $credential = DeviceCredential::query()->create([
                    'user_id' => $user->id,
                    'device_id' => $deviceId,
                    ...$payload,
                ]);
            }

            $this->audit->record(
                actor: $user,
                action: 'auth.device_credential_issued',
                request: $request,
                module: 'auth',
                subjectType: DeviceCredential::class,
                subjectId: $credential->id,
                after: [
                    'device_id' => $deviceId,
                    'platform' => $platform,
                ],
            );

            return $this->tokenResponse($access->plainTextToken, $refreshPlain, $deviceId);
        });
    }

    /**
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int, device_id: string, user: User}
     */
    public function refresh(Request $request): array
    {
        $refreshPlain = (string) $request->input('refresh_token');
        $deviceId = $this->normalizeDeviceId($request->input('device_id'));

        if ($refreshPlain === '') {
            throw new DeviceCredentialException('Refresh token is required.', 'refresh_token_required', 422);
        }

        $credential = DeviceCredential::query()
            ->where('device_id', $deviceId)
            ->where('refresh_token_hash', hash('sha256', $refreshPlain))
            ->first();

        if (! $credential || ! $credential->isActive()) {
            if ($credential) {
                $this->audit->record(
                    actor: $credential->user,
                    action: 'auth.device_credential_rejected',
                    request: $request,
                    module: 'auth',
                    subjectType: DeviceCredential::class,
                    subjectId: $credential->id,
                    after: ['reason' => 'inactive_or_expired'],
                );
            }

            throw new DeviceCredentialException('Device credential is invalid or revoked.', 'refresh_invalid', 401);
        }

        return DB::transaction(function () use ($credential, $request, $deviceId) {
            $user = $credential->user;

            if ($credential->access_token_id) {
                PersonalAccessToken::query()->whereKey($credential->access_token_id)->delete();
            }

            $access = $user->createToken('hybrid-device', ['*']);
            $refreshPlain = Str::random(64);
            $ttlDays = (int) config('hybrid.device_credentials.refresh_token_ttl_days', 30);

            $credential->fill([
                'refresh_token_hash' => hash('sha256', $refreshPlain),
                'access_token_id' => $access->accessToken->id,
                'last_used_at' => now(),
                'expires_at' => now()->addDays($ttlDays),
            ])->save();

            $this->audit->record(
                actor: $user,
                action: 'auth.device_credential_rotated',
                request: $request,
                module: 'auth',
                subjectType: DeviceCredential::class,
                subjectId: $credential->id,
                after: ['device_id' => $deviceId],
            );

            return [
                ...$this->tokenResponse($access->plainTextToken, $refreshPlain, $deviceId),
                'user' => $user,
            ];
        });
    }

    public function revokeForCurrentAccess(User $user, Request $request): void
    {
        $token = $user->currentAccessToken();
        if (! $token) {
            return;
        }

        $credential = DeviceCredential::query()
            ->where('user_id', $user->id)
            ->where('access_token_id', $token->id)
            ->whereNull('revoked_at')
            ->first();

        if (! $credential) {
            return;
        }

        $this->revoke($credential, $user, $request, 'logout');
    }

    public function revokeByDevice(User $user, string $deviceId, Request $request): DeviceCredential
    {
        $credential = DeviceCredential::query()
            ->where('user_id', $user->id)
            ->where('device_id', $this->normalizeDeviceId($deviceId))
            ->whereNull('revoked_at')
            ->first();

        if (! $credential) {
            throw new DeviceCredentialException('Device credential not found.', 'device_not_found', 404);
        }

        return $this->revoke($credential, $user, $request, 'explicit');
    }

    public function revoke(DeviceCredential $credential, User $actor, Request $request, string $reason): DeviceCredential
    {
        return DB::transaction(function () use ($credential, $actor, $request, $reason) {
            if ($credential->access_token_id) {
                PersonalAccessToken::query()->whereKey($credential->access_token_id)->delete();
            }

            $credential->fill([
                'revoked_at' => now(),
                'refresh_token_hash' => hash('sha256', Str::random(64)),
                'access_token_id' => null,
            ])->save();

            $this->audit->record(
                actor: $actor,
                action: 'auth.device_credential_revoked',
                request: $request,
                module: 'auth',
                subjectType: DeviceCredential::class,
                subjectId: $credential->id,
                after: [
                    'device_id' => $credential->device_id,
                    'reason' => $reason,
                ],
            );

            return $credential->fresh();
        });
    }

    public function foundationManifest(): array
    {
        return [
            'runtime' => config('hybrid.runtime'),
            'platforms' => config('hybrid.platforms'),
            'api' => [
                'version' => config('hybrid.api.version'),
                'version_header' => config('hybrid.api.version_header'),
            ],
            'offline_tolerant_actions' => config('hybrid.offline_tolerant_actions'),
            'permissions' => collect(config('hybrid.permissions'))->map(fn ($meta, $key) => [
                'key' => $key,
                'purpose' => $meta['purpose'],
                'fallback' => $meta['fallback'],
            ])->values()->all(),
        ];
    }

    private function enforceDeviceLimit(User $user, string $deviceId): void
    {
        $max = (int) config('hybrid.device_credentials.max_devices_per_user', 10);
        $active = DeviceCredential::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->where('device_id', '!=', $deviceId)
            ->count();

        if ($active >= $max) {
            throw new DeviceCredentialException(
                'Maximum registered devices reached. Revoke an unused device first.',
                'device_limit',
                422,
            );
        }
    }

    private function normalizeDeviceId(?string $deviceId): string
    {
        $deviceId = trim((string) $deviceId);
        if ($deviceId === '' || strlen($deviceId) > 64) {
            throw new DeviceCredentialException('A stable device_id (max 64 chars) is required.', 'device_id_invalid', 422);
        }

        return $deviceId;
    }

    private function normalizePlatform(?string $platform): string
    {
        $platform = strtolower(trim((string) $platform));
        if (! in_array($platform, ['ios', 'android', 'web-hybrid'], true)) {
            return 'web-hybrid';
        }

        return $platform;
    }

    /**
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int, device_id: string}
     */
    private function tokenResponse(string $access, string $refresh, string $deviceId): array
    {
        return [
            'access_token' => $access,
            'refresh_token' => $refresh,
            'token_type' => 'Bearer',
            'expires_in' => (int) config('hybrid.device_credentials.access_token_ttl_minutes', 60) * 60,
            'device_id' => $deviceId,
        ];
    }
}
