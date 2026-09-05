<?php

namespace App\Http\Middleware;

use App\Models\SecurityAuditLog;
use App\Services\MfaPolicy;
use App\Services\SecurityAuditService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Story 1.2 AC1 — privileged users must enroll MFA before privileged access.
 */
class EnsureMfaEnrolled
{
    public function __construct(
        private MfaPolicy $policy,
        private SecurityAuditService $audit,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $this->policy->requiresEnrollment($user)) {
            return $next($request);
        }

        if ($this->isEnrollmentRoute($request) || $this->tokenAllowsEnrollment($request)) {
            return $next($request);
        }

        $this->audit->record($user, SecurityAuditLog::EVENT_MFA_ACCESS_DENIED, $request, [
            'reason' => 'mfa_enrollment_required',
        ]);

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'message' => 'MFA enrollment is required before privileged access.',
                'requires_mfa_enrollment' => true,
            ], 403);
        }

        return redirect()->route('mfa.setup');
    }

    private function isEnrollmentRoute(Request $request): bool
    {
        return $request->routeIs('mfa.setup')
            || $request->is('api/mfa/setup')
            || $request->is('mfa/setup');
    }

    private function tokenAllowsEnrollment(Request $request): bool
    {
        $token = $request->user()?->currentAccessToken();

        return $token !== null && $token->can('mfa-enrollment');
    }
}
