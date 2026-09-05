<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\HqDashboardService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Story 12.5: HQ leadership consolidated dashboard API.
 */
class HqDashboardController extends Controller
{
    public function __construct(
        private HqDashboardService $dashboard,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        try {
            return response()->json([
                'data' => $this->dashboard->dashboard($request->user(), $request->query()),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        } catch (ValidationException $e) {
            throw $e;
        }
    }

    public function drillDown(Request $request, string $metric): JsonResponse
    {
        try {
            return response()->json([
                'data' => $this->dashboard->drillDown(
                    $request->user(),
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
