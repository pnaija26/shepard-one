<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StandardReportException;
use App\Services\StandardReportService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Story 13.2: standard church reports API.
 */
class StandardReportController extends Controller
{
    public function __construct(
        private StandardReportService $reports,
    ) {
    }

    public function catalog(Request $request): JsonResponse
    {
        try {
            return response()->json(['data' => $this->reports->catalog($request->user())]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function run(Request $request, string $reportKey): JsonResponse
    {
        try {
            return response()->json([
                'data' => $this->reports->run($request->user(), $reportKey, $request->query()),
            ]);
        } catch (StandardReportException $exception) {
            return $this->reportError($exception);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    private function reportError(StandardReportException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'code' => $exception->codeKey,
            'details' => $exception->details,
        ], $exception->status);
    }
}
