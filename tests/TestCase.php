<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;
use PragmaRX\Google2FA\Google2FA;

abstract class TestCase extends BaseTestCase
{
    /**
     * Privileged user with MFA enrolled (Story 1.2 baseline for admin tests).
     */
    protected function privilegedUser(array $attributes = []): User
    {
        $google2fa = new Google2FA();

        return User::factory()->create(array_merge([
            'roles' => ['admin'],
            'has_mfa_enrolled' => true,
            'mfa_secret' => $google2fa->generateSecretKey(),
        ], $attributes));
    }

    /**
     * Authenticate a user who has satisfied MFA for protected API routes.
     */
    protected function actingAsMfaVerified(User $user, string $guard = 'sanctum'): static
    {
        if ($guard === 'sanctum') {
            Sanctum::actingAs($user, ['*']);

            return $this;
        }

        return $this->actingAs($user, $guard)->withSession(['mfa_verified' => true]);
    }

    /**
     * Bearer token for a fully verified API principal.
     */
    protected function bearerTokenFor(User $user): string
    {
        return $user->createToken('auth-token')->plainTextToken;
    }
}
