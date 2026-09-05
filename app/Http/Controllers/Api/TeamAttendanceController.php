<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServiceTeam;
use App\Models\TeamAttendanceRecord;
use App\Models\TeamOccurrence;
use App\Services\TeamAttendanceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Story 5.5: team attendance capture and analysis API.
 */
class TeamAttendanceController extends Controller
{
    public function __construct(
        private TeamAttendanceService $attendance,
    ) {
    }

    public function listOccurrences(Request $request, ServiceTeam $serviceTeam): JsonResponse
    {
        try {
            $items = $this->attendance->listOccurrences($request->user(), $serviceTeam);

            return response()->json([
                'data' => $items->map(fn (TeamOccurrence $item) => $this->attendance->formatOccurrence($item))->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function createOccurrence(Request $request, ServiceTeam $serviceTeam): JsonResponse
    {
        try {
            $occurrence = $this->attendance->createOccurrence($request->user(), $serviceTeam, $request->all());

            return response()->json(['data' => $this->attendance->formatOccurrence($occurrence)], 201);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function showOccurrence(Request $request, TeamOccurrence $occurrence): JsonResponse
    {
        try {
            $item = $this->attendance->showOccurrence($request->user(), $occurrence);

            return response()->json(['data' => $this->attendance->formatOccurrence($item)]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function capture(Request $request, TeamOccurrence $occurrence): JsonResponse
    {
        $validated = $request->validate([
            'entries' => ['required', 'array', 'min:1'],
        ]);

        try {
            $result = $this->attendance->captureAttendance($request->user(), $occurrence, $validated['entries']);

            return response()->json(['data' => $result]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function correct(Request $request, TeamAttendanceRecord $record): JsonResponse
    {
        try {
            $record = $this->attendance->correctAttendance($request->user(), $record, $request->all());

            return response()->json(['data' => $this->attendance->formatRecord($record)]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function analyze(Request $request, ServiceTeam $serviceTeam): JsonResponse
    {
        try {
            $analysis = $this->attendance->analyzeTeam($request->user(), $serviceTeam, $request->only(['from_date', 'to_date']));

            return response()->json(['data' => $analysis]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }
}
