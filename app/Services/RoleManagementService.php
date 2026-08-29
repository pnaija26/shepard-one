<?php

namespace App\Services;

use App\Models\AuthorizationAuditLog;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Story 1.6: role/permission lifecycle with security invariants.
 *
 * AC1 — create/update roles and their scoped permission rows; every grant is
 *       validated against the ACTOR's own scope so an administrator can never
 *       grant a scope they do not possess (branch-scoped admins cannot mint
 *       global or cross-branch grants).
 *
 * AC3 — every mutation performs targeted cache invalidation for affected users
 *       and writes an audit row, so revocation is effective on the next request.
 *
 * AC4 — any change that would remove the last viable super-admin path throws
 *       {@see LastSuperAdminException} unless an approved break-glass code was
 *       supplied; both outcomes are recorded in authorization_audit_log.
 */
class RoleManagementService
{
    public function __construct(
        private AuthorizationService $authorization,
    ) {
    }

    // ------------------------------------------------------------------
    // AC1 — role CRUD with scope-grant validation
    // ------------------------------------------------------------------

    /**
     * Create a role with its permission rows.
     *
     * @param  array{permissions?: array<int, array<string, mixed>>} $attrs
     */
    public function create(User $actor, array $attrs): Role
    {
        $name = (string) ($attrs['name'] ?? '');
        if ($name === '') {
            throw ValidationException::withMessages(['name' => ['Role name is required.']]);
        }

        if (Role::where('name', $name)->exists()) {
            throw ValidationException::withMessages(['name' => ["A role named '{$name}' already exists."]]);
        }

        $permissions = $attrs['permissions'] ?? [];
        $this->assertGrantsWithinActorScope($actor, $permissions);

        return DB::transaction(function () use ($actor, $name, $attrs, $permissions) {
            $role = Role::create([
                'name' => $name,
                'description' => $attrs['description'] ?? null,
                'is_super_admin' => (bool) ($attrs['is_super_admin'] ?? false),
                'is_system' => false,
            ]);

            foreach ($permissions as $p) {
                RolePermission::create($this->normalizeGrant($role->id, $p));
            }

            AuthorizationAuditLog::record($actor, AuthorizationAuditLog::EVENT_ROLE_CREATED, Role::class, $role->id, [
                'name' => $name,
                'permission_count' => count($permissions),
            ]);

            return $role;
        });
    }

    /**
     * Update a role's metadata and/or replace its permission set.
     */
    public function update(User $actor, Role $role, array $attrs): Role
    {
        // AC4: stripping the super-admin flag off the last viable super role is
        // a lockout — blocked (or break-glass) before anything else happens.
        if (array_key_exists('is_super_admin', $attrs) && ! $attrs['is_super_admin'] && $role->is_super_admin) {
            $this->assertNotLastSuperAdmin($actor, $role, 'update');
        }

        // AC1: re-validate the full proposed permission set against actor scope.
        if (array_key_exists('permissions', $attrs)) {
            $this->assertGrantsWithinActorScope($actor, $attrs['permissions']);
        }

        return DB::transaction(function () use ($actor, $role, $attrs) {
            $meta = array_intersect_key($attrs, ['name' => 1, 'description' => 1]);
            if ($meta !== []) {
                $role->fill($meta)->save();
            }

            if (array_key_exists('permissions', $attrs)) {
                // Replace semantics: removed rows are revoked grants.
                $removed = $role->permissions()->count();
                $role->permissions()->delete();

                foreach ($attrs['permissions'] as $p) {
                    RolePermission::create($this->normalizeGrant($role->id, $p));
                }

                AuthorizationAuditLog::record($actor, AuthorizationAuditLog::EVENT_PERMISSION_REVOKED, Role::class, $role->id, [
                    'removed_rows' => $removed,
                    'granted_rows' => count($attrs['permissions']),
                ]);
            }

            // Any permission change alters every holder's effective set.
            $this->invalidateHolders($actor, $role);

            AuthorizationAuditLog::record($actor, AuthorizationAuditLog::EVENT_ROLE_UPDATED, Role::class, $role->id, [
                'name' => $role->fresh()->name,
            ]);

            return $role->fresh();
        });
    }

    /**
     * Delete a role (and its permission rows + assignments).
     */
    public function delete(User $actor, Role $role, array $options = []): void
    {
        // AC4: deleting the last viable super-admin path is blocked unless an
        // approved break-glass code was supplied.
        if ($role->is_super_admin) {
            $this->assertNotLastSuperAdmin($actor, $role, 'delete', $options);
        }

        DB::transaction(function () use ($actor, $role) {
            $holderIds = $role->assignments()->pluck('user_id')->all();

            $role->permissions()->delete();
            $role->assignments()->delete();
            $role->delete();

            foreach ($holderIds as $id) {
                $this->authorization->invalidate($id);
            }

            AuthorizationAuditLog::record($actor, AuthorizationAuditLog::EVENT_ROLE_DELETED, Role::class, $role->id, [
                'name' => $role->getOriginal('name') ?? null,
                'affected_users' => count($holderIds),
            ]);
        });
    }

    // ------------------------------------------------------------------
    // AC1/AC3 — permission grant & revoke (single rows)
    // ------------------------------------------------------------------

    public function grantPermission(User $actor, Role $role, array $attrs): RolePermission
    {
        $this->assertGrantsWithinActorScope($actor, [$attrs]);

        return DB::transaction(function () use ($actor, $role, $attrs) {
            $permission = RolePermission::create($this->normalizeGrant($role->id, $attrs));

            foreach ($role->assignments()->pluck('user_id') as $userId) {
                $this->authorization->invalidate($userId);
            }

            AuthorizationAuditLog::record($actor, AuthorizationAuditLog::EVENT_PERMISSION_GRANTED, RolePermission::class, $permission->id, [
                'role_id' => $role->id,
                'action' => $attrs['action'] ?? null,
            ]);

            return $permission;
        });
    }

    public function revokePermission(User $actor, Role $role, RolePermission|int $permission): void
    {
        $permission = $permission instanceof RolePermission ? $permission : RolePermission::findOrFail($permission);

        DB::transaction(function () use ($actor, $role, $permission) {
            $holderIds = $role->assignments()->pluck('user_id')->all();

            $permission->delete();

            foreach ($holderIds as $userId) {
                $this->authorization->invalidate($userId);
            }

            AuthorizationAuditLog::record($actor, AuthorizationAuditLog::EVENT_PERMISSION_REVOKED, RolePermission::class, $permission->id, [
                'role_id' => $role->id,
                'action' => $permission->getOriginal('action') ?? null,
            ]);
        });
    }

    // ------------------------------------------------------------------
    // AC2/AC3 — assignment lifecycle (grant / revoke)
    // ------------------------------------------------------------------

    public function assign(User $actor, User $target, Role $role, ?\Carbon\Carbon $expiresAt = null): RoleAssignment
    {
        return DB::transaction(function () use ($actor, $target, $role, $expiresAt) {
            $assignment = RoleAssignment::updateOrCreate(
                ['user_id' => $target->id, 'role_id' => $role->id],
                [
                    'granted_by' => $actor->id,
                    'expires_at' => $expiresAt,
                ],
            );

            // AC3: the target's cached context must reflect this immediately.
            $this->authorization->invalidate($target);

            AuthorizationAuditLog::record($actor, AuthorizationAuditLog::EVENT_ASSIGNMENT_MADE, RoleAssignment::class, $assignment->id, [
                'user_id' => $target->id,
                'role_id' => $role->id,
                'expires_at' => $expiresAt?->toIso8601String(),
            ]);

            return $assignment;
        });
    }

    public function revokeAssignment(User $actor, User $target, Role $role): void
    {
        // AC4: revoking the last viable super-admin assignment is a lockout.
        if ($role->is_super_admin) {
            $this->assertNotLastSuperAdmin($actor, $role, 'revoke_assignment');
        }

        DB::transaction(function () use ($actor, $target, $role) {
            $existed = RoleAssignment::where('user_id', $target->id)->where('role_id', $role->id)->delete();

            // AC3: immediate invalidation — the next request is denied.
            $this->authorization->invalidate($target);

            AuthorizationAuditLog::record($actor, AuthorizationAuditLog::EVENT_ASSIGNMENT_REMOVED, RoleAssignment::class, null, [
                'user_id' => $target->id,
                'role_id' => $role->id,
                'existed' => (bool) $existed,
            ]);

            if ($existed > 0) {
                AuthorizationAuditLog::record($actor, AuthorizationAuditLog::EVENT_CACHE_INVALIDATED, User::class, $target->id);
            }
        });
    }

    // ------------------------------------------------------------------
    // AC1 — "cannot grant scope they do not possess" validation
    // ------------------------------------------------------------------

    /**
     * Every proposed permission row must fall inside the ACTOR's own effective
     * scope. Church-wide (HQ) actors may grant anything; branch-scoped actors
     * are limited to their own subtree and can never mint global grants.
     */
    public function assertGrantsWithinActorScope(User $actor, array $permissions): void
    {
        if ($permissions === []) {
            return;
        }

        // A super-admin (or church-wide privileged user) possesses the full scope.
        if ($this->actorPossessesFullScope($actor)) {
            return;
        }

        $scope = BranchScope::for($actor);

        foreach ($permissions as $p) {
            $scopeType = (string) ($p['scope_type'] ?? RolePermission::SCOPE_GLOBAL);
            $scopeId = isset($p['scope_id']) ? (int) $p['scope_id'] : null;

            // Global grants are church-wide by definition — only full-scope actors.
            if ($scopeType === RolePermission::SCOPE_GLOBAL || $scopeId === null) {
                throw new ScopeGrantDeniedException(
                    'You cannot grant a scope you do not possess: global (church-wide) grants require an HQ administrator.'
                );
            }

            // The scoped unit must exist and be inside the actor's own subtree.
            $org = Organization::whereKey($scopeId)->first();
            if ($org === null || ! $scope->includes($org)) {
                throw new ScopeGrantDeniedException(
                    "You cannot grant a scope you do not possess: organization #{$scopeId} is outside your branch."
                );
            }
        }
    }

    /**
     * Does the actor hold church-wide (HQ) scope? Branch-scoped users never do.
     */
    private function actorPossessesFullScope(User $actor): bool
    {
        if (! $actor->isPrivileged()) {
            return false;
        }

        // Church-wide: no branch assignment, or an explicit super-admin role.
        if ($actor->branch_id === null) {
            return true;
        }

        return $actor->isSuperAdmin();
    }

    /** @return array<string, mixed> */
    private function normalizeGrant(int $roleId, array $p): array
    {
        $scopeType = (string) ($p['scope_type'] ?? RolePermission::SCOPE_GLOBAL);

        if (! in_array($scopeType, RolePermission::SCOPE_TYPES, true)) {
            throw ValidationException::withMessages([
                'permissions' => ["Invalid scope type '{$scopeType}'. Allowed: " . implode(', ', RolePermission::SCOPE_TYPES) . '.'],
            ]);
        }

        $action = (string) ($p['action'] ?? '');
        if ($action === '') {
            throw ValidationException::withMessages(['permissions' => ['Each permission requires an action.']]);
        }

        return [
            'role_id' => $roleId,
            'scope_type' => $scopeType,
            'scope_id' => $scopeType === RolePermission::SCOPE_GLOBAL ? null : (isset($p['scope_id']) ? (int) $p['scope_id'] : null),
            'module' => $p['module'] ?? null,
            'function_name' => $p['function_name'] ?? null,
            'record_type' => $p['record_type'] ?? null,
            'action' => $action,
        ];
    }

    // ------------------------------------------------------------------
    // AC4 — last-super-admin break-glass guard
    // ------------------------------------------------------------------

    /**
     * Block (or break-glass) any change that would leave the church with zero
     * viable super-admin paths. The attempt is ALWAYS recorded first.
     */
    private function assertNotLastSuperAdmin(User $actor, Role $role, string $operation, array $options = []): void
    {
        if (! $this->wouldRemoveLastSuperAdminPath($role)) {
            return; // Another viable super-admin path remains — safe.
        }

        $breakGlassCode = (string) ($options['break_glass'] ?? '');
        $approvedCode = config('authz.break_glass_code');

        if ($approvedCode !== null && $breakGlassCode !== '' && hash_equals($approvedCode, $breakGlassCode)) {
            // Approved break-glass procedure: allow AND record it (AC4).
            AuthorizationAuditLog::record($actor, AuthorizationAuditLog::EVENT_BREAK_GLASS_APPROVED, Role::class, $role->id, [
                'operation' => $operation,
                'break_glass_used' => true,
            ]);

            return;
        }

        // Blocked: record the attempt (AC4 "records the attempted change"), then deny.
        AuthorizationAuditLog::record($actor, AuthorizationAuditLog::EVENT_LAST_SUPER_ADMIN_BLOCKED, Role::class, $role->id, [
            'operation' => $operation,
            'break_glass_attempted' => $breakGlassCode !== '',
        ]);

        throw new LastSuperAdminException(
            "This {$operation} would remove the last viable super-administrator path. An approved break-glass procedure is required."
        );
    }

    /**
     * Would removing this role's super-admin capability leave zero users with an
     * active super-admin assignment? (AC4: "last viable super-administrator path")
     */
    private function wouldRemoveLastSuperAdminPath(Role $role): bool
    {
        if (! $role->is_super_admin) {
            return false;
        }

        // Any OTHER role that is still a super-admin and has an active holder?
        $otherSuperRoles = Role::where('id', '!=', $role->id)
            ->where('is_super_admin', true)
            ->get();

        foreach ($otherSuperRoles as $candidate) {
            if ($this->hasActiveHolder($candidate)) {
                return false; // A viable path remains.
            }
        }

        // No other super role with an active holder: this one is the last path.
        return true;
    }

    private function hasActiveHolder(Role $role): bool
    {
        return RoleAssignment::where('role_id', $role->id)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->exists();
    }

    // ------------------------------------------------------------------
    // AC3 — targeted invalidation for a role's holders
    // ------------------------------------------------------------------

    private function invalidateHolders(User $actor, Role $role): void
    {
        foreach ($role->assignments()->pluck('user_id') as $userId) {
            $this->authorization->invalidate($userId);
        }

        AuthorizationAuditLog::record($actor, AuthorizationAuditLog::EVENT_CACHE_INVALIDATED, Role::class, $role->id, [
            'affected_users' => $role->assignments()->count(),
        ]);
    }
}
