<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OperationsAlert;
use App\Services\OperationsMonitoringException;
use App\Services\OperationsMonitoringService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Story 15.6: operations monitoring, backups, and recovery API.
 */
class OperationsMonitoringController extends Controller
{
    public function __construct(
        private OperationsMonitoringService $operations,
    ) {
    }

    public function catalog(Request $request): JsonResponse
    {
        try {
            return response()->json(['data' => $this->operations->catalog($request->user())]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function dashboard(Request $request): JsonResponse
    {
        try {
            return response()->json(['data' => $this->operations->dashboard($request->user())]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function collectTelemetry(Request $request): JsonResponse
    {
        try {
            $this->operations->catalog($request->user());

            return response()->json(['data' => $this->operations->collectTelemetry()]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function acknowledgeAlert(Request $request, OperationsAlert $operationsAlert): JsonResponse
    {
        try {
            $alert = $this->operations->acknowledgeAlert($request->user(), $operationsAlert);

            return response()->json(['data' => $this->operations->formatAlert($alert)]);
        } catch (OperationsMonitoringException $exception) {
            return $this->opsError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function resolveAlert(Request $request, OperationsAlert $operationsAlert): JsonResponse
    {
        try {
            $alert = $this->operations->resolveAlert($request->user(), $operationsAlert);

            return response()->json(['data' => $this->operations->formatAlert($alert)]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function indexBackups(Request $request): JsonResponse
    {
        try {
            $items = $this->operations->listBackups($request->user());

            return response()->json([
                'data' => $items->map(fn ($run) => $this->operations->formatBackupRun($run))->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function storeBackup(Request $request): JsonResponse
    {
        try {
            $run = $this->operations->recordBackupRun($request->user(), $request->all());

            return response()->json(['data' => $this->operations->formatBackupRun($run)], 201);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function indexRecoveryExercises(Request $request): JsonResponse
    {
        try {
            $items = $this->operations->listRecoveryExercises($request->user());

            return response()->json([
                'data' => $items->map(fn ($exercise) => $this->operations->formatRecoveryExercise($exercise))->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function storeRecoveryExercise(Request $request): JsonResponse
    {
        try {
            $exercise = $this->operations->completeRecoveryExercise($request->user(), $request->all());

            return response()->json(['data' => $this->operations->formatRecoveryExercise($exercise)], 201);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    private function opsError(OperationsMonitoringException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'code' => $exception->codeKey,
        ], $exception->getCode());
    }
}
