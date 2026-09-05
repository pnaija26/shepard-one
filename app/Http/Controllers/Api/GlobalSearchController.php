<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GlobalSearchException;
use App\Services\GlobalSearchService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Story 14.3: global permission-filtered church search API.
 */
class GlobalSearchController extends Controller
{
    public function __construct(
        private GlobalSearchService $search,
    ) {
    }

    public function catalog(Request $request): JsonResponse
    {
        try {
            return response()->json(['data' => $this->search->catalog($request->user())]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $query = (string) $request->query('q', '');

            return response()->json([
                'data' => $this->search->search($request->user(), $query, $request->only('record_type')),
            ]);
        } catch (GlobalSearchException $exception) {
            return $this->searchError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function resolve(Request $request, string $recordType, int $recordId): JsonResponse
    {
        try {
            return response()->json([
                'data' => $this->search->resolve($request->user(), $recordType, $recordId),
            ]);
        } catch (GlobalSearchException $exception) {
            return $this->searchError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function syncFailures(Request $request): JsonResponse
    {
        try {
            $items = $this->search->listSyncFailures($request->user());

            return response()->json([
                'data' => $items->map(fn ($item) => $this->search->formatSyncFailure($item))->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function processRetries(Request $request): JsonResponse
    {
        try {
            return response()->json(['data' => $this->search->processRetriesFor($request->user())]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    private function searchError(GlobalSearchException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'code' => $exception->codeKey,
            'details' => $exception->details,
        ], $exception->status);
    }
}
