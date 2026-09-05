<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\SecurityAuditLog;
use App\Services\MfaPolicy;
use App\Services\SecurityAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;

class MfaController extends Controller
{
    public function __construct(
        private MfaPolicy $policy,
        private SecurityAuditService $audit,
    ) {
    }

    public function showSetup()
    {
        if (! Auth::check()) {
            return redirect()->route('login');
        }

        $user = Auth::user();

        if ($user->hasMfaEnrolled()) {
            return redirect()->route('dashboard');
        }

        $google2fa = new Google2FA();
        $secret = $google2fa->generateSecretKey();
        $qrCodeUrl = $google2fa->getQRCodeImageAsDataUrl(
            'ShepardOne',
            $user->email,
            $secret
        );

        return view('auth.mfa-setup', [
            'secret' => $secret,
            'qrCodeUrl' => $qrCodeUrl,
        ]);
    }

    public function setup(Request $request)
    {
        $request->validate([
            'otp' => 'required|string|size:6',
            'secret' => 'required|string',
        ]);

        $user = $request->user() ?? Auth::user();
        if (! $user) {
            abort(401);
        }

        $google2fa = new Google2FA();
        $valid = $google2fa->verifyKey($request->secret, $request->otp);

        if (! $valid) {
            $this->audit->record($user, SecurityAuditLog::EVENT_MFA_VERIFICATION_FAILED, $request, [
                'phase' => 'enrollment',
            ]);

            throw ValidationException::withMessages([
                'otp' => ['The provided OTP code is invalid.'],
            ]);
        }

        $user->update([
            'mfa_secret' => $request->secret,
            'has_mfa_enrolled' => true,
        ]);

        $this->audit->record($user, SecurityAuditLog::EVENT_MFA_ENROLLMENT_COMPLETED, $request);

        $request->user()?->currentAccessToken()?->delete();

        if ($request->expectsJson() || $request->is('api/*')) {
            if ($this->policy->requiresVerification($user->fresh())) {
                $token = $user->createToken('mfa-pending', ['mfa-pending'])->plainTextToken;

                return response()->json([
                    'message' => 'MFA enrolled. Complete verification to access privileged functions.',
                    'requires_mfa' => true,
                    'access_token' => $token,
                    'token_type' => 'Bearer',
                ]);
            }

            $token = $user->createToken('auth-token')->plainTextToken;

            return response()->json([
                'message' => 'MFA has been enabled successfully.',
                'access_token' => $token,
                'token_type' => 'Bearer',
            ]);
        }

        session(['mfa_verified' => false]);

        return redirect()->route('mfa.verify')->with('message', 'MFA has been enabled. Please verify to continue.');
    }

    public function showVerify()
    {
        if (! Auth::check()) {
            return redirect()->route('login');
        }

        $user = Auth::user();

        if (! $user->hasMfaEnrolled()) {
            return redirect()->route('dashboard');
        }

        return view('auth.mfa-verify');
    }

    public function verify(Request $request): JsonResponse|\Illuminate\Http\RedirectResponse
    {
        $request->validate([
            'otp' => 'required|string|size:6',
        ]);

        $user = $request->user() ?? Auth::user();
        if (! $user || ! $user->hasMfaEnrolled()) {
            abort(401);
        }

        $google2fa = new Google2FA();
        $valid = $google2fa->verifyKey($user->mfa_secret, $request->otp);

        if (! $valid) {
            $this->audit->record($user, SecurityAuditLog::EVENT_MFA_VERIFICATION_FAILED, $request, [
                'phase' => 'verification',
            ]);

            throw ValidationException::withMessages([
                'otp' => ['The provided OTP code is invalid.'],
            ]);
        }

        $this->audit->record($user, SecurityAuditLog::EVENT_MFA_VERIFICATION_SUCCEEDED, $request);

        $request->user()?->currentAccessToken()?->delete();

        if ($request->expectsJson() || $request->is('api/*')) {
            $token = $user->createToken('auth-token')->plainTextToken;

            return response()->json([
                'message' => 'MFA verified successfully.',
                'access_token' => $token,
                'token_type' => 'Bearer',
            ]);
        }

        session(['mfa_verified' => true]);

        return redirect()->route('dashboard');
    }
}
