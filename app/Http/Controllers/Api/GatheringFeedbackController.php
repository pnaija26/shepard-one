<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GatheringFeedback;
use App\Services\GatheringFeedbackException;
use App\Services\GatheringFeedbackService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Story 4.5: gathering feedback submission and team workflow API.
 */
class GatheringFeedbackController extends Controller
{
    public function __construct(
        private GatheringFeedbackService $feedback,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $items = $this->feedback->listFeedback($request->user(), $request->only([
                'status', 'assigned_team', 'gathering_key', 'gathering_id',
            ]));

            return response()->json([
                'data' => $items->map(fn (GatheringFeedback $item) => $this->feedback->formatFeedback($item, true))->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $item = $this->feedback->submitFeedback($request->user(), $request->all());

            return response()->json([
                'data' => $this->feedback->formatFeedback($item, true),
                'message' => $this->submissionMessage($item->status),
            ], 201);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        } catch (GatheringFeedbackException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'reason' => $e->reason,
                'next_step' => $e->nextStep,
            ], $e->status);
        }
    }

    public function show(Request $request, GatheringFeedback $gatheringFeedback): JsonResponse
    {
        try {
            $item = $this->feedback->showFeedback($request->user(), $gatheringFeedback);

            return response()->json([
                'data' => $this->feedback->formatFeedback($item, true),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function recordActivity(Request $request, GatheringFeedback $gatheringFeedback): JsonResponse
    {
        try {
            $activity = $this->feedback->recordActivity($request->user(), $gatheringFeedback, $request->all());
            $item = $this->feedback->showFeedback($request->user(), $gatheringFeedback->fresh());

            return response()->json([
                'data' => [
                    'activity' => $activity,
                    'feedback' => $this->feedback->formatFeedback($item, true),
                ],
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    private function submissionMessage(string $status): string
    {
        return match ($status) {
            GatheringFeedback::STATUS_MODERATION_HOLD => 'Your feedback has been received and is pending review.',
            GatheringFeedback::STATUS_REJECTED => 'Your feedback could not be accepted.',
            default => 'Thank you. Your feedback has been submitted.',
        };
    }
}
