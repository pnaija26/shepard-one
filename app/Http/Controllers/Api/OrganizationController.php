<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class OrganizationController extends Controller
{
    /**
     * Display a listing of the organizations.
     */
    public function index(Request $request): JsonResponse
    {
        // Return a flat list of every organization; the SPA builds the tree
        // client-side so hierarchies of any depth render correctly.
        $organizations = Organization::orderBy('name')->get();

        return response()->json($organizations);
    }

    /**
     * Store a newly created organization in storage.
     */
    public function store(Request $request): JsonResponse
    {
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

        // Check if the user has permission to create organizations
        $user = $request->user();
        if (!$user->isPrivileged()) {
            return response()->json([
                'message' => 'Unauthorized to create organizations'
            ], 403);
        }

        // Validate parent organization exists and is valid for hierarchy
        if ($validated['parent_id']) {
            $parent = Organization::find($validated['parent_id']);
            if (!$parent) {
                throw ValidationException::withMessages([
                    'parent_id' => ['Parent organization does not exist']
                ]);
            }

            // Validate that the parent-child relationship is valid based on organization types
            // Prevent invalid hierarchies like team under team, or branch under team, etc.
            if (!$this->isValidHierarchy($validated['type'], $parent->type)) {
                throw ValidationException::withMessages([
                    'type' => ['Invalid hierarchy: ' . $validated['type'] . ' cannot be a child of ' . $parent->type]
                ]);
            }
        }

        // Create the organization
        $organization = Organization::create($validated);

        return response()->json($organization, 201);
    }

    /**
     * Display the specified organization.
     */
    public function show(Organization $organization): JsonResponse
    {
        return response()->json($organization->load(['parent', 'children']));
    }

    /**
     * Update the specified organization in storage.
     */
    public function update(Request $request, Organization $organization): JsonResponse
    {
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

        // Check if the user has permission to update organizations
        $user = $request->user();
        if (!$user->isPrivileged()) {
            return response()->json([
                'message' => 'Unauthorized to update organizations'
            ], 403);
        }

        // Validate parent organization exists and is valid for hierarchy
        if (isset($validated['parent_id']) && $validated['parent_id']) {
            $parent = Organization::find($validated['parent_id']);
            if (!$parent) {
                throw ValidationException::withMessages([
                    'parent_id' => ['Parent organization does not exist']
                ]);
            }

            // Validate that the parent-child relationship is valid based on organization types
            // Check if the new child type can be a child of the specified parent type
            if (!empty($validated['type']) && !$this->isValidHierarchy($validated['type'], $parent->type)) {
                throw ValidationException::withMessages([
                    'type' => ['Invalid hierarchy: ' . $validated['type'] . ' cannot be a child of ' . $parent->type]
                ]);
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
        if (!$user->isPrivileged()) {
            return response()->json([
                'message' => 'Unauthorized to delete organizations'
            ], 403);
        }

        // For now, we'll just deactivate rather than delete to preserve history
        // In a real implementation, you might want to check for dependencies
        $organization->update(['is_active' => false]);

        return response()->json([
            'message' => 'Organization deactivated successfully'
        ]);
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
        if (!isset($validHierarchies[$parentType])) {
            return false;
        }

        return in_array($childType, $validHierarchies[$parentType]);
    }
}