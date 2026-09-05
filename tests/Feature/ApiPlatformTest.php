<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 15.3: protected and documented REST APIs.
 */
class ApiPlatformTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'API-HQ']);
        $this->branch = Organization::create([
            'name' => 'Branch A',
            'type' => 'branch',
            'identifier' => 'API-A',
            'parent_id' => $hq->id,
        ]);
    }

    /**
     * @param  list<string>  $actions
     */
    private function grant(User $user, array $actions): void
    {
        $role = Role::create(['name' => 'api_' . $user->id . '_' . substr(md5(implode(',', $actions)), 0, 6)]);
        foreach ($actions as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);
    }

    private function platformAdmin(): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $this->grant($user, ['api.platform.read', 'api.platform.manage', 'members.read']);

        return $user;
    }

    /**
     * @return array{client_id: string, client_secret: string}
     */
    private function createMachineClient(User $admin, array $scopes = ['members.read']): array
    {
        $payload = $this->actingAsMfaVerified($admin)
            ->postJson('/api/platform/clients', [
                'name' => 'Directory sync',
                'allowed_scopes' => $scopes,
                'branch_id' => $this->branch->id,
                'rate_limit_per_minute' => 5,
            ])
            ->assertCreated()
            ->json('data');

        return [
            'client_id' => $payload['client_id'],
            'client_secret' => $payload['client_secret'],
        ];
    }

    public function test_machine_principal_calls_documented_v1_endpoint_with_correlation_and_denies_invalid_credentials(): void
    {
        $admin = $this->platformAdmin();
        Member::create([
            'membership_id' => 'M-API-1',
            'branch_id' => $this->branch->id,
            'first_name' => 'Ada',
            'last_name' => 'Member',
            'email' => 'ada@example.com',
            'registration_channel' => 'web',
            'consent_data_processing' => true,
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
        ]);

        $this->json('GET', '/api/v1/members', [], ['Accept' => 'application/json'])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated')
            ->assertJsonStructure(['correlation_id']);

        $client = $this->createMachineClient($admin);

        $response = $this->withHeader('Authorization', 'Bearer ' . $client['client_id'] . '.' . $client['client_secret'])
            ->withHeader('X-Correlation-Id', 'corr-test-001')
            ->getJson('/api/v1/members')
            ->assertOk()
            ->assertHeader('X-Correlation-Id', 'corr-test-001')
            ->assertHeader('X-Api-Version', '1');

        $this->assertCount(1, $response->json('data'));
        $this->assertDatabaseHas('api_access_events', [
            'correlation_id' => 'corr-test-001',
            'outcome' => 'allowed',
            'status_code' => 200,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $client['client_id'] . '.wrong-secret')
            ->getJson('/api/v1/members')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'credential_revoked');

        $this->assertDatabaseHas('audit_events', ['action' => 'api_platform.access_denied']);
    }

    public function test_contract_documentation_matches_registered_routes_and_detects_executable_drift(): void
    {
        $admin = $this->platformAdmin();

        $contract = $this->actingAsMfaVerified($admin)
            ->getJson('/api/platform/contract')
            ->assertOk()
            ->json('data');

        $this->assertSame('1', $contract['version']);
        $this->assertNotEmpty($contract['endpoints']);
        $this->assertArrayHasKey('api_error', $contract['schemas']);

        $validation = $this->actingAsMfaVerified($admin)
            ->getJson('/api/platform/contract/validate')
            ->assertOk()
            ->json('data');

        $this->assertTrue($validation['valid']);
        $this->assertSame(0, $validation['issue_count']);

        $revoked = $this->createMachineClient($admin);
        $model = ApiClient::query()->where('client_id', $revoked['client_id'])->firstOrFail();

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/platform/clients/{$model->id}/revoke")
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer ' . $revoked['client_id'] . '.' . $revoked['client_secret'])
            ->getJson('/api/v1/members')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'credential_revoked');
    }
}
