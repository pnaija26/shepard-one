<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ComposableDashboardService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Story 13.1: assigned composable dashboard runtime API.
 */
class MyComposableDashboardController extends Controller
{
    public function __construct(
        private ComposableDashboardService $dashboards,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        try {
            return response()->json([
                'data' => $this->dashboards->runtimeForUser($request->user(), $request->query()),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }
}
