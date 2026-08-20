<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MemberMovement;
use App\Models\User;
use App\Services\BranchScope;
use App\Services\MovementConflictException;
use App\Services\MemberMovementService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Story 1.5: control cross-branch identity movement.
 *
 * Endpoints (all under /api/org/movements, auth:sanctum):
 *   GET    /            list movements visible to the caller's scope
 *   POST   /            initiate a pending movement for an existing person
 *   GET    /{movement}  detail incl. decision audit + association history
 *   POST   /{m}/approve destination or HQ approver approves (applies on effective date)
 *   POST   /{m}/reject  destination or HQ approver rejects (association unchanged)
 *
 * Scope is always derived server-side from the authenticated user's branch
 * assignment (BranchScope). Client-supplied parameters never widen it.
 */
class MemberMovementController extends Controller
{
    public function __construct(private readonly MemberMovementService $movements)
    {
    }

    /**
     * List movements visible to the caller: church-wide sees everything; a
     * branch-scoped user sees movements touching their subtree (source or
     * destination inside it, or the person currently in it).
     */
    public function index(Request $request): JsonResponse
    {
        $scope = $this->effectiveScope($request);

        $movements = MemberMovement::query()
            ->with(['person', 'sourceBranch', 'destinationBranch', 'initiator', 'decider'])
            ->when(! $scope->isChurchWide(), function ($query) use ($scope): void {
                // Visible if either endpoint of the movement is inside our subtree.
                // (A person's *current* branch may differ from source_branch_id once
                // a movement has been applied; both endpoints are recorded, so this
                // covers pre- and post-application visibility.)
                $subtree = collect($scope->subtreeIds((int) $scope->branchId()));

                $query->where(function ($q) use ($subtree): void {
                    $q->whereIn('source_branch_id', $subtree)
                        ->orWhereIn('destination_branch_id', $subtree);
                });
            })
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $movements,
            'meta' => [
                'scope' => $scope->isChurchWide() ? 'church-wide' : 'branch',
                'branch_id' => $scope->branchId(),
            ],
        ]);
    }

    /**
     * Initiate a pending movement for an existing centrally identified person.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'person_id' => ['required', 'integer', 'exists:users,id'],
            'destination_branch_id' => ['required', 'integer', 'exists:organizations,id'],
            'effective_date' => ['required', 'date', 'after_or_equal:today'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $movement = $this->movements->initiate(
            actor: $request->user(),
            personId: (int) $validated['person_id'],
            destinationBranchId: (int) $validated['destination_branch_id'],
            effectiveDate: $validated['effective_date'],
            reason: $validated['reason'],
        );

        return response()->json(
            $movement->load(['person', 'sourceBranch', 'destinationBranch', 'initiator']),
            201,
        );
    }

    /**
     * Movement detail with decision audit and the person's association history.
     */
    public function show(Request $request, MemberMovement $movement): JsonResponse
    {
        $scope = $this->effectiveScope($request);
        $this->assertVisible($scope, $movement);

        return response()->json([
            'data' => $movement->load(['person', 'sourceBranch', 'destinationBranch', 'initiator', 'decider']),
            'history' => $this->movements->historyFor((int) $movement->person_id),
        ]);
    }

    /**
     * People available as movement subjects, scoped to the viewer (Story 1.5 UI).
     * Church-wide users see everyone; branch-scoped users only their subtree.
     */
    public function people(Request $request): JsonResponse
    {
        $scope = $this->effectiveScope($request);

        $query = User::with('branch:id,name');

        if (! $scope->isChurchWide()) {
            $subtree = collect($scope->subtreeIds((int) $scope->branchId()));
            $query->whereIn('branch_id', $subtree);
        }

        return response()->json([
            'data' => $query->orderBy('name')->get(['id', 'name', 'email', 'branch_id']),
            'meta' => [
                'scope' => $scope->isChurchWide() ? 'church-wide' : 'branch',
                'branch_id' => $scope->branchId(),
            ],
        ]);
    }

    /**
     * Approve a pending movement (destination or HQ approver). The association
     * changes on the effective date — immediately if that date has arrived.
     */
    public function approve(Request $request, MemberMovement $movement): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $movement = $this->movements->approve(
            actor: $request->user(),
            movement: $movement,
            decisionReason: $validated['reason'] ?? null,
        );

        return response()->json($movement);
    }

    /**
     * Reject a pending movement (destination or HQ approver). The active branch
     * association is unchanged; the decision and reason are audited.
     */
    public function reject(Request $request, MemberMovement $movement): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $movement = $this->movements->reject(
            actor: $request->user(),
            movement: $movement,
            decisionReason: $validated['reason'],
        );

        return response()->json($movement);
    }

    /**
     * Resolve the caller's effective branch scope (Story 1.4 convention).
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
     * A movement is visible to a scope when either endpoint of it falls inside
     * that scope (church-wide sees all). Denied scopes see nothing.
     */
    private function assertVisible(BranchScope $scope, MemberMovement $movement): void
    {
        if ($scope->isChurchWide()) {
            return;
        }

        foreach ([$movement->source_branch_id, $movement->destination_branch_id] as $branchId) {
            if ($branchId !== null && $scope->includes((int) $branchId)) {
                return;
            }
        }

        throw new AuthorizationException('You do not have access to this movement.');
    }
}
