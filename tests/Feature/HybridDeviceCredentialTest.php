<?php

namespace Tests\Feature;

use App\Models\DeviceCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Story 12.1: hybrid device credentials, foundation manifest, rotation/revoke.
 */
class HybridDeviceCredentialTest extends TestCase
{
    use RefreshDatabase;

    public function test_hybrid_login_issues_rotatable_device_credential_without_logging_secrets(): void
    {
        $user = User::factory()->create([
            'email' => 'member@example.church',
            'password' => bcrypt('Secret-Pass-123'),
            'roles' => ['member'],
            'has_mfa_enrolled' => false,
        ]);

        $login = $this->postJson('/api/auth/login', [
            'email' => 'member@example.church',
            'password' => 'Secret-Pass-123',
            'client' => 'hybrid',
            'device_id' => 'device-alpha-001',
            'device_name' => 'Pixel Test',
            'platform' => 'android',
        ])->assertOk()
            ->assertJsonStructure([
                'access_token',
                'refresh_token',
                'token_type',
                'expires_in',
                'device_id',
                'user' => ['id', 'email'],
            ])
            ->json();

        $this->assertSame('device-alpha-001', $login['device_id']);
        $this->assertDatabaseCount('device_credentials', 1);

        $credential = DeviceCredential::query()->first();
        $this->assertSame(hash('sha256', $login['refresh_token']), $credential->refresh_token_hash);
        $this->assertNotSame($login['refresh_token'], $credential->refresh_token_hash);
        $this->assertTrue($credential->isActive());

        $foundation = $this->getJson('/api/auth/hybrid/foundation')
            ->assertOk()
            ->json('data');

        $this->assertSame('capacitor', $foundation['runtime']['bridge']);
        $this->assertSame('1', $foundation['api']['version']);
        $this->assertContains('feedback.draft', $foundation['offline_tolerant_actions']);
        $this->assertNotEmpty($foundation['permissions']);

        $rotated = $this->postJson('/api/auth/device/refresh', [
            'refresh_token' => $login['refresh_token'],
            'device_id' => 'device-alpha-001',
        ])->assertOk()->json();

        $this->assertNotSame($login['access_token'], $rotated['access_token']);
        $this->assertNotSame($login['refresh_token'], $rotated['refresh_token']);

        $this->postJson('/api/auth/device/refresh', [
            'refresh_token' => $login['refresh_token'],
            'device_id' => 'device-alpha-001',
        ])->assertUnauthorized()->assertJsonPath('code', 'refresh_invalid');

        $this->withToken($rotated['access_token'])
            ->postJson('/api/auth/device/revoke', ['device_id' => 'device-alpha-001'])
            ->assertOk()
            ->assertJsonPath('data.device_id', 'device-alpha-001');

        $credential->refresh();
        $this->assertNotNull($credential->revoked_at);

        $this->postJson('/api/auth/device/refresh', [
            'refresh_token' => $rotated['refresh_token'],
            'device_id' => 'device-alpha-001',
        ])->assertUnauthorized();
    }

    public function test_web_login_does_not_create_device_credential_and_logout_clears_hybrid_token(): void
    {
        $user = User::factory()->create([
            'email' => 'web@example.church',
            'password' => bcrypt('Secret-Pass-123'),
            'roles' => ['member'],
            'has_mfa_enrolled' => false,
        ]);

        $login = $this->postJson('/api/auth/login', [
            'email' => 'web@example.church',
            'password' => 'Secret-Pass-123',
        ])->assertOk()->json();

        $this->assertArrayHasKey('access_token', $login);
        $this->assertArrayNotHasKey('refresh_token', $login);
        $this->assertDatabaseCount('device_credentials', 0);

        $hybrid = $this->postJson('/api/auth/login', [
            'email' => 'web@example.church',
            'password' => 'Secret-Pass-123',
            'client' => 'hybrid',
            'device_id' => 'device-beta-002',
            'platform' => 'ios',
        ])->assertOk()->json();

        $this->assertDatabaseCount('device_credentials', 1);
        $tokenId = DeviceCredential::query()->value('access_token_id');

        $this->withToken($hybrid['access_token'])
            ->postJson('/api/auth/logout')
            ->assertOk();

        $this->assertNull(PersonalAccessToken::query()->find($tokenId));
        $this->assertNotNull(DeviceCredential::query()->value('revoked_at'));
    }
}
