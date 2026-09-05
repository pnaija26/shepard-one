<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Story 1.6: the single source of truth for "can this user do X in context Y".
 *
 * AC2 — every enforcement path (service/background, gate, policy, middleware)
 *       funnels through {@see allows()}, so they all reach the same decision.
 *
 * AC3 — the computed permission set is cached per user with a bounded TTL
 *       ({@see CACHE_TTL}, the "agreed security window"). Any mutation that
 *       changes authorization (grant/revoke/assign/unassign) also performs a
 *       TARGETED invalidation of the affected users' cache entries, so
 *       revocation takes effect on the very next request — no redeploy. The
 *       TTL is the backstop for mutations made outside this service: even a
 *       stale entry can never outlive the security window.
 */
class AuthorizationService
{
    /** Agreed security window (seconds) for cached authorization context (AC3). */
    public const CACHE_TTL = 60;

    /** Cache key prefix — stable so targeted invalidation is trivial. */
    public const CACHE_PREFIX = 'authz:user:';

    /** @var string[] Actions granted to legacy privileged users (pre-1.6 data). */
    private const LEGACY_PRIVILEGED_ACTIONS = [
        'roles.manage',
        'organizations.read',
        'organizations.write',
        'organizations.delete',
        'movements.read',
        'movements.initiate',
        'movements.approve',
        'config.read',
        'config.manage',
        'audit.read',
        'audit.export',
        'members.read',
        'members.write',
        'members.archive',
        'members.preferences',
        'members.sensitive',
        'members.changes.review',
        'members.lifecycle.read',
        'members.lifecycle.manage',
        'members.lifecycle.approve',
        'households.read',
        'households.manage',
        'members.duplicates.review',
        'members.duplicates.merge',
        'membership_card.scan',
        'directory.read',
        'directory.export',
        'directory.staff',
        'visitors.read',
        'visitors.write',
        'visitors.export',
        'visitors.sensitive',
        'onboarding.read',
        'onboarding.manage',
        'attendance.read',
        'attendance.write',
        'attendance.exceptions.read',
        'attendance.exceptions.manage',
        'followups.read',
        'followups.manage',
        'followups.work',
        'followups.escalate',
        'services.read',
        'services.manage',
        'events.read',
        'events.manage',
        'events.budget.read',
        'events.registrations.read',
        'events.registrations.manage',
        'events.registrations.self',
        'events.admit.scan',
    ];

    /**
     * Does the user hold an effective grant for `action` in the given context?
     *
     * @param  User|null   $user      principal (null => background path, denied)
     * @param  string      $action    e.g. 'members.read', 'reports.export'
     * @param  int|null    $orgId     organization id the action targets (null = none)
     * @param  array       $context   optional narrowing: module/function_name/record_type
     */
    public function allows(?User $user, string $action, ?int $orgId = null, array $context = []): bool
    {
        if ($user === null || ! $user->exists) {
            return false; // background paths without a principal fail secure (Story 1.4 parity).
        }

        foreach ($this->effectivePermissions($user) as $grant) {
            if ($this->grantMatches($grant, $action, $orgId, $context)) {
                return true;
            }
        }

        // Migration bridge: users with legacy JSON roles but no scoped assignments
        // keep working until migrated to role_assignments (Stories 1.1–1.5 tests).
        if (! $this->hasActiveAssignments($user) && $this->legacyAllows($user, $action, $orgId)) {
            return true;
        }

        return false;
    }

    /**
     * Background/export/search paths must call this — never bypass the service.
     */
    public function allowsOrFail(?User $user, string $action, ?int $orgId = null, array $context = []): void
    {
        if (! $this->allows($user, $action, $orgId, $context)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    /**
     * The user's effective permission set: the union of every ACTIVE role
     * assignment (AC2), each expanded to its scoped permission rows. Cached per
     * user with a bounded TTL (AC3).
     *
     * @return RolePermission[]
     */
    public function effectivePermissions(User $user): array
    {
        $key = self::CACHE_PREFIX . $user->id;

        $cached = Cache::get($key);
        if ($cached !== null) {
            return $cached;
        }

        $permissions = RolePermission::query()
            ->whereHas('role.assignments', function ($q) use ($user) {
                $q->where('role_assignments.user_id', $user->id)
                    ->where(function ($qq) {
                        $qq->whereNull('expires_at')
                            ->orWhere('expires_at', '>', now());
                    });
            })
            ->get()
            ->all();

        Cache::put($key, $permissions, self::CACHE_TTL);

        return $permissions;
    }

    /**
     * Targeted invalidation (AC3): drop the cached authorization context for a
     * user so their next request recomputes from current data. Called by every
     * mutation in RoleManagementService.
     */
    public function invalidate(User|int $user): void
    {
        Cache::forget(self::CACHE_PREFIX . ($user instanceof User ? $user->id : (int) $user));
    }

    /** Invalidate the cached context for ALL users (role/permission changes). */
    public function invalidateAll(): void
    {
        // The array/file stores have no key scan; we track affected users via a
        // small registry so invalidation is exact, not a full flush.
        foreach ($this->trackedUserIds() as $id) {
            Cache::forget(self::CACHE_PREFIX . $id);
        }
    }

    /** @return int[] */
    private function trackedUserIds(): array
    {
        return \App\Models\RoleAssignment::query()->pluck('user_id')->unique()->values()->all();
    }

    private function hasActiveAssignments(User $user): bool
    {
        return RoleAssignment::query()
            ->where('user_id', $user->id)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->exists();
    }

    private function legacyAllows(User $user, string $action, ?int $orgId): bool
    {
        if (! $user->isPrivileged() || ! in_array($action, self::LEGACY_PRIVILEGED_ACTIONS, true)) {
            return false;
        }

        if ($user->isChurchWide()) {
            return true;
        }

        if ($orgId === null) {
            return true;
        }

        try {
            return BranchScope::for($user)->includes($orgId);
        } catch (BranchScopeException) {
            return false;
        }
    }

    // ------------------------------------------------------------------
    // Grant matching (AC1 dimensions + AC2 subtree semantics)
    // ------------------------------------------------------------------

    /**
     * Does one permission row cover the requested action in context?
     */
    private function grantMatches(RolePermission $grant, string $action, ?int $orgId, array $context): bool
    {
        // 1) Action: exact match or '*' wildcard.
        if ($grant->action !== '*' && $grant->action !== $action) {
            return false;
        }

        // 2) Scope: global grants cover everything; scoped grants must contain
        //    the target org (subtree semantics, same as Story 1.4 BranchScope).
        if (! $this->scopeCovers($grant, $orgId)) {
            return false;
        }

        // 3) Narrowing dimensions: a grant that specifies module/function/record
        //    type only matches when the request context supplies the SAME value
        //    (a grant without the dimension is unconstrained on it).
        foreach (['module' => 'module', 'function_name' => 'function_name', 'record_type' => 'record_type'] as $col => $ctxKey) {
            if ($grant->{$col} !== null && ($context[$ctxKey] ?? null) !== $grant->{$col}) {
                return false;
            }
        }

        return true;
    }

    /**
     * Scope containment: global always covers; a scoped grant covers the target
     * org when the target is the scope root or one of its descendants. A null
     * target (no specific org) is covered only by global grants — a scoped
     * grant must not silently widen to "anywhere".
     */
    private function scopeCovers(RolePermission $grant, ?int $orgId): bool
    {
        if ($grant->isGlobal()) {
            return true;
        }

        if ($orgId === null || $grant->scope_id === null) {
            return false;
        }

        // Walk up from the target org until we hit the scope root, a cycle, or
        // the top of the tree (cycle-safe, same pattern as BranchScope::includes).
        $currentId = (int) $orgId;
        $visited = [];

        while ($currentId !== null && ! isset($visited[$currentId])) {
            if ($currentId === (int) $grant->scope_id) {
                return true;
            }
            $visited[$currentId] = true;
            $parentId = Organization::whereKey($currentId)->value('parent_id');
            $currentId = $parentId !== null ? (int) $parentId : null;
        }

        return false;
    }
}
