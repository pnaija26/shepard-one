<?php

namespace App\Services;

use App\Models\User;

/**
 * Story 1.2: configurable MFA requirements (AC3 — policy changes do not
 * require source-code modification; adjust config/identity.php instead).
 */
class MfaPolicy
{
    /** @return string[] */
    public function privilegedRoles(): array
    {
        return config('identity.security.mfa.privileged_roles', ['admin', 'hq_admin', 'system_admin']);
    }

    /** True when this user must satisfy MFA before privileged access. */
    public function requiresMfaFor(User $user): bool
    {
        if (config('identity.security.mfa.required_for_all', false)) {
            return true;
        }

        if (! config('identity.security.mfa.required_for_privileged', true)) {
            return false;
        }

        return $user->isPrivileged();
    }

    /** Privileged (or policy-mandated) user who has not enrolled MFA yet. */
    public function requiresEnrollment(User $user): bool
    {
        return $this->requiresMfaFor($user) && ! $user->hasMfaEnrolled();
    }

    /** User who must complete a second-factor check this session/token. */
    public function requiresVerification(User $user): bool
    {
        return $this->requiresMfaFor($user) && $user->hasMfaEnrolled();
    }
}
