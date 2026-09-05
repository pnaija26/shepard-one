<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChurchContent;
use App\Services\ChurchContentException;
use App\Services\ChurchContentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Story 10.7: publish church content.
 */
class ChurchContentController extends Controller
{
    public function __construct(
        private ChurchContentService $contents,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $items = $this->contents->listAdmin($request->user());

            return response()->json([
                'data' => $items->map(fn (ChurchContent $c) => $this->contents->format($c))->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $item = $this->contents->create($request->user(), $request->all());

            return response()->json(['data' => $this->contents->format($item)], 201);
        } catch (ChurchContentException $exception) {
            return $this->ccError($exception);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function show(Request $request, ChurchContent $churchContent): JsonResponse
    {
        try {
            $item = $this->contents->show($request->user(), $churchContent);

            return response()->json(['data' => $this->contents->format($item)]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function updateDraft(Request $request, ChurchContent $churchContent): JsonResponse
    {
        try {
            $item = $this->contents->updateDraft($request->user(), $churchContent, $request->all());

            return response()->json(['data' => $this->contents->format($item)]);
        } catch (ChurchContentException $exception) {
            return $this->ccError($exception);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function validateDefinition(Request $request, ChurchContent $churchContent): JsonResponse
    {
        try {
            return response()->json(['data' => $this->contents->validate($request->user(), $churchContent)]);
        } catch (ChurchContentException $exception) {
            return $this->ccError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function preview(Request $request, ChurchContent $churchContent): JsonResponse
    {
        try {
            return response()->json(['data' => $this->contents->preview($request->user(), $churchContent, $request->all())]);
        } catch (ChurchContentException $exception) {
            return $this->ccError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function submit(Request $request, ChurchContent $churchContent): JsonResponse
    {
        try {
            $item = $this->contents->submitForApproval($request->user(), $churchContent);

            return response()->json(['data' => $this->contents->format($item)]);
        } catch (ChurchContentException $exception) {
            return $this->ccError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function approve(Request $request, ChurchContent $churchContent): JsonResponse
    {
        try {
            $item = $this->contents->approve($request->user(), $churchContent, $request->all());

            return response()->json(['data' => $this->contents->format($item)]);
        } catch (ChurchContentException $exception) {
            return $this->ccError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function withdraw(Request $request, ChurchContent $churchContent): JsonResponse
    {
        try {
            $item = $this->contents->withdraw($request->user(), $churchContent, $request->input('reason'));

            return response()->json(['data' => $this->contents->format($item)]);
        } catch (ChurchContentException $exception) {
            return $this->ccError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function processWindows(Request $request): JsonResponse
    {
        try {
            $branchId = $request->query('branch_id');

            return response()->json([
                'data' => $this->contents->processWindows(
                    $request->user(),
                    $branchId !== null ? (int) $branchId : null,
                ),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function feed(Request $request): JsonResponse
    {
        try {
            $items = $this->contents->feed($request->user(), $request->query());

            return response()->json([
                'data' => $items->map(fn (ChurchContent $c) => $this->contents->format($c, includeDraftBody: false))->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function search(Request $request): JsonResponse
    {
        try {
            $items = $this->contents->search(
                $request->user(),
                (string) $request->query('q', ''),
                $request->query(),
            );

            return response()->json([
                'data' => $items->map(fn (ChurchContent $c) => $this->contents->format($c, includeDraftBody: false))->values(),
            ]);
        } catch (ChurchContentException $exception) {
            return $this->ccError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    private function ccError(ChurchContentException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'code' => $exception->codeKey(),
            'details' => $exception->details(),
        ], $exception->httpStatus());
    }
}
