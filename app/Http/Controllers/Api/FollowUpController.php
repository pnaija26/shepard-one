<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FollowUp;
use App\Services\FollowUpService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Story 3.4: follow-up assignment and outcome tracking API.
 */
class FollowUpController extends Controller
{
    public function __construct(
        private FollowUpService $followUps,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $items = $this->followUps->listFollowUps($request->user(), $request->only(['status', 'assignee_id']));

            return response()->json([
                'data' => $items->map(fn (FollowUp $followUp) => $this->followUps->formatFollowUp($followUp))->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $followUp = $this->followUps->createFollowUp($request->user(), $request->all());

            return response()->json(['data' => $this->followUps->formatFollowUp($followUp)], 201);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function show(Request $request, FollowUp $followUp): JsonResponse
    {
        try {
            $followUp = $this->followUps->showFollowUp($request->user(), $followUp);
            $includeRestricted = $request->user()->id === $followUp->assignee_id
                || $request->user()->isChurchWide();

            return response()->json([
                'data' => $this->followUps->formatFollowUp($followUp, $includeRestricted),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function recordActivity(Request $request, FollowUp $followUp): JsonResponse
    {
        try {
            $activity = $this->followUps->recordActivity($request->user(), $followUp, $request->all());
            $followUp = $this->followUps->showFollowUp($request->user(), $followUp->fresh());

            return response()->json([
                'data' => [
                    'activity' => $activity,
                    'follow_up' => $this->followUps->formatFollowUp($followUp, true),
                ],
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function processEscalations(Request $request): JsonResponse
    {
        try {
            $counts = $this->followUps->processEscalations(
                $request->user(),
                $request->integer('branch_id') ?: null,
            );

            return response()->json(['data' => $counts]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }
}
