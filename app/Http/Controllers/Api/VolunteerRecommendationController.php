<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServiceTeam;
use App\Services\ServiceTeamAssignmentException;
use App\Services\VolunteerRecommendationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Story 5.9: volunteer recommendation API.
 */
class VolunteerRecommendationController extends Controller
{
    public function __construct(
        private VolunteerRecommendationService $recommendations,
    ) {
    }

    public function recommend(Request $request, ServiceTeam $serviceTeam): JsonResponse
    {
        try {
            return response()->json([
                'data' => $this->recommendations->recommend($request->user(), $serviceTeam, $request->all()),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function confirm(Request $request, ServiceTeam $serviceTeam): JsonResponse
    {
        try {
            $assignment = $this->recommendations->confirmRecommendation($request->user(), $serviceTeam, $request->all());

            return response()->json([
                'data' => [
                    'assignment_id' => $assignment->id,
                    'status' => $assignment->status,
                    'member_id' => $assignment->member_id,
                ],
                'message' => 'Volunteer assignment confirmed.',
            ], 201);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        } catch (ServiceTeamAssignmentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'reason' => $e->reason,
                'overridable' => $e->overridable,
            ], $e->status);
        }
    }
}
