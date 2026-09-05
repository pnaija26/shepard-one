<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChurchGroup;
use App\Models\ChurchGroupMeeting;
use App\Models\ChurchGroupMeetingAttendance;
use App\Services\GroupMeetingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Story 6.2: group meetings and follow-up API.
 */
class ChurchGroupMeetingController extends Controller
{
    public function __construct(
        private GroupMeetingService $meetings,
    ) {
    }

    public function index(Request $request, ChurchGroup $churchGroup): JsonResponse
    {
        try {
            $items = $this->meetings->listMeetings($request->user(), $churchGroup);

            return response()->json([
                'data' => $items->map(fn (ChurchGroupMeeting $meeting) => $this->meetings->formatMeeting($meeting, $request->user()))->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function store(Request $request, ChurchGroup $churchGroup): JsonResponse
    {
        try {
            $meeting = $this->meetings->scheduleMeeting($request->user(), $churchGroup, $request->all());

            return response()->json([
                'data' => $this->meetings->formatMeeting($meeting, $request->user()),
            ], 201);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function show(Request $request, ChurchGroupMeeting $churchGroupMeeting): JsonResponse
    {
        try {
            $meeting = $this->meetings->showMeeting($request->user(), $churchGroupMeeting);

            return response()->json([
                'data' => $this->meetings->formatMeeting($meeting, $request->user()),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function record(Request $request, ChurchGroupMeeting $churchGroupMeeting): JsonResponse
    {
        try {
            $meeting = $this->meetings->recordActivity($request->user(), $churchGroupMeeting, $request->all());

            return response()->json([
                'data' => $this->meetings->formatMeeting($meeting, $request->user()),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function correctAttendance(Request $request, ChurchGroupMeetingAttendance $churchGroupMeetingAttendance): JsonResponse
    {
        try {
            $record = $this->meetings->correctAttendance($request->user(), $churchGroupMeetingAttendance, $request->all());

            return response()->json(['data' => $record]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function evaluateFollowUps(Request $request, ChurchGroupMeeting $churchGroupMeeting): JsonResponse
    {
        try {
            $result = $this->meetings->evaluateFollowUps($request->user(), $churchGroupMeeting, $request->all());

            return response()->json(['data' => $result]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function dashboard(Request $request, ChurchGroup $churchGroup): JsonResponse
    {
        try {
            return response()->json([
                'data' => $this->meetings->dashboardMetrics($request->user(), $churchGroup),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }
}
