<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TeamRosterSlot;
use App\Services\TeamRosterService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Story 5.4: member roster response API.
 */
class MyRosterSlotController extends Controller
{
    public function __construct(
        private TeamRosterService $rosters,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $slots = $this->rosters->listMySlots($request->user());

            return response()->json([
                'data' => $slots->map(fn (TeamRosterSlot $slot) => $this->rosters->formatSlot($slot))->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function respond(Request $request, TeamRosterSlot $slot): JsonResponse
    {
        try {
            $slot = $this->rosters->respondToSlot($request->user(), $slot, $request->all());

            return response()->json(['data' => $this->rosters->formatSlot($slot)]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }
}
