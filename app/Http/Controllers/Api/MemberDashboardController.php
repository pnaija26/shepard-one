<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MemberDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Story 12.2: member personal dashboard API.
 */
class MemberDashboardController extends Controller
{
    public function __construct(
        private MemberDashboardService $dashboard,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->dashboard->dashboard($request->user(), $request->query()),
        ]);
    }
}
