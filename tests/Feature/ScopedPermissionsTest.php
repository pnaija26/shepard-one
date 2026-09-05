<?php

namespace Tests\Feature;

use App\Models\AuthorizationAuditLog;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\BranchScopeException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Story 1.6: Manage Scoped Roles and Permissions.
 */
class ScopedPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private function buildTree(): array
    {
        $hq = Organization::create(['name' => 'Headquarters', 'type' => 'headquarters', 'identifier' => 'IDX-HQ']);

        $branchA = Organization::create([
            'name' => 'Branch A', 'type' => 'branch', 'identifier' => 'IDX-BR-A', 'parent_id' => $hq->id,
        ]);
        $branchB = Organization::create([
            'name' => 'Branch B', 'type' => 'branch', 'identifier' => 'IDX-BR-B', 'parent_id' => $hq->id,
        ]);

        return compact('hq', 'branchA', 'branchB');
    }

    private function hqActor(): User
    {
        return $this->privilegedUser(['branch_id' => null]);
    }

    private function branchActor(Organization $branch): User
    {
        return $this->privilegedUser(['branch_id' => $branch->id]);
    }

    private function grantRole(User $user, Role $role, array $permissions): Role
    {
        foreach ($permissions as $permission) {
            RolePermission::create(array_merge(['role_id' => $role->id], $permission));
        }

        RoleAssignment::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'granted_by' => $user->id,
        ]);

        return $role->fresh('permissions');
    }

    // ------------------------------------------------------------------
    // AC1 — role CRUD + scope-grant validation
    // ------------------------------------------------------------------

    public function test_hq_admin_can_create_role_with_scoped_permissions()
    {
        $t = $this->buildTree();
        $actor = $this->hqActor();
        $this->grantRole($actor, Role::create(['name' => 'security_admin', 'description' => 'HQ security']), [
            ['scope_type' => 'global', 'action' => 'roles.manage'],
        ]);

        $response = $this->actingAsMfaVerified($actor)->postJson('/api/roles', [
            'name' => 'regional_coordinator',
            'description' => 'Regional ops',
            'permissions' => [
                [
                    'scope_type' => 'branch',
                    'scope_id' => $t['branchA']->id,
                    'module' => 'organizations',
                    'action' => 'organizations.read',
                ],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'regional_coordinator');

        $this->assertDatabaseHas('role_permissions', [
            'scope_type' => 'branch',
            'scope_id' => $t['branchA']->id,
            'action' => 'organizations.read',
        ]);
    }

    public function test_branch_admin_cannot_grant_global_scope()
    {
        $t = $this->buildTree();
        $actor = $this->branchActor($t['branchA']);
        $this->grantRole($actor, Role::create(['name' => 'branch_security', 'description' => 'Branch security']), [
            ['scope_type' => 'branch', 'scope_id' => $t['branchA']->id, 'action' => 'roles.manage'],
        ]);

        $this->actingAsMfaVerified($actor)->postJson('/api/roles', [
            'name' => 'illegal_global',
            'permissions' => [
                ['scope_type' => 'global', 'action' => 'organizations.read'],
            ],
        ])->assertStatus(403);
    }

    public function test_branch_admin_cannot_grant_outside_subtree()
    {
        $t = $this->buildTree();
        $actor = $this->branchActor($t['branchA']);
        $this->grantRole($actor, Role::create(['name' => 'branch_security', 'description' => 'Branch security']), [
            ['scope_type' => 'branch', 'scope_id' => $t['branchA']->id, 'action' => 'roles.manage'],
        ]);

        $this->actingAsMfaVerified($actor)->postJson('/api/roles', [
            'name' => 'cross_branch',
            'permissions' => [
                [
                    'scope_type' => 'branch',
                    'scope_id' => $t['branchB']->id,
                    'action' => 'organizations.read',
                ],
            ],
        ])->assertStatus(403);
    }

    // ------------------------------------------------------------------
    // AC2 — effective permissions + parity across enforcement paths
    // ------------------------------------------------------------------

    public function test_effective_permissions_union_multiple_role_assignments()
    {
        $t = $this->buildTree();
        $user = User::factory()->create(['branch_id' => $t['branchA']->id]);

        $roleA = Role::create(['name' => 'reader_a']);
        $roleB = Role::create(['name' => 'writer_b']);

        RolePermission::create(['role_id' => $roleA->id, 'scope_type' => 'branch', 'scope_id' => $t['branchA']->id, 'action' => 'organizations.read']);
        RolePermission::create(['role_id' => $roleB->id, 'scope_type' => 'branch', 'scope_id' => $t['branchB']->id, 'action' => 'organizations.write']);

        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $roleA->id, 'granted_by' => 1]);
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $roleB->id, 'granted_by' => 1]);

        $authz = app(AuthorizationService::class);

        $this->assertTrue($authz->allows($user, 'organizations.read', $t['branchA']->id));
        $this->assertTrue($authz->allows($user, 'organizations.write', $t['branchB']->id));
        $this->assertFalse($authz->allows($user, 'organizations.delete', $t['branchA']->id));
    }

    public function test_gate_and_service_agree_on_authorization_decision()
    {
        $t = $this->buildTree();
        $user = User::factory()->create(['branch_id' => null]);
        $role = Role::create(['name' => 'hq_reader']);
        RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => 'organizations.read']);
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => 1]);

        $authz = app(AuthorizationService::class);

        $this->assertSame(
            $authz->allows($user, 'organizations.read', $t['branchA']->id),
            Gate::forUser($user)->allows('organizations.read', $t['branchA']->id)
        );
    }

    public function test_background_path_without_principal_is_denied()
    {
        $authz = app(AuthorizationService::class);

        $this->assertFalse($authz->allows(null, 'organizations.read'));
        $this->expectException(BranchScopeException::class);
        \App\Services\BranchScope::for(null);
    }

    // ------------------------------------------------------------------
    // AC3 — revocation without deployment + cache invalidation
    // ------------------------------------------------------------------

    public function test_revoked_permission_denied_on_next_request()
    {
        $t = $this->buildTree();
        $user = User::factory()->create(['branch_id' => $t['branchA']->id]);
        $role = Role::create(['name' => 'mutable']);
        $permission = RolePermission::create([
            'role_id' => $role->id,
            'scope_type' => 'branch',
            'scope_id' => $t['branchA']->id,
            'action' => 'organizations.read',
        ]);
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => 1]);

        $authz = app(AuthorizationService::class);
        $this->assertTrue($authz->allows($user, 'organizations.read', $t['branchA']->id));

        $permission->delete();
        $authz->invalidate($user);

        $this->assertFalse($authz->allows($user, 'organizations.read', $t['branchA']->id));
    }

    public function test_expired_assignment_denied_on_next_request()
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'temp']);
        RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => 'organizations.read']);
        RoleAssignment::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'granted_by' => 1,
            'expires_at' => now()->subMinute(),
        ]);

        $authz = app(AuthorizationService::class);
        $authz->invalidate($user);

        $this->assertFalse($authz->allows($user, 'organizations.read'));
    }

    public function test_cache_invalidated_within_security_window_on_role_update()
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'cached_role']);
        RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => 'organizations.read']);
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => 1]);

        $authz = app(AuthorizationService::class);
        $authz->effectivePermissions($user);

        $this->assertNotNull(Cache::get(AuthorizationService::CACHE_PREFIX . $user->id));

        $actor = $this->hqActor();
        $this->grantRole($actor, Role::create(['name' => 'security']), [
            ['scope_type' => 'global', 'action' => 'roles.manage'],
        ]);

        $this->actingAsMfaVerified($actor)->putJson("/api/roles/{$role->id}", [
            'permissions' => [
                ['scope_type' => 'global', 'action' => 'organizations.write'],
            ],
        ])->assertOk();

        $this->assertNull(Cache::get(AuthorizationService::CACHE_PREFIX . $user->id));
    }

    // ------------------------------------------------------------------
    // AC4 — last super-admin protection
    // ------------------------------------------------------------------

    public function test_deleting_last_super_admin_role_is_blocked()
    {
        $actor = $this->hqActor();
        $super = Role::create(['name' => 'super_admin', 'is_super_admin' => true, 'is_system' => true]);
        RoleAssignment::create(['user_id' => $actor->id, 'role_id' => $super->id, 'granted_by' => $actor->id]);
        $this->grantRole($actor, Role::create(['name' => 'security']), [
            ['scope_type' => 'global', 'action' => 'roles.manage'],
        ]);

        $this->actingAsMfaVerified($actor)
            ->deleteJson("/api/roles/{$super->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('authorization_audit_log', [
            'event' => AuthorizationAuditLog::EVENT_LAST_SUPER_ADMIN_BLOCKED,
        ]);
    }

    public function test_break_glass_allows_last_super_admin_removal()
    {
        config(['authz.break_glass_code' => 'approved-break-glass']);

        $actor = $this->hqActor();
        $super = Role::create(['name' => 'super_admin', 'is_super_admin' => true, 'is_system' => true]);
        RoleAssignment::create(['user_id' => $actor->id, 'role_id' => $super->id, 'granted_by' => $actor->id]);
        $this->grantRole($actor, Role::create(['name' => 'security']), [
            ['scope_type' => 'global', 'action' => 'roles.manage'],
        ]);

        $this->actingAsMfaVerified($actor)
            ->deleteJson("/api/roles/{$super->id}", ['break_glass' => 'approved-break-glass'])
            ->assertOk();

        $this->assertDatabaseHas('authorization_audit_log', [
            'event' => AuthorizationAuditLog::EVENT_BREAK_GLASS_APPROVED,
        ]);
        $this->assertDatabaseMissing('roles', ['id' => $super->id]);
    }
}
