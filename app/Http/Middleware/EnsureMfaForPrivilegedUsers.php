<?php

namespace App\Http\Middleware;

use App\Models\SecurityAuditLog;
use App\Services\MfaPolicy;
use App\Services\SecurityAuditService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Story 1.2 AC2 — privileged users with MFA enrolled must verify each session.
 */
class EnsureMfaForPrivilegedUsers
{
    public function __construct(
        private MfaPolicy $policy,
        private SecurityAuditService $audit,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $this->policy->requiresVerification($user) || $this->isMfaVerified($request)) {
            return $next($request);
        }

        if ($this->isVerificationRoute($request)) {
            return $next($request);
        }

        $this->audit->record($user, SecurityAuditLog::EVENT_MFA_ACCESS_DENIED, $request, [
            'reason' => 'mfa_verification_required',
        ]);

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'message' => 'A valid second factor is required for privileged access.',
                'requires_mfa' => true,
            ], 403);
        }

        return redirect()->route('mfa.verify');
    }

    private function isMfaVerified(Request $request): bool
    {
        if ($request->hasSession() && $request->session()->get('mfa_verified') === true) {
            return true;
        }

        $token = $request->user()?->currentAccessToken();
        if ($token === null) {
            return false;
        }

        if ($token->can('*')) {
            return true;
        }

        return ! $token->can('mfa-pending') && ! $token->can('mfa-enrollment');
    }

    private function isVerificationRoute(Request $request): bool
    {
        return $request->routeIs('mfa.verify')
            || $request->routeIs('mfa.setup')
            || $request->is('api/mfa/verify')
            || $request->is('api/mfa/setup')
            || $request->is('mfa/verify')
            || $request->is('mfa/setup');
    }
}
