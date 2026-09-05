<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VolunteerProfile;
use App\Models\VolunteerProfileChange;
use App\Services\VolunteerProfileService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Story 5.3: volunteer profile coordinator API.
 */
class VolunteerProfileController extends Controller
{
    public function __construct(
        private VolunteerProfileService $profiles,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $items = $this->profiles->listProfiles($request->user(), $request->only('status'));

            return response()->json([
                'data' => $items->map(fn (VolunteerProfile $profile) => $this->profiles->formatProfile($profile, $request->user(), true))->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function alerts(Request $request): JsonResponse
    {
        try {
            return response()->json(['data' => $this->profiles->listAlerts($request->user())]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $profile = $this->profiles->createProfile($request->user(), $request->all());

            return response()->json([
                'data' => $this->profiles->formatProfile($profile, $request->user(), true),
            ], 201);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function show(Request $request, VolunteerProfile $volunteerProfile): JsonResponse
    {
        try {
            $profile = $this->profiles->showProfile($request->user(), $volunteerProfile);

            return response()->json([
                'data' => $this->profiles->formatProfile($profile, $request->user(), true),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function update(Request $request, VolunteerProfile $volunteerProfile): JsonResponse
    {
        try {
            $profile = $this->profiles->updateProfile($request->user(), $volunteerProfile, $request->all());

            return response()->json([
                'data' => $this->profiles->formatProfile($profile, $request->user(), true),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function verifyChange(Request $request, VolunteerProfileChange $change): JsonResponse
    {
        $validated = $request->validate([
            'approve' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $profile = $this->profiles->verifyPendingChange(
                $request->user(),
                $change,
                (bool) $validated['approve'],
                $validated['reason'] ?? null,
            );

            return response()->json([
                'data' => $this->profiles->formatProfile($profile, $request->user(), true),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }
}
