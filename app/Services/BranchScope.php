<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Thrown when a data-processing path has no valid scope context.
 * Background jobs, scheduled tasks, reports and webhooks must fail with this
 * rather than processing tenant-owned data unscoped (Story 1.4).
 */
class BranchScopeException extends \RuntimeException
{
}

/**
 * Story 1.4: branch data isolation + consolidated HQ views.
 *
 * Scope is derived ONLY from the authenticated user's server-side assignment
 * (users.branch_id):
 *   - branch_id NULL        -> church-wide (HQ) scope, sees all branches;
 *   - branch_id set         -> that branch and its descendants only.
 *
 * Client-supplied parameters are never consulted here, so tampering with query
 * strings or request bodies cannot widen a user's effective scope. If the
 * assigned branch no longer exists (or no principal is present at all) the
 * scope fails secure: zero rows / denied access — never unscoped processing.
 */
final class BranchScope
{
    private ?int $branchId = null;

    /** True when the user had a branch assignment that can no longer be resolved. */
    private bool $denied = false;

    private function __construct(User $user)
    {
        if ($user->branch_id === null) {
            return; // Church-wide (HQ) scope.
        }

        $this->branchId = (int) $user->branch_id;

        // Secure failure: the assigned branch was deleted -> deny everything
        // instead of silently widening to unscoped access.
        if (! Organization::whereKey($this->branchId)->exists()) {
            $this->denied = true;
        }
    }

    /**
     * Resolve a scope for the given principal.
     *
     * @throws BranchScopeException when no authenticated user is available —
     *                              background paths must fail secure, not run unscoped.
     */
    public static function for(?User $user): self
    {
        if (! $user instanceof User) {
            throw new BranchScopeException(
                'Missing scope context: refusing to process branch-owned data without an authenticated principal.'
            );
        }

        return new self($user);
    }

    public function isChurchWide(): bool
    {
        return ! $this->denied && $this->branchId === null;
    }

    /** The user's assigned branch id, or null for church-wide scope. */
    public function branchId(): ?int
    {
        return $this->denied ? null : $this->branchId;
    }

    public function isDenied(): bool
    {
        return $this->denied;
    }

    /**
     * Constrain an organizations query to this scope.
     */
    public function applyToQuery(Builder $query): Builder
    {
        if ($this->isChurchWide()) {
            return $query;
        }

        // Denied (unresolvable branch) or branch-scoped: never unscoped.
        if ($this->denied) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('organizations.id', $this->subtreeIds($this->branchId));
    }

    /**
     * Whether the given organization (or id) falls inside this scope.
     */
    public function includes(Organization|int $org): bool
    {
        if ($this->isChurchWide()) {
            return true;
        }

        if ($this->denied) {
            return false;
        }

        $currentId = $org instanceof Organization ? (int) $org->id : (int) $org;
        $visited = [];

        // Walk up the parent chain from the target until we hit our branch,
        // a root, or a cycle (visited set keeps bad data from looping forever).
        while ($currentId !== null && ! isset($visited[$currentId])) {
            if ($currentId === $this->branchId) {
                return true;
            }

            $visited[$currentId] = true;
            $parentId = Organization::whereKey($currentId)->value('parent_id');
            $currentId = $parentId !== null ? (int) $parentId : null;
        }

        return false;
    }

    /**
     * Assert access to an organization within this scope.
     *
     * @throws AuthorizationException (rendered as 403 for API requests)
     */
    public function assertIncludes(Organization|int $org): void
    {
        if (! $this->includes($org)) {
            throw new AuthorizationException(
                'You do not have access to this organization.'
            );
        }
    }

    /**
     * All ids in the branch subtree (the branch itself plus every descendant),
     * computed with a single query and cycle-safe. No cross-request caching:
     * long-lived processes must always see current data.
     */
    public function subtreeIds(int $rootId): array
    {
        $rows = Organization::query()->select('id', 'parent_id')->get();

        $childrenOf = [];
        foreach ($rows as $row) {
            if ($row->parent_id !== null) {
                $childrenOf[(int) $row->parent_id][] = (int) $row->id;
            }
        }

        $ids = [$rootId];
        $queue = [$rootId];

        while ($queue !== []) {
            $current = array_shift($queue);
            foreach ($childrenOf[$current] ?? [] as $child) {
                if (in_array($child, $ids, true)) {
                    continue; // Cycle guard: never revisit.
                }
                $ids[] = $child;
                $queue[] = $child;
            }
        }

        return $ids;
    }
}
