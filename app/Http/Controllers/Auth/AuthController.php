<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Services\AuditService;
use App\Services\DeviceCredentialException;
use App\Services\DeviceCredentialService;
use App\Services\MfaPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private MfaPolicy $policy,
        private AuditService $audit,
        private DeviceCredentialService $devices,
    ) {
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
            'client' => 'nullable|string|max:32',
            'device_id' => 'nullable|string|max:64',
            'device_name' => 'nullable|string|max:120',
            'platform' => 'nullable|string|max:32',
        ]);

        $credentials = $request->only('email', 'password');
        $provider = Auth::guard()->getProvider();
        $user = $provider->retrieveByCredentials($credentials);

        if (! $user || ! $provider->validateCredentials($user, $credentials)) {
            $this->audit->record(
                actor: null,
                action: AuditEvent::ACTION_AUTH_LOGIN_FAILED,
                request: $request,
                module: 'auth',
                after: ['email' => $request->input('email')],
            );

            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($this->policy->requiresEnrollment($user)) {
            $token = $user->createToken('mfa-enrollment', ['mfa-enrollment'])->plainTextToken;

            return response()->json([
                'message' => 'MFA enrollment is required before privileged access.',
                'requires_mfa_enrollment' => true,
                'access_token' => $token,
                'token_type' => 'Bearer',
                'user' => $user,
            ]);
        }

        if ($this->policy->requiresVerification($user)) {
            $token = $user->createToken('mfa-pending', ['mfa-pending'])->plainTextToken;

            return response()->json([
                'message' => 'A valid second factor is required for privileged access.',
                'requires_mfa' => true,
                'access_token' => $token,
                'token_type' => 'Bearer',
                'user' => $user,
            ]);
        }

        $hybrid = $this->devices->isHybridClient($request);

        try {
            if ($hybrid) {
                $issued = $this->devices->issue($user, $request);
            } else {
                $issued = [
                    'access_token' => $user->createToken('auth-token')->plainTextToken,
                    'token_type' => 'Bearer',
                ];
            }
        } catch (DeviceCredentialException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => $exception->codeKey,
            ], $exception->getCode() ?: 422);
        }

        $this->audit->record(
            actor: $user,
            action: AuditEvent::ACTION_AUTH_LOGIN,
            request: $request,
            module: 'auth',
            after: [
                'method' => 'password',
                'client' => $hybrid ? 'hybrid' : 'web',
            ],
        );

        return response()->json([
            ...$issued,
            'user' => $user,
        ]);
    }

    public function logout(Request $request)
    {
        $user = $request->user();

        $this->audit->record(
            actor: $user,
            action: AuditEvent::ACTION_AUTH_LOGOUT,
            request: $request,
            module: 'auth',
        );

        $this->devices->revokeForCurrentAccess($user, $request);

        $user->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Successfully logged out',
        ]);
    }

    public function user(Request $request)
    {
        return response()->json($request->user());
    }

    public function refreshDevice(Request $request): JsonResponse
    {
        $request->validate([
            'refresh_token' => 'required|string',
            'device_id' => 'required|string|max:64',
        ]);

        try {
            $rotated = $this->devices->refresh($request);

            return response()->json($rotated);
        } catch (DeviceCredentialException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => $exception->codeKey,
            ], $exception->getCode() ?: 401);
        }
    }

    public function revokeDevice(Request $request): JsonResponse
    {
        $request->validate([
            'device_id' => 'required|string|max:64',
        ]);

        try {
            $credential = $this->devices->revokeByDevice($request->user(), $request->input('device_id'), $request);

            return response()->json([
                'message' => 'Device credential revoked.',
                'data' => [
                    'device_id' => $credential->device_id,
                    'revoked_at' => $credential->revoked_at?->toIso8601String(),
                ],
            ]);
        } catch (DeviceCredentialException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => $exception->codeKey,
            ], $exception->getCode() ?: 422);
        }
    }

    public function hybridFoundation(): JsonResponse
    {
        return response()->json(['data' => $this->devices->foundationManifest()]);
    }
}
