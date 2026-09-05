<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReportSchedule;
use App\Services\ReportScheduleException;
use App\Services\ReportScheduleService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Story 13.5: scheduled report distribution API.
 */
class ReportScheduleController extends Controller
{
    public function __construct(
        private ReportScheduleService $schedules,
    ) {
    }

    public function catalog(Request $request): JsonResponse
    {
        try {
            return response()->json(['data' => $this->schedules->catalog($request->user())]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $items = $this->schedules->list($request->user());

            return response()->json([
                'data' => $items->map(fn (ReportSchedule $item) => $this->schedules->format($item))->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $item = $this->schedules->create($request->user(), $request->all());

            return response()->json(['data' => $this->schedules->format($item)], 201);
        } catch (ReportScheduleException $exception) {
            return $this->scheduleError($exception);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function show(Request $request, ReportSchedule $reportSchedule): JsonResponse
    {
        try {
            $item = $this->schedules->show($request->user(), $reportSchedule);

            return response()->json(['data' => $this->schedules->format($item)]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    private function scheduleError(ReportScheduleException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'code' => $exception->codeKey,
            'details' => $exception->details,
        ], $exception->status);
    }
}
