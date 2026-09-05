<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OnboardingEnrollment;
use App\Models\OnboardingJourney;
use App\Services\OnboardingJourneyService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Story 3.2: onboarding journey management API.
 */
class OnboardingJourneyController extends Controller
{
    public function __construct(
        private OnboardingJourneyService $onboarding,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $journeys = $this->onboarding->listJourneys($request->user());

            return response()->json([
                'data' => $journeys->map(fn (OnboardingJourney $journey) => $this->onboarding->formatJourney($journey))->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $journey = $this->onboarding->createJourney($request->user(), $request->all());

            return response()->json(['data' => $this->onboarding->formatJourney($journey)], 201);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function update(Request $request, OnboardingJourney $journey): JsonResponse
    {
        try {
            $journey = $this->onboarding->updateJourney($request->user(), $journey, $request->all());

            return response()->json(['data' => $this->onboarding->formatJourney($journey)]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function publish(Request $request, OnboardingJourney $journey): JsonResponse
    {
        $validated = $request->validate([
            'steps' => ['required', 'array', 'min:1'],
            'stop_conditions' => ['nullable', 'array'],
        ]);

        try {
            $version = $this->onboarding->publishJourney(
                $request->user(),
                $journey,
                $validated['steps'],
                $validated['stop_conditions'] ?? null,
            );

            return response()->json([
                'data' => [
                    'journey' => $this->onboarding->formatJourney($journey->fresh(['branch:id,name'])),
                    'version' => $version->version,
                ],
                'message' => 'Journey published successfully.',
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function enrollments(Request $request): JsonResponse
    {
        try {
            $enrollments = $this->onboarding->listEnrollments($request->user(), $request->only(['status']));

            return response()->json([
                'data' => $enrollments->map(fn (OnboardingEnrollment $enrollment) => $this->onboarding->formatEnrollment($enrollment))->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function showEnrollment(Request $request, OnboardingEnrollment $enrollment): JsonResponse
    {
        try {
            $enrollment = $this->onboarding->showEnrollment($request->user(), $enrollment);

            return response()->json(['data' => $this->onboarding->formatEnrollment($enrollment)]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function processDue(Request $request): JsonResponse
    {
        try {
            $counts = $this->onboarding->processDueSteps($request->user());

            return response()->json(['data' => $counts]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }
}
