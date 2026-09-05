<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\RoleManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Story 1.6: role and permission management API (AC1–AC4).
 */
class RoleController extends Controller
{
    public function __construct(
        private RoleManagementService $roles,
        private AuthorizationService $authorization,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAction($request, 'roles.manage');

        $roles = Role::with('permissions')
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role) => $this->formatRole($role));

        return response()->json(['data' => $roles]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeAction($request, 'roles.manage');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'is_super_admin' => 'boolean',
            'permissions' => 'array',
            'permissions.*.scope_type' => ['nullable', 'string', Rule::in(RolePermission::SCOPE_TYPES)],
            'permissions.*.scope_id' => 'nullable|integer|exists:organizations,id',
            'permissions.*.module' => 'nullable|string|max:255',
            'permissions.*.function_name' => 'nullable|string|max:255',
            'permissions.*.record_type' => 'nullable|string|max:255',
            'permissions.*.action' => 'required_with:permissions|string|max:255',
        ]);

        $role = $this->roles->create($request->user(), $validated);

        return response()->json(['data' => $this->formatRole($role->load('permissions'))], 201);
    }

    public function show(Request $request, Role $role): JsonResponse
    {
        $this->authorizeAction($request, 'roles.manage');

        return response()->json(['data' => $this->formatRole($role->load('permissions'))]);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        $this->authorizeAction($request, 'roles.manage');

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'is_super_admin' => 'boolean',
            'permissions' => 'array',
            'permissions.*.scope_type' => ['nullable', 'string', Rule::in(RolePermission::SCOPE_TYPES)],
            'permissions.*.scope_id' => 'nullable|integer|exists:organizations,id',
            'permissions.*.module' => 'nullable|string|max:255',
            'permissions.*.function_name' => 'nullable|string|max:255',
            'permissions.*.record_type' => 'nullable|string|max:255',
            'permissions.*.action' => 'required_with:permissions|string|max:255',
            'break_glass' => 'nullable|string',
        ]);

        $options = ['break_glass' => $validated['break_glass'] ?? ''];
        unset($validated['break_glass']);

        $role = $this->roles->update($request->user(), $role, $validated, $options);

        return response()->json(['data' => $this->formatRole($role->load('permissions'))]);
    }

    public function destroy(Request $request, Role $role): JsonResponse
    {
        $this->authorizeAction($request, 'roles.manage');

        $validated = $request->validate([
            'break_glass' => 'nullable|string',
        ]);

        $this->roles->delete($request->user(), $role, [
            'break_glass' => $validated['break_glass'] ?? '',
        ]);

        return response()->json(['message' => 'Role deleted successfully.']);
    }

    public function assign(Request $request, Role $role): JsonResponse
    {
        $this->authorizeAction($request, 'roles.manage');

        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'expires_at' => 'nullable|date|after:now',
        ]);

        $target = User::findOrFail($validated['user_id']);
        $expiresAt = isset($validated['expires_at']) ? \Carbon\Carbon::parse($validated['expires_at']) : null;

        $assignment = $this->roles->assign($request->user(), $target, $role, $expiresAt);

        return response()->json(['data' => $assignment->load(['user', 'role'])], 201);
    }

    public function revokeAssignment(Request $request, Role $role, User $user): JsonResponse
    {
        $this->authorizeAction($request, 'roles.manage');

        $validated = $request->validate([
            'break_glass' => 'nullable|string',
        ]);

        $this->roles->revokeAssignment($request->user(), $user, $role);

        return response()->json(['message' => 'Role assignment revoked.']);
    }

    private function authorizeAction(Request $request, string $action): void
    {
        if (! $this->authorization->allows($request->user(), $action)) {
            abort(403, 'Forbidden.');
        }
    }

    /** @return array<string, mixed> */
    private function formatRole(Role $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'description' => $role->description,
            'is_super_admin' => $role->is_super_admin,
            'is_system' => $role->is_system,
            'permissions' => $role->permissions->map(fn (RolePermission $p) => [
                'id' => $p->id,
                'scope_type' => $p->scope_type,
                'scope_id' => $p->scope_id,
                'module' => $p->module,
                'function_name' => $p->function_name,
                'record_type' => $p->record_type,
                'action' => $p->action,
                'label' => $this->permissionLabel($p),
            ])->values()->all(),
        ];
    }

    private function permissionLabel(RolePermission $permission): string
    {
        $scope = $permission->isGlobal()
            ? 'global'
            : "{$permission->scope_type}:{$permission->scope_id}";

        return "{$scope} → {$permission->action}";
    }
}
