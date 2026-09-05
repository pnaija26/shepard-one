<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WelfareRequest;
use App\Services\WelfareAssessmentService;
use App\Services\WelfareFollowUpService;
use App\Services\WelfareReportService;
use App\Services\WelfareRequestException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Story 7.5: welfare follow-up, closure, overdue processing, and reporting.
 */
class WelfareFollowUpController extends Controller
{
    public function __construct(
        private WelfareFollowUpService $followUps,
        private WelfareReportService $reports,
        private WelfareAssessmentService $assessments,
    ) {
    }

    public function store(Request $request, WelfareRequest $welfareRequest): JsonResponse
    {
        try {
            $entry = $this->followUps->recordFollowUp($request->user(), $welfareRequest, $request->all());

            return response()->json([
                'data' => $this->followUps->formatEntry($entry),
                'case' => $this->assessments->formatForActor(
                    $welfareRequest->fresh([
                        'followUpEntries.recordedBy',
                        'deliveries.confirmation',
                        'assessments',
                        'caseEvents',
                    ]),
                    $request->user(),
                ),
            ], 201);
        } catch (WelfareRequestException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => $exception->codeKey(),
                'details' => $exception->details(),
            ], $exception->httpStatus());
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function close(Request $request, WelfareRequest $welfareRequest): JsonResponse
    {
        try {
            $entry = $this->followUps->closeCase($request->user(), $welfareRequest, $request->all());

            return response()->json([
                'data' => $this->followUps->formatEntry($entry),
                'case' => $this->assessments->formatForActor(
                    $welfareRequest->fresh(['followUpEntries.recordedBy', 'deliveries.confirmation']),
                    $request->user(),
                ),
            ]);
        } catch (WelfareRequestException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => $exception->codeKey(),
                'details' => $exception->details(),
            ], $exception->httpStatus());
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function processOverdue(Request $request): JsonResponse
    {
        try {
            $counts = $this->followUps->processOverdue(
                $request->user(),
                $request->integer('branch_id') ?: null,
            );

            return response()->json(['data' => $counts]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function report(Request $request): JsonResponse
    {
        try {
            return response()->json([
                'data' => $this->reports->report($request->user(), $request->all()),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }
}
