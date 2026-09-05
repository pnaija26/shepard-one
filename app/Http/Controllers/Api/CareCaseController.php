<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CareCase;
use App\Models\CareCaseEscalation;
use App\Services\CareCaseException;
use App\Services\CareCaseService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Stories 8.1–8.2: restricted pastoral care case API.
 */
class CareCaseController extends Controller
{
    public function __construct(
        private CareCaseService $careCases,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $items = $this->careCases->listCases(
                $request->user(),
                $request->only(['status', 'category']),
            );

            return response()->json([
                'data' => $items->map(
                    fn (CareCase $case) => $this->careCases->formatForActor($case, $request->user())
                )->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $case = $this->careCases->createCase($request->user(), $request->all());

            return response()->json([
                'data' => $this->careCases->formatForActor($case, $request->user()),
            ], 201);
        } catch (CareCaseException $exception) {
            return $this->careError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function show(Request $request, CareCase $careCase): JsonResponse
    {
        try {
            $case = $this->careCases->showCase($request->user(), $careCase);

            return response()->json([
                'data' => $this->careCases->formatForActor($case, $request->user()),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function recordActivity(Request $request, CareCase $careCase): JsonResponse
    {
        try {
            $activity = $this->careCases->recordActivity($request->user(), $careCase, $request->all());

            return response()->json([
                'data' => $this->careCases->formatActivity($activity, true),
                'case' => $this->careCases->formatForActor(
                    $careCase->fresh([
                        'activities.actor:id,name',
                        'activities.responsibleOfficer:id,name',
                        'escalations.toOfficer:id,name',
                        'assignedOfficer:id,name',
                    ]),
                    $request->user(),
                ),
            ], 201);
        } catch (CareCaseException $exception) {
            return $this->careError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function escalate(Request $request, CareCase $careCase): JsonResponse
    {
        try {
            $escalation = $this->careCases->escalateCase($request->user(), $careCase, $request->all());

            return response()->json([
                'data' => $this->careCases->formatEscalation($escalation),
                'case' => $this->careCases->formatForActor(
                    $careCase->fresh([
                        'activities.actor:id,name',
                        'escalations.toOfficer:id,name',
                        'assignedOfficer:id,name',
                    ]),
                    $request->user(),
                ),
            ], 201);
        } catch (CareCaseException $exception) {
            return $this->careError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function processEscalations(Request $request): JsonResponse
    {
        try {
            return response()->json([
                'data' => $this->careCases->processEscalations(
                    $request->user(),
                    $request->integer('branch_id') ?: null,
                ),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function acknowledgeEscalation(Request $request, CareCaseEscalation $careCaseEscalation): JsonResponse
    {
        try {
            $escalation = $this->careCases->acknowledgeEscalation($request->user(), $careCaseEscalation);

            return response()->json([
                'data' => $this->careCases->formatEscalation($escalation),
            ]);
        } catch (CareCaseException $exception) {
            return $this->careError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function close(Request $request, CareCase $careCase): JsonResponse
    {
        try {
            $case = $this->careCases->closeCase($request->user(), $careCase, $request->all());

            return response()->json([
                'data' => $this->careCases->formatForActor($case, $request->user()),
            ]);
        } catch (CareCaseException $exception) {
            return $this->careError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function reopen(Request $request, CareCase $careCase): JsonResponse
    {
        try {
            $case = $this->careCases->reopenCase($request->user(), $careCase, $request->all());

            return response()->json([
                'data' => $this->careCases->formatForActor($case, $request->user()),
            ]);
        } catch (CareCaseException $exception) {
            return $this->careError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    private function careError(CareCaseException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'code' => $exception->codeKey(),
            'details' => $exception->details(),
        ], $exception->httpStatus());
    }
}
