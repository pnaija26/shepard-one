<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChurchEvent;
use App\Services\ChurchEventService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Story 4.2: church event planning and operations API.
 */
class ChurchEventController extends Controller
{
    public function __construct(
        private ChurchEventService $events,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $items = $this->events->listEvents($request->user(), $request->only(['branch_id', 'status']));

            return response()->json([
                'data' => $items->map(fn (ChurchEvent $event) => $this->events->formatEvent($event, $request->user()))->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $event = $this->events->createEvent($request->user(), $request->all());

            return response()->json(['data' => $this->events->formatEvent($event, $request->user())], 201);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function show(Request $request, ChurchEvent $churchEvent): JsonResponse
    {
        try {
            $event = $this->events->showEvent($request->user(), $churchEvent);

            return response()->json(['data' => $this->events->formatEvent($event, $request->user())]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function update(Request $request, ChurchEvent $churchEvent): JsonResponse
    {
        try {
            $event = $this->events->updateEvent($request->user(), $churchEvent, $request->all());

            return response()->json(['data' => $this->events->formatEvent($event, $request->user())]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function publish(Request $request, ChurchEvent $churchEvent): JsonResponse
    {
        try {
            $event = $this->events->publishEvent($request->user(), $churchEvent);

            return response()->json(['data' => $this->events->formatEvent($event, $request->user())]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function postpone(Request $request, ChurchEvent $churchEvent): JsonResponse
    {
        try {
            $event = $this->events->postponeEvent($request->user(), $churchEvent, $request->all());

            return response()->json(['data' => $this->events->formatEvent($event, $request->user())]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function cancel(Request $request, ChurchEvent $churchEvent): JsonResponse
    {
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        try {
            $event = $this->events->cancelEvent($request->user(), $churchEvent, $validated['reason'] ?? null);

            return response()->json(['data' => $this->events->formatEvent($event, $request->user())]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function complete(Request $request, ChurchEvent $churchEvent): JsonResponse
    {
        try {
            $event = $this->events->completeEvent($request->user(), $churchEvent);

            return response()->json(['data' => $this->events->formatEvent($event, $request->user())]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function close(Request $request, ChurchEvent $churchEvent): JsonResponse
    {
        try {
            $event = $this->events->closeEvent($request->user(), $churchEvent);

            return response()->json(['data' => $this->events->formatEvent($event, $request->user())]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }
}
