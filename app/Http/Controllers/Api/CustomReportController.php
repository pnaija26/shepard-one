<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomReport;
use App\Services\CustomReportException;
use App\Services\CustomReportService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Story 13.3: custom report designer and runner API.
 */
class CustomReportController extends Controller
{
    public function __construct(
        private CustomReportService $reports,
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

    public function index(Request $request): JsonResponse
    {
        try {
            $items = $this->reports->list($request->user());

            return response()->json([
                'data' => $items->map(fn (CustomReport $item) => $this->reports->format($item))->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $item = $this->reports->create($request->user(), $request->all());

            return response()->json(['data' => $this->reports->format($item)], 201);
        } catch (CustomReportException $exception) {
            return $this->reportError($exception);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function show(Request $request, CustomReport $customReport): JsonResponse
    {
        try {
            $item = $this->reports->show($request->user(), $customReport);

            return response()->json(['data' => $this->reports->format($item)]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function updateDraft(Request $request, CustomReport $customReport): JsonResponse
    {
        try {
            $item = $this->reports->updateDraft($request->user(), $customReport, $request->all());

            return response()->json(['data' => $this->reports->format($item)]);
        } catch (CustomReportException $exception) {
            return $this->reportError($exception);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function validateDefinition(Request $request, CustomReport $customReport): JsonResponse
    {
        try {
            return response()->json([
                'data' => $this->reports->validateReportDefinition($request->user(), $customReport),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function preview(Request $request, CustomReport $customReport): JsonResponse
    {
        try {
            return response()->json([
                'data' => $this->reports->preview($request->user(), $customReport, $request->all()),
            ]);
        } catch (CustomReportException $exception) {
            return $this->reportError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function publish(Request $request, CustomReport $customReport): JsonResponse
    {
        try {
            $item = $this->reports->publish($request->user(), $customReport, $request->all());

            return response()->json(['data' => $this->reports->format($item)]);
        } catch (CustomReportException $exception) {
            return $this->reportError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function run(Request $request, CustomReport $customReport): JsonResponse
    {
        try {
            return response()->json([
                'data' => $this->reports->run($request->user(), $customReport, $request->query()),
            ]);
        } catch (CustomReportException $exception) {
            return $this->reportError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    private function reportError(CustomReportException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'code' => $exception->codeKey,
            'details' => $exception->details,
        ], $exception->status);
    }
}
