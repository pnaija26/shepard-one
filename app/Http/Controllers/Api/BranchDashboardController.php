<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\BranchDashboardService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Story 12.4: branch administrator operational dashboard API.
 */
class BranchDashboardController extends Controller
{
    public function __construct(
        private BranchDashboardService $dashboard,
    ) {
    }

    public function branches(Request $request): JsonResponse
    {
        try {
            $branches = $this->dashboard->listAccessibleBranches($request->user());

            return response()->json([
                'data' => $branches->map(fn (Organization $branch) => [
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'identifier' => $branch->identifier,
                    'type' => $branch->type,
                ])->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function show(Request $request, Organization $organization): JsonResponse
    {
        try {
            return response()->json([
                'data' => $this->dashboard->dashboard(
                    $request->user(),
                    $organization,
                    $request->query(),
                ),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        } catch (ValidationException $e) {
            throw $e;
        }
    }

    public function drillDown(Request $request, Organization $organization, string $metric): JsonResponse
    {
        try {
            return response()->json([
                'data' => $this->dashboard->drillDown(
                    $request->user(),
                    $organization,
                    $metric,
                    $request->query(),
                ),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        } catch (ValidationException $e) {
            throw $e;
        }
    }
}
