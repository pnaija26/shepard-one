<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServiceTeam;
use App\Models\TeamRoster;
use App\Models\TeamRosterSlot;
use App\Services\TeamRosterException;
use App\Services\TeamRosterService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Story 5.4: team roster publication API.
 */
class TeamRosterController extends Controller
{
    public function __construct(
        private TeamRosterService $rosters,
    ) {
    }

    public function index(Request $request, ServiceTeam $serviceTeam): JsonResponse
    {
        try {
            $items = $this->rosters->listRosters($request->user(), $serviceTeam);

            return response()->json([
                'data' => $items->map(fn (TeamRoster $roster) => $this->rosters->formatRoster($roster))->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function store(Request $request, ServiceTeam $serviceTeam): JsonResponse
    {
        try {
            $roster = $this->rosters->createRoster($request->user(), $serviceTeam, $request->all());

            return response()->json(['data' => $this->rosters->formatRoster($roster)], 201);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function show(Request $request, TeamRoster $teamRoster): JsonResponse
    {
        try {
            $roster = $this->rosters->showRoster($request->user(), $teamRoster);

            return response()->json(['data' => $this->rosters->formatRoster($roster)]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function addSlot(Request $request, TeamRoster $teamRoster): JsonResponse
    {
        try {
            $slot = $this->rosters->addSlot($request->user(), $teamRoster, $request->all());

            return response()->json(['data' => $this->rosters->formatSlot($slot)], 201);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function validateRoster(Request $request, TeamRoster $teamRoster): JsonResponse
    {
        try {
            $summary = $this->rosters->validateRoster($request->user(), $teamRoster);

            return response()->json(['data' => $summary]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function publish(Request $request, TeamRoster $teamRoster): JsonResponse
    {
        try {
            $roster = $this->rosters->publishRoster($request->user(), $teamRoster, $request->all());

            return response()->json(['data' => $this->rosters->formatRoster($roster)]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        } catch (TeamRosterException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'reason' => $e->reason,
                'overridable' => $e->overridable,
                'conflicts' => $e->conflicts,
            ], $e->status);
        }
    }

    public function substitute(Request $request, TeamRosterSlot $slot): JsonResponse
    {
        try {
            $replacement = $this->rosters->substituteSlot($request->user(), $slot, $request->all());

            return response()->json(['data' => $this->rosters->formatSlot($replacement)]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }
}
