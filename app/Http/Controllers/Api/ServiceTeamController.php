<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServiceTeam;
use App\Services\ServiceTeamService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Story 5.1: service team configuration API.
 */
class ServiceTeamController extends Controller
{
    public function __construct(
        private ServiceTeamService $teams,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $items = $this->teams->listTeams($request->user(), $request->only(['branch_id', 'status']));

            return response()->json([
                'data' => $items->map(fn (ServiceTeam $team) => $this->teams->formatTeam($team))->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $team = $this->teams->createTeam($request->user(), $request->all());

            return response()->json(['data' => $this->teams->formatTeam($team)], 201);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function show(Request $request, ServiceTeam $serviceTeam): JsonResponse
    {
        try {
            $team = $this->teams->showTeam($request->user(), $serviceTeam);

            return response()->json(['data' => $this->teams->formatTeam($team)]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function update(Request $request, ServiceTeam $serviceTeam): JsonResponse
    {
        try {
            $team = $this->teams->updateTeam($request->user(), $serviceTeam, $request->all());

            return response()->json(['data' => $this->teams->formatTeam($team)]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function activate(Request $request, ServiceTeam $serviceTeam): JsonResponse
    {
        try {
            $team = $this->teams->activateTeam($request->user(), $serviceTeam);

            return response()->json(['data' => $this->teams->formatTeam($team)]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function archive(Request $request, ServiceTeam $serviceTeam): JsonResponse
    {
        try {
            $team = $this->teams->archiveTeam($request->user(), $serviceTeam, $request->input('reason'));

            return response()->json(['data' => $this->teams->formatTeam($team)]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }
}
