<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\VolunteerProfileService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Story 5.3: volunteer self-service profile API.
 */
class MyVolunteerProfileController extends Controller
{
    public function __construct(
        private VolunteerProfileService $profiles,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        try {
            $profile = $this->profiles->profileForMember($request->user());

            return response()->json([
                'data' => $this->profiles->formatProfile($profile, $request->user()),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function update(Request $request): JsonResponse
    {
        try {
            $profile = $this->profiles->profileForMember($request->user());
            $updated = $this->profiles->updateProfile($request->user(), $profile, $request->all(), selfService: true);

            return response()->json([
                'data' => $this->profiles->formatProfile($updated, $request->user()),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }
}
