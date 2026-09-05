<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServiceTeam;
use App\Services\TeamDashboardConflictException;
use App\Services\TeamOperationsDashboardService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Story 5.8 / 12.3: team leader operations dashboard API.
 */
class TeamOperationsDashboardController extends Controller
{
    public function __construct(
        private TeamOperationsDashboardService $dashboard,
    ) {
    }

    public function teams(Request $request): JsonResponse
    {
        try {
            $teams = $this->dashboard->listAccessibleTeams($request->user());

            return response()->json([
                'data' => $teams->map(fn (ServiceTeam $team) => [
                    'id' => $team->id,
                    'name' => $team->name,
                    'branch_id' => $team->branch_id,
                    'category' => $team->category,
                    'status' => $team->status,
                ])->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function show(Request $request, ServiceTeam $serviceTeam): JsonResponse
    {
        try {
            return response()->json([
                'data' => $this->dashboard->dashboard($request->user(), $serviceTeam),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function drillDown(Request $request, ServiceTeam $serviceTeam, string $widget): JsonResponse
    {
        try {
            return response()->json([
                'data' => $this->dashboard->drillDown(
                    $request->user(),
                    $serviceTeam,
                    $widget,
                    $request->query(),
                ),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        } catch (ValidationException $e) {
            throw $e;
        }
    }

    public function sync(Request $request, ServiceTeam $serviceTeam): JsonResponse
    {
        $request->validate([
            'expected_version' => 'nullable|string|max:64',
            'action' => 'nullable|string|max:64',
        ]);

        try {
            return response()->json([
                'data' => $this->dashboard->syncAfterAction($request->user(), $serviceTeam, $request->all()),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        } catch (TeamDashboardConflictException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => $exception->codeKey,
                'current_version' => $exception->currentVersion,
            ], 409);
        }
    }
}
