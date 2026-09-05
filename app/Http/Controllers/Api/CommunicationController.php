<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Communication;
use App\Services\CommunicationException;
use App\Services\CommunicationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Story 10.1: permission-aware multi-channel communications.
 */
class CommunicationController extends Controller
{
    public function __construct(
        private CommunicationService $communications,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $items = $this->communications->list($request->user(), $request->query());

            return response()->json([
                'data' => $items->map(fn (Communication $item) => $this->communications->format($item))->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $item = $this->communications->create($request->user(), $request->all());

            return response()->json([
                'data' => $this->communications->format($item, includeBody: true),
            ], 201);
        } catch (CommunicationException $exception) {
            return $this->commError($exception);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function show(Request $request, Communication $communication): JsonResponse
    {
        try {
            $item = $this->communications->show($request->user(), $communication);
            $includeBody = $request->boolean('include_body');

            return response()->json([
                'data' => $this->communications->format($item, includeBody: $includeBody),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function cancel(Request $request, Communication $communication): JsonResponse
    {
        try {
            $item = $this->communications->cancel($request->user(), $communication);

            return response()->json([
                'data' => $this->communications->format($item),
            ]);
        } catch (CommunicationException $exception) {
            return $this->commError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function suppress(Request $request): JsonResponse
    {
        try {
            $item = $this->communications->suppress($request->user(), $request->all());

            return response()->json([
                'data' => [
                    'id' => $item->id,
                    'member_id' => $item->member_id,
                    'channel' => $item->channel,
                    'reason' => $item->reason,
                    'active' => $item->active,
                    'suppressed_at' => $item->suppressed_at?->toIso8601String(),
                ],
            ], 201);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function processDue(Request $request): JsonResponse
    {
        try {
            $branchId = $request->query('branch_id');

            return response()->json([
                'data' => $this->communications->processDue(
                    $request->user(),
                    $branchId !== null ? (int) $branchId : null,
                ),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function processRetries(Request $request): JsonResponse
    {
        try {
            $branchId = $request->query('branch_id');

            return response()->json([
                'data' => $this->communications->processRetries(
                    $request->user(),
                    $branchId !== null ? (int) $branchId : null,
                ),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    private function commError(CommunicationException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'code' => $exception->codeKey(),
            'details' => $exception->details(),
        ], $exception->httpStatus());
    }
}
