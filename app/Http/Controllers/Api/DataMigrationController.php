<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DataMigrationCutoverPlan;
use App\Models\DataMigrationMapping;
use App\Models\DataMigrationRun;
use App\Models\DataMigrationSource;
use App\Models\DataMigrationValidationRun;
use App\Services\DataMigrationCutoverService;
use App\Services\DataMigrationException;
use App\Services\DataMigrationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Story 15.1: legacy data profiling, mapping, and validation API.
 */
class DataMigrationController extends Controller
{
    public function __construct(
        private DataMigrationService $migrations,
        private DataMigrationCutoverService $cutover,
    ) {
    }

    public function catalog(Request $request): JsonResponse
    {
        try {
            return response()->json(['data' => $this->migrations->catalog($request->user())]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function indexSources(Request $request): JsonResponse
    {
        try {
            $items = $this->migrations->listSources($request->user());

            return response()->json([
                'data' => $items->map(fn (DataMigrationSource $item) => $this->migrations->formatSource($item))->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function storeSource(Request $request): JsonResponse
    {
        try {
            $item = $this->migrations->createSource($request->user(), $request->all());

            return response()->json(['data' => $this->migrations->formatSource($item)], 201);
        } catch (DataMigrationException $exception) {
            return $this->migrationError($exception);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function profileSource(Request $request, DataMigrationSource $dataMigrationSource): JsonResponse
    {
        try {
            $profile = $this->migrations->profile($request->user(), $dataMigrationSource);

            return response()->json([
                'data' => $this->migrations->formatProfile($profile),
            ]);
        } catch (DataMigrationException $exception) {
            return $this->migrationError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function storeMapping(Request $request, DataMigrationSource $dataMigrationSource): JsonResponse
    {
        try {
            $mapping = $this->migrations->createMapping($request->user(), $dataMigrationSource, $request->all());

            return response()->json(['data' => $this->migrations->formatMapping($mapping)], 201);
        } catch (DataMigrationException $exception) {
            return $this->migrationError($exception);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function updateMappingDraft(Request $request, DataMigrationMapping $dataMigrationMapping): JsonResponse
    {
        try {
            $version = $this->migrations->updateMappingDraft($request->user(), $dataMigrationMapping, $request->all());

            return response()->json([
                'data' => [
                    'mapping' => $this->migrations->formatMapping($dataMigrationMapping->fresh(['versions'])),
                    'version_number' => $version->version_number,
                ],
            ]);
        } catch (DataMigrationException $exception) {
            return $this->migrationError($exception);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function validateMapping(Request $request, DataMigrationMapping $dataMigrationMapping): JsonResponse
    {
        try {
            return response()->json([
                'data' => $this->migrations->validateMapping($request->user(), $dataMigrationMapping),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function testSample(Request $request, DataMigrationMapping $dataMigrationMapping): JsonResponse
    {
        try {
            return response()->json([
                'data' => $this->migrations->testSample($request->user(), $dataMigrationMapping),
            ]);
        } catch (DataMigrationException $exception) {
            return $this->migrationError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function runValidation(Request $request, DataMigrationMapping $dataMigrationMapping): JsonResponse
    {
        try {
            $run = $this->migrations->runValidation($request->user(), $dataMigrationMapping);

            return response()->json(['data' => $this->migrations->formatValidationRun($run)], 201);
        } catch (DataMigrationException $exception) {
            return $this->migrationError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function showValidationRun(Request $request, DataMigrationValidationRun $dataMigrationValidationRun): JsonResponse
    {
        try {
            $run = $this->migrations->showValidationRun($request->user(), $dataMigrationValidationRun);

            return response()->json(['data' => $this->migrations->formatValidationRun($run)]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function approveMapping(Request $request, DataMigrationMapping $dataMigrationMapping): JsonResponse
    {
        try {
            $mapping = $this->migrations->approveMapping($request->user(), $dataMigrationMapping);

            return response()->json(['data' => $this->migrations->formatMapping($mapping)]);
        } catch (DataMigrationException $exception) {
            return $this->migrationError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function createCutoverPlan(Request $request, DataMigrationMapping $dataMigrationMapping): JsonResponse
    {
        try {
            $plan = $this->cutover->createCutoverPlan($request->user(), $dataMigrationMapping, $request->all());

            return response()->json(['data' => $this->cutover->formatPlan($plan)], 201);
        } catch (DataMigrationException $exception) {
            return $this->migrationError($exception);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function showCutoverPlan(Request $request, DataMigrationCutoverPlan $dataMigrationCutoverPlan): JsonResponse
    {
        try {
            $plan = $this->cutover->showPlan($request->user(), $dataMigrationCutoverPlan);

            return response()->json(['data' => $this->cutover->formatPlan($plan)]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function runTestMigration(Request $request, DataMigrationCutoverPlan $dataMigrationCutoverPlan): JsonResponse
    {
        try {
            $run = $this->cutover->runTestMigration($request->user(), $dataMigrationCutoverPlan, $request->all());

            return response()->json(['data' => $this->cutover->formatRun($run)], 201);
        } catch (DataMigrationException $exception) {
            return $this->migrationError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function signOffUat(Request $request, DataMigrationCutoverPlan $dataMigrationCutoverPlan): JsonResponse
    {
        try {
            $plan = $this->cutover->signOffUat($request->user(), $dataMigrationCutoverPlan);

            return response()->json(['data' => $this->cutover->formatPlan($plan)]);
        } catch (DataMigrationException $exception) {
            return $this->migrationError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function executeProduction(Request $request, DataMigrationCutoverPlan $dataMigrationCutoverPlan): JsonResponse
    {
        try {
            $run = $this->cutover->executeProduction($request->user(), $dataMigrationCutoverPlan, $request->all());

            return response()->json(['data' => $this->cutover->formatRun($run)], 201);
        } catch (DataMigrationException $exception) {
            return $this->migrationError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function approveGoLive(Request $request, DataMigrationCutoverPlan $dataMigrationCutoverPlan): JsonResponse
    {
        try {
            $report = $this->cutover->approveGoLive($request->user(), $dataMigrationCutoverPlan);

            return response()->json(['data' => $report]);
        } catch (DataMigrationException $exception) {
            return $this->migrationError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function disposeMigration(Request $request, DataMigrationCutoverPlan $dataMigrationCutoverPlan): JsonResponse
    {
        try {
            $plan = $this->cutover->disposeMigration($request->user(), $dataMigrationCutoverPlan);

            return response()->json(['data' => $this->cutover->formatPlan($plan)]);
        } catch (DataMigrationException $exception) {
            return $this->migrationError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function showRun(Request $request, DataMigrationRun $dataMigrationRun): JsonResponse
    {
        try {
            $this->cutover->showPlan($request->user(), $dataMigrationRun->plan);

            return response()->json(['data' => $this->cutover->formatRun($dataMigrationRun->load(['importRecords', 'events']))]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    private function migrationError(DataMigrationException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'code' => $exception->codeKey,
            'details' => $exception->details,
        ], $exception->status);
    }
}
