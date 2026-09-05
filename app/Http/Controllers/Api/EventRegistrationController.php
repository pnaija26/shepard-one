<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChurchEvent;
use App\Services\EventRegistrationException;
use App\Services\EventRegistrationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Story 4.3: event registration and admission API.
 */
class EventRegistrationController extends Controller
{
    public function __construct(
        private EventRegistrationService $registrations,
    ) {
    }

    public function index(Request $request, ChurchEvent $churchEvent): JsonResponse
    {
        try {
            $items = $this->registrations->listRegistrations($request->user(), $churchEvent);

            return response()->json([
                'data' => $items->map(fn ($registration) => $this->registrations->formatRegistration($registration, false))->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function store(Request $request, ChurchEvent $churchEvent): JsonResponse
    {
        try {
            $registration = $this->registrations->register($request->user(), $churchEvent, $request->all());

            return response()->json([
                'data' => $this->registrations->formatRegistration($registration),
            ], 201);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        } catch (EventRegistrationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'reason' => $e->reason,
                'next_step' => $e->nextStep,
            ], $e->status);
        }
    }

    public function scan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'event_id' => ['nullable', 'integer'],
        ]);

        try {
            $result = $this->registrations->admitByCredential(
                $request->user(),
                $validated['token'],
                $validated['event_id'] ?? null,
            );

            return response()->json(['data' => $result], $result['admitted'] ? 200 : 422);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }
}
