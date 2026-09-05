<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\MilestoneGreetingConfig;
use App\Models\MilestoneGreetingEvaluation;
use App\Services\MilestoneGreetingException;
use App\Services\MilestoneGreetingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Story 10.4: automated birthday and anniversary greetings.
 */
class MilestoneGreetingController extends Controller
{
    public function __construct(
        private MilestoneGreetingService $greetings,
    ) {
    }

    public function indexConfigs(Request $request): JsonResponse
    {
        try {
            $items = $this->greetings->listConfigs($request->user());

            return response()->json([
                'data' => $items->map(fn (MilestoneGreetingConfig $c) => $this->greetings->formatConfig($c))->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function upsertConfig(Request $request): JsonResponse
    {
        try {
            $item = $this->greetings->upsertConfig($request->user(), $request->all());

            return response()->json([
                'data' => $this->greetings->formatConfig($item),
            ]);
        } catch (MilestoneGreetingException $exception) {
            return $this->greetingError($exception);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function upsertMemberMilestone(Request $request, Member $member): JsonResponse
    {
        try {
            $item = $this->greetings->upsertMemberMilestone($request->user(), $member, $request->all());

            return response()->json([
                'data' => [
                    'id' => $item->id,
                    'member_id' => $item->member_id,
                    'type' => $item->type,
                    'occurred_on' => $item->occurred_on?->toDateString(),
                    'active' => $item->active,
                ],
            ]);
        } catch (MilestoneGreetingException $exception) {
            return $this->greetingError($exception);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function processWindow(Request $request): JsonResponse
    {
        try {
            return response()->json([
                'data' => $this->greetings->processWindow(
                    $request->user(),
                    $request->input('on'),
                    $request->filled('branch_id') ? (int) $request->input('branch_id') : null,
                ),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function listToday(Request $request): JsonResponse
    {
        try {
            return response()->json([
                'data' => $this->greetings->listForDate(
                    $request->user(),
                    $request->query('on'),
                    $request->query('type'),
                ),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function evaluations(Request $request): JsonResponse
    {
        try {
            $items = $this->greetings->listEvaluations($request->user(), $request->query());

            return response()->json([
                'data' => $items->map(fn (MilestoneGreetingEvaluation $e) => $this->greetings->formatEvaluation($e))->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    private function greetingError(MilestoneGreetingException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'code' => $exception->codeKey(),
            'details' => $exception->details(),
        ], $exception->httpStatus());
    }
}
