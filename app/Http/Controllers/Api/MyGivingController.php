<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GivingAccessException;
use App\Services\GivingHistoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Story 11.3: member giving history and statements.
 */
class MyGivingController extends Controller
{
    public function __construct(
        private GivingHistoryService $giving,
    ) {
    }

    public function history(Request $request): JsonResponse
    {
        try {
            return response()->json(['data' => $this->giving->memberHistory($request->user(), $request->query())]);
        } catch (GivingAccessException $exception) {
            return $this->err($exception);
        }
    }

    public function statement(Request $request): JsonResponse
    {
        try {
            $statement = $this->giving->requestStatement($request->user(), $request->all());

            return response()->json(['data' => $this->giving->formatStatement($statement)], 201);
        } catch (GivingAccessException $exception) {
            return $this->err($exception);
        }
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
