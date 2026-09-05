<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServiceTeam;
use App\Models\ServiceTeamAssignment;
use App\Services\ServiceTeamAssignmentException;
use App\Services\ServiceTeamAssignmentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Story 5.2: service team assignment API.
 */
class ServiceTeamAssignmentController extends Controller
{
    public function __construct(
        private ServiceTeamAssignmentService $assignments,
    ) {
    }

    public function index(Request $request, ServiceTeam $serviceTeam): JsonResponse
    {
        try {
            $items = $this->assignments->listAssignments($request->user(), $serviceTeam);

            return response()->json([
                'data' => $items->map(fn ($item) => $this->assignments->formatAssignment($item))->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function store(Request $request, ServiceTeam $serviceTeam): JsonResponse
    {
        try {
            $assignment = $this->assignments->assignMember($request->user(), $serviceTeam, $request->all());

            return response()->json(['data' => $this->assignments->formatAssignment($assignment)], 201);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        } catch (ServiceTeamAssignmentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'reason' => $e->reason,
                'overridable' => $e->overridable,
            ], $e->status);
        }
    }

    public function bulkStore(Request $request, ServiceTeam $serviceTeam): JsonResponse
    {
        $validated = $request->validate([
            'entries' => ['required', 'array', 'min:1'],
        ]);

        try {
            $result = $this->assignments->bulkAssign($request->user(), $serviceTeam, $validated['entries']);

            return response()->json(['data' => $result], 201);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        } catch (ServiceTeamAssignmentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'reason' => $e->reason,
                'overridable' => $e->overridable,
            ], $e->status);
        }
    }

    public function approve(Request $request, ServiceTeamAssignment $assignment): JsonResponse
    {
        try {
            $assignment = $this->assignments->approveAssignment($request->user(), $assignment);

            return response()->json(['data' => $this->assignments->formatAssignment($assignment)]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function transfer(Request $request, ServiceTeamAssignment $assignment): JsonResponse
    {
        try {
            $assignment = $this->assignments->transferAssignment($request->user(), $assignment, $request->all());

            return response()->json(['data' => $this->assignments->formatAssignment($assignment)]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        } catch (ServiceTeamAssignmentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'reason' => $e->reason,
                'overridable' => $e->overridable,
            ], $e->status);
        }
    }

    public function remove(Request $request, ServiceTeamAssignment $assignment): JsonResponse
    {
        try {
            $assignment = $this->assignments->removeAssignment($request->user(), $assignment, $request->input('reason'));

            return response()->json(['data' => $this->assignments->formatAssignment($assignment)]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }
}
