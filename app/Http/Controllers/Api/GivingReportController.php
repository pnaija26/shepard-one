<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GivingAccessException;
use App\Services\GivingHistoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Story 11.3: finance giving reports and unauthorized-path denials.
 */
class GivingReportController extends Controller
{
    public function __construct(
        private GivingHistoryService $giving,
    ) {
    }

    public function report(Request $request): JsonResponse
    {
        try {
            return response()->json(['data' => $this->giving->financeReport($request->user(), $request->query())]);
        } catch (GivingAccessException $exception) {
            return $this->err($exception);
        }
    }

    /**
     * Catch-all style denial for disallowed export/dashboard/search surfaces.
     */
    public function unauthorized(Request $request): JsonResponse
    {
        try {
            $this->giving->denyUnauthorizedPath(
                $request->user(),
                (string) $request->query('path', $request->path()),
                $request->query(),
            );
        } catch (GivingAccessException $exception) {
            return $this->err($exception);
        }

        return response()->json(['message' => 'Forbidden.'], 403);
    }

    private function err(GivingAccessException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'code' => $exception->codeKey(),
            'details' => $exception->details(),
        ], $exception->httpStatus());
    }
}
