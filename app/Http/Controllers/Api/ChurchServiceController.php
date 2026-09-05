<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChurchService;
use App\Services\ChurchServiceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Story 4.1: church service scheduling API.
 */
class ChurchServiceController extends Controller
{
    public function __construct(
        private ChurchServiceService $services,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $items = $this->services->listServices($request->user(), $request->only([
                'branch_id', 'status', 'from_date',
            ]));

            return response()->json([
                'data' => $items->map(fn (ChurchService $service) => $this->services->formatService($service))->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $service = $this->services->createService($request->user(), $request->all());

            return response()->json(['data' => $this->services->formatService($service)], 201);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function show(Request $request, ChurchService $churchService): JsonResponse
    {
        try {
            $service = $this->services->showService($request->user(), $churchService);

            return response()->json(['data' => $this->services->formatService($service)]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function update(Request $request, ChurchService $churchService): JsonResponse
    {
        try {
            $service = $this->services->updateService($request->user(), $churchService, $request->all());

            return response()->json(['data' => $this->services->formatService($service)]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function publish(Request $request, ChurchService $churchService): JsonResponse
    {
        try {
            $service = $this->services->publishService($request->user(), $churchService);

            return response()->json(['data' => $this->services->formatService($service)]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function cancel(Request $request, ChurchService $churchService): JsonResponse
    {
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        try {
            $service = $this->services->cancelService($request->user(), $churchService, $validated['reason'] ?? null);

            return response()->json(['data' => $this->services->formatService($service)]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }
}
