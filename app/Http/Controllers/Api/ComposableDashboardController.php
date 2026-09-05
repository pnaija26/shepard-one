<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ComposableDashboard;
use App\Services\ComposableDashboardException;
use App\Services\ComposableDashboardService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Story 13.1: role-specific dashboard composer API.
 */
class ComposableDashboardController extends Controller
{
    public function __construct(
        private ComposableDashboardService $dashboards,
    ) {
    }

    public function catalog(Request $request): JsonResponse
    {
        try {
            return response()->json(['data' => $this->dashboards->catalog($request->user())]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $items = $this->dashboards->list($request->user());

            return response()->json([
                'data' => $items->map(fn (ComposableDashboard $item) => $this->dashboards->format($item))->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $item = $this->dashboards->create($request->user(), $request->all());

            return response()->json(['data' => $this->dashboards->format($item)], 201);
        } catch (ComposableDashboardException $exception) {
            return $this->dashboardError($exception);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function show(Request $request, ComposableDashboard $composableDashboard): JsonResponse
    {
        try {
            $item = $this->dashboards->show($request->user(), $composableDashboard);

            return response()->json(['data' => $this->dashboards->format($item)]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function updateDraft(Request $request, ComposableDashboard $composableDashboard): JsonResponse
    {
        try {
            $item = $this->dashboards->updateDraft($request->user(), $composableDashboard, $request->all());

            return response()->json(['data' => $this->dashboards->format($item)]);
        } catch (ComposableDashboardException $exception) {
            return $this->dashboardError($exception);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function validateDefinition(Request $request, ComposableDashboard $composableDashboard): JsonResponse
    {
        try {
            return response()->json([
                'data' => $this->dashboards->validateDefinition($request->user(), $composableDashboard),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function preview(Request $request, ComposableDashboard $composableDashboard): JsonResponse
    {
        try {
            return response()->json([
                'data' => $this->dashboards->preview($request->user(), $composableDashboard, $request->all()),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function publish(Request $request, ComposableDashboard $composableDashboard): JsonResponse
    {
        try {
            $item = $this->dashboards->publish($request->user(), $composableDashboard, $request->all());

            return response()->json(['data' => $this->dashboards->format($item)]);
        } catch (ComposableDashboardException $exception) {
            return $this->dashboardError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    private function dashboardError(ComposableDashboardException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'code' => $exception->codeKey,
            'details' => $exception->details,
        ], $exception->status);
    }
}
