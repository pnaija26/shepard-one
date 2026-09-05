<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OperationalIncident;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\OperationalIncidentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Story 4.6: operational incident reporting and resolution API.
 */
class OperationalIncidentController extends Controller
{
    public function __construct(
        private OperationalIncidentService $incidents,
        private AuthorizationService $authorization,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $items = $this->incidents->listIncidents($request->user(), $request->only(['status', 'classification']));

            return response()->json([
                'data' => $items->map(fn (OperationalIncident $item) => $this->incidents->formatIncident(
                    $item,
                    $this->canViewSensitive($request->user(), $item),
                ))->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $item = $this->incidents->reportIncident($request->user(), $request->all());

            return response()->json([
                'data' => $this->incidents->formatIncident($item, true),
            ], 201);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function show(Request $request, OperationalIncident $operationalIncident): JsonResponse
    {
        try {
            $item = $this->incidents->showIncident($request->user(), $operationalIncident);

            return response()->json([
                'data' => $this->incidents->formatIncident(
                    $item,
                    $this->canViewSensitive($request->user(), $item),
                ),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function recordActivity(Request $request, OperationalIncident $operationalIncident): JsonResponse
    {
        try {
            $activity = $this->incidents->recordActivity($request->user(), $operationalIncident, $request->all());
            $item = $this->incidents->showIncident($request->user(), $operationalIncident->fresh());

            return response()->json([
                'data' => [
                    'activity' => $activity,
                    'incident' => $this->incidents->formatIncident($item, true),
                ],
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function review(Request $request, OperationalIncident $operationalIncident): JsonResponse
    {
        try {
            $item = $this->incidents->reviewClosure($request->user(), $operationalIncident, $request->all());

            return response()->json([
                'data' => $this->incidents->formatIncident($item, true),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function processEscalations(Request $request): JsonResponse
    {
        try {
            $counts = $this->incidents->processEscalations(
                $request->user(),
                $request->integer('branch_id') ?: null,
            );

            return response()->json(['data' => $counts]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    private function canViewSensitive(User $actor, OperationalIncident $incident): bool
    {
        if (! $incident->is_restricted) {
            return true;
        }

        return $actor->isChurchWide()
            || $actor->id === $incident->owner_id
            || $this->authorization->allows($actor, 'incidents.respond')
            || $this->authorization->allows($actor, 'incidents.review');
    }
}
