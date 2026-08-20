<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\BranchScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OrganizationController extends Controller
{
    /**
     * Display a listing of the organizations.
     *
     * Story 1.4: the result set is constrained to the authenticated user's
     * effective scope (branch subtree, or church-wide for HQ users). The
     * response carries `meta.scope` so clients can render the correct context;
     * client-supplied parameters are never consulted when computing the scope.
     */
    public function index(Request $request): JsonResponse
    {
        $scope = $this->effectiveScope($request);

        // Return a flat list of organizations within scope; the SPA builds the
        // tree client-side so hierarchies of any depth render correctly.
        $organizations = $scope->applyToQuery(Organization::query())
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $organizations,
            'meta' => [
                'scope' => $scope->isChurchWide() ? 'church-wide' : 'branch',
                'branch_id' => $scope->branchId(),
            ],
        ]);
    }

    /**
     * Store a newly created organization in storage.
     */
    public function store(Request $request): JsonResponse
    {
        // Check if the user has permission to create organizations
        $user = $request->user();
        if (! $user->isPrivileged()) {
            return response()->json([
                'message' => 'Unauthorized to create organizations'
            ], 403);
        }

        $scope = $this->effectiveScope($request);

        // Validate the request
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:headquarters,branch,campus,location,ministry,department,team,group',
            'identifier' => 'required|string|unique:organizations,identifier|max:255',
            'parent_id' => 'nullable|exists:organizations,id',
            'description' => 'nullable|string',
            'attributes' => 'nullable|array',
            'is_active' => 'boolean',
        ]);

        // Validate parent organization exists and is valid for hierarchy
        $parentId = $validated['parent_id'] ?? null;
        if ($parentId) {
            $parent = Organization::find($parentId);
            if (! $parent) {
                throw ValidationException::withMessages([
                    'parent_id' => ['Parent organization does not exist']
                ]);
            }

            // Story 1.4: a branch-scoped user may only create units inside their own subtree.
            $scope->assertIncludes($parent);

            // Validate that the parent-child relationship is valid based on organization types
            // Prevent invalid hierarchies like team under team, or branch under team, etc.
            if (! $this->isValidHierarchy($validated['type'], $parent->type)) {
                throw ValidationException::withMessages([
                    'type' => ['Invalid hierarchy: ' . $validated['type'] . ' cannot be a child of ' . $parent->type]
                ]);
            }
        } elseif (! $scope->isChurchWide()) {
            // Story 1.4: branch-scoped users cannot create root-level units
            // (headquarters or new branches) — that is an HQ governance action.
            throw ValidationException::withMessages([
                'parent_id' => ['Branch administrators must create organizations under a parent within their own branch.']
            ]);
        }

        // Create the organization
        $organization = Organization::create($validated);

        return response()->json($organization, 201);
    }

    /**
     * Display the specified organization.
     */
    public function show(Request $request, Organization $organization): JsonResponse
    {
        // Story 1.4: cross-branch access is denied (403), never leaked.
        $this->effectiveScope($request)->assertIncludes($organization);

        return response()->json($organization->load(['parent', 'children']));
    }

    /**
     * Update the specified organization in storage.
     */
    public function update(Request $request, Organization $organization): JsonResponse
    {
        // Check if the user has permission to update organizations
        $user = $request->user();
        if (! $user->isPrivileged()) {
            return response()->json([
                'message' => 'Unauthorized to update organizations'
            ], 403);
        }

        // Story 1.4: the target must be inside the user's effective scope.
        $scope = $this->effectiveScope($request);
        $scope->assertIncludes($organization);

        // Validate the request
        $validated = $request->validate([
            'name' => 'string|max:255',
            'type' => 'in:headquarters,branch,campus,location,ministry,department,team,group',
            'identifier' => 'string|unique:organizations,identifier,' . $organization->id . '|max:255',
            'parent_id' => 'nullable|exists:organizations,id',
            'description' => 'nullable|string',
            'attributes' => 'nullable|array',
            'is_active' => 'boolean',
        ]);

        // Validate parent organization exists and is valid for hierarchy
        if (array_key_exists('parent_id', $validated)) {
            $parentId = $validated['parent_id'];
            if ($parentId) {
                if ((int) $parentId === (int) $organization->id) {
                    throw ValidationException::withMessages([
                        'parent_id' => ['An organization cannot be its own parent']
                    ]);
                }

                $parent = Organization::find($parentId);
                if (! $parent) {
                    throw ValidationException::withMessages([
                        'parent_id' => ['Parent organization does not exist']
                    ]);
                }

                // Story 1.4: a branch-scoped user may only reparent within their own subtree.
                $scope->assertIncludes($parent);

                // Prevent cycles: the new parent must not be a descendant of this organization,
                // otherwise the hierarchy would loop and the tree could never render.
                if ($this->isDescendant($organization, $parentId)) {
                    throw ValidationException::withMessages([
                        'parent_id' => ['Parent organization cannot be one of its own descendants']
                    ]);
                }

                // Validate that the parent-child relationship is valid based on organization types
                // Check if the new child type can be a child of the specified parent type
                $childType = $validated['type'] ?? $organization->type;
                if (! $this->isValidHierarchy($childType, $parent->type)) {
                    throw ValidationException::withMessages([
                        'type' => ['Invalid hierarchy: ' . $childType . ' cannot be a child of ' . $parent->type]
                    ]);
                }
            }
        }

        // Update the organization
        $organization->update($validated);

        return response()->json($organization);
    }

    /**
     * Remove the specified organization from storage.
     */
    public function destroy(Request $request, Organization $organization): JsonResponse
    {
        // Check if the user has permission to delete organizations
        $user = $request->user();
        if (! $user->isPrivileged()) {
            return response()->json([
                'message' => 'Unauthorized to delete organizations'
            ], 403);
        }

        // Story 1.4: the target must be inside the user's effective scope.
        $this->effectiveScope($request)->assertIncludes($organization);

        // Refuse to delete an organization that still has children — the FK is
        // ON DELETE SET NULL, so deleting would silently orphan them into roots.
        $childCount = $organization->children()->count();
        if ($childCount > 0) {
            throw ValidationException::withMessages([
                'parent_id' => [
                    'Cannot delete an organization that has ' . $childCount
                    . ' child organization(s). Move or remove its children first.'
                ]
            ]);
        }

        $organization->delete();

        return response()->json([
            'message' => 'Organization deleted successfully'
        ]);
    }

    /**
     * Resolve the caller's effective branch scope (Story 1.4).
     *
     * Scope comes exclusively from the authenticated user's server-side
     * assignment — never from request parameters. A privileged user whose
     * assigned branch no longer exists fails secure with a 403 instead of
     * falling back to unscoped access.
     */
    private function effectiveScope(Request $request): BranchScope
    {
        $scope = BranchScope::for($request->user());

        if ($scope->isDenied()) {
            throw new AuthorizationException(
                'Your branch assignment is invalid; contact an HQ administrator.'
            );
        }

        return $scope;
    }

    /**
     * Determine whether $candidateId is the organization itself or one of its descendants.
     */
    private function isDescendant(Organization $organization, int|string $candidateId): bool
    {
        if ((int) $candidateId === (int) $organization->id) {
            return true;
        }

        // Walk up from the candidate parent: if we reach the organization, it is an ancestor.
        $current = Organization::find($candidateId);
        $guard = 0;
        while ($current && $guard < 100) {
            if ((int) $current->id === (int) $organization->id) {
                return true;
            }

            $current = $current->parent_id ? Organization::find($current->parent_id) : null;
            $guard++;
        }

        return false;
    }

    /**
     * Validate if the hierarchy is valid based on organization types.
     */
    private function isValidHierarchy(string $childType, string $parentType): bool
    {
        // Define valid parent-child relationships 
        $validHierarchies = [
            'headquarters' => ['branch'],  // headquarters can have branches
            'branch' => ['campus', 'location', 'ministry', 'department'],  // branches can have campuses, locations, ministries, departments
            'campus' => ['location', 'ministry', 'department'],  // campuses can have locations, ministries, departments  
            'location' => ['ministry', 'department', 'team', 'group'],  // locations can have ministries, departments, teams, groups
            'ministry' => ['department', 'team', 'group'],  // ministries can have departments, teams, groups
            'department' => ['team', 'group'],  // departments can have teams, groups
            'team' => ['group'],  // teams can have groups
        ];

        // If parent type is not defined in valid hierarchies, it's a leaf node or root
        if (! isset($validHierarchies[$parentType])) {
            return false;
        }

        return in_array($childType, $validHierarchies[$parentType]);
    }
}
