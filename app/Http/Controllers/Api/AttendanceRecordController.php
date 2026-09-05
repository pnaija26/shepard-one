<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Services\AttendanceCaptureException;
use App\Services\AttendanceCaptureService;
use App\Services\AttendanceRecordService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Story 3.3: attendance recording API.
 */
class AttendanceRecordController extends Controller
{
    public function __construct(
        private AttendanceRecordService $records,
        private AttendanceCaptureService $capture,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $records = $this->records->listRecords($request->user(), $request->only([
                'subject_type', 'subject_id', 'branch_id',
            ]));

            return response()->json(['data' => $records]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $record = $this->records->recordAttendance($request->user(), $request->all());

            return response()->json(['data' => $record], 201);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function capture(Request $request): JsonResponse
    {
        try {
            $record = $this->capture->capture($request->user(), $request->all());

            return response()->json(['data' => $this->capture->formatRecord($record)], 201);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        } catch (AttendanceCaptureException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'reason' => $e->reason,
                'next_step' => $e->nextStep,
            ], $e->status);
        }
    }

    public function sync(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entries' => ['required', 'array', 'min:1'],
        ]);

        try {
            $result = $this->capture->syncOffline($request->user(), $validated['entries']);

            return response()->json(['data' => $result]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function sessionRecords(Request $request, string $sessionKey, int $sessionId): JsonResponse
    {
        try {
            $records = $this->capture->listSessionRecords($request->user(), $sessionKey, $sessionId);

            return response()->json([
                'data' => $records->map(fn ($record) => $this->capture->formatRecord($record))->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function correct(Request $request, AttendanceRecord $record): JsonResponse
    {
        try {
            $record = $this->records->correctAttendance($request->user(), $record, $request->all());

            return response()->json(['data' => $record]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }
}
