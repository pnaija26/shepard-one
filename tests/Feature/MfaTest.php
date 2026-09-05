<?php

namespace Tests\Feature;

use App\Models\SecurityAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Story 1.2: Require MFA for Privileged Access.
 */
class MfaTest extends TestCase
{
    use RefreshDatabase;

    private function privilegedWithoutMfa(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'roles' => ['admin'],
            'has_mfa_enrolled' => false,
            'mfa_secret' => null,
        ], $attributes));
    }

    private function privilegedWithMfa(array $attributes = []): User
    {
        $google2fa = new Google2FA();

        return User::factory()->create(array_merge([
            'roles' => ['admin'],
            'has_mfa_enrolled' => true,
            'mfa_secret' => $google2fa->generateSecretKey(),
        ], $attributes));
    }

    private function currentOtp(string $secret): string
    {
        return (new Google2FA())->getCurrentOtp($secret);
    }

    public function test_privileged_user_login_requires_mfa_enrollment()
    {
        $user = $this->privilegedWithoutMfa(['password' => 'password']);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('requires_mfa_enrollment', true)
            ->assertJsonStructure(['access_token', 'token_type', 'user']);

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_privileged_user_can_enroll_mfa_with_enrollment_token()
    {
        $user = $this->privilegedWithoutMfa();
        $google2fa = new Google2FA();
        $secret = $google2fa->generateSecretKey();
        $token = $user->createToken('mfa-enrollment', ['mfa-enrollment'])->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/mfa/setup', [
            'secret' => $secret,
            'otp' => $this->currentOtp($secret),
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('requires_mfa', true)
            ->assertJsonStructure(['access_token']);

        $user->refresh();
        $this->assertTrue($user->hasMfaEnrolled());
        $this->assertDatabaseHas('security_audit_log', [
            'user_id' => $user->id,
            'event' => SecurityAuditLog::EVENT_MFA_ENROLLMENT_COMPLETED,
        ]);
    }

    public function test_privileged_user_login_requires_mfa_verification_when_enrolled()
    {
        $user = $this->privilegedWithMfa(['password' => 'password']);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('requires_mfa', true)
            ->assertJsonStructure(['access_token', 'token_type', 'user']);
    }

    public function test_privileged_user_invalid_mfa_otp_is_denied_and_audited()
    {
        $user = $this->privilegedWithMfa();
        $token = $user->createToken('mfa-pending', ['mfa-pending'])->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/mfa/verify', [
            'otp' => '000000',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('otp');

        $this->assertDatabaseHas('security_audit_log', [
            'user_id' => $user->id,
            'event' => SecurityAuditLog::EVENT_MFA_VERIFICATION_FAILED,
        ]);
    }

    public function test_privileged_user_valid_mfa_otp_grants_full_access()
    {
        $google2fa = new Google2FA();
        $secret = $google2fa->generateSecretKey();
        $user = $this->privilegedWithMfa(['mfa_secret' => $secret]);
        $pending = $user->createToken('mfa-pending', ['mfa-pending'])->plainTextToken;

        $verify = $this->withToken($pending)->postJson('/api/mfa/verify', [
            'otp' => $this->currentOtp($secret),
        ]);

        $verify
            ->assertOk()
            ->assertJsonStructure(['access_token', 'token_type']);

        $fullToken = $verify->json('access_token');

        \Illuminate\Support\Facades\Auth::forgetGuards();

        $this->withToken($fullToken)
            ->getJson('/api/org/organizations')
            ->assertOk();

        $this->assertDatabaseHas('security_audit_log', [
            'user_id' => $user->id,
            'event' => SecurityAuditLog::EVENT_MFA_VERIFICATION_SUCCEEDED,
        ]);
    }

    public function test_privileged_user_without_verified_mfa_cannot_access_protected_api()
    {
        $user = $this->privilegedWithMfa();
        Sanctum::actingAs($user, ['mfa-pending']);

        $this->getJson('/api/org/organizations')
            ->assertStatus(403)
            ->assertJsonPath('requires_mfa', true);

        $this->assertDatabaseHas('security_audit_log', [
            'user_id' => $user->id,
            'event' => SecurityAuditLog::EVENT_MFA_ACCESS_DENIED,
        ]);
    }

    public function test_non_privileged_user_login_succeeds_without_mfa()
    {
        $user = User::factory()->create([
            'password' => 'password',
            'roles' => ['member'],
            'has_mfa_enrolled' => false,
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonMissing(['requires_mfa', 'requires_mfa_enrollment']);

        $this->withToken($response->json('access_token'))
            ->getJson('/api/auth/user')
            ->assertOk();
    }

    public function test_global_mfa_policy_requires_non_privileged_users_to_enroll()
    {
        config()->set('identity.security.mfa.required_for_all', true);

        $user = User::factory()->create([
            'password' => 'password',
            'roles' => ['member'],
            'has_mfa_enrolled' => false,
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('requires_mfa_enrollment', true);
    }

    public function test_privileged_web_user_without_mfa_is_redirected_to_setup()
    {
        $user = $this->privilegedWithoutMfa();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertRedirect(route('mfa.setup'));
    }
}
