<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TrainingAssessmentResult;
use App\Models\TrainingCertificate;
use App\Models\TrainingEnrolment;
use App\Models\TrainingSessionAttendance;
use App\Services\TrainingProgressException;
use App\Services\TrainingProgressService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Story 6.4: training progress and certification API.
 */
class TrainingProgressController extends Controller
{
    public function __construct(
        private TrainingProgressService $progress,
    ) {
    }

    public function show(Request $request, TrainingEnrolment $trainingEnrolment): JsonResponse
    {
        try {
            return response()->json([
                'data' => $this->progress->getProgress($request->user(), $trainingEnrolment),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function recordAttendance(Request $request, TrainingEnrolment $trainingEnrolment): JsonResponse
    {
        try {
            return response()->json([
                'data' => $this->progress->recordAttendance($request->user(), $trainingEnrolment, $request->all()),
            ]);
        } catch (TrainingProgressException $exception) {
            return $this->progressError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function correctAttendance(Request $request, TrainingSessionAttendance $trainingSessionAttendance): JsonResponse
    {
        try {
            $record = $this->progress->correctAttendance($request->user(), $trainingSessionAttendance, $request->all());

            return response()->json(['data' => $record]);
        } catch (TrainingProgressException $exception) {
            return $this->progressError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function recordAssessments(Request $request, TrainingEnrolment $trainingEnrolment): JsonResponse
    {
        try {
            return response()->json([
                'data' => $this->progress->recordAssessments($request->user(), $trainingEnrolment, $request->all()),
            ]);
        } catch (TrainingProgressException $exception) {
            return $this->progressError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function correctAssessment(Request $request, TrainingAssessmentResult $trainingAssessmentResult): JsonResponse
    {
        try {
            $result = $this->progress->correctAssessment($request->user(), $trainingAssessmentResult, $request->all());

            return response()->json(['data' => $result]);
        } catch (TrainingProgressException $exception) {
            return $this->progressError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function confirmCompletion(Request $request, TrainingEnrolment $trainingEnrolment): JsonResponse
    {
        try {
            return response()->json([
                'data' => $this->progress->confirmCompletion($request->user(), $trainingEnrolment),
            ]);
        } catch (TrainingProgressException $exception) {
            return $this->progressError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function revokeCertificate(Request $request, TrainingCertificate $trainingCertificate): JsonResponse
    {
        try {
            $certificate = $this->progress->revokeCertificate($request->user(), $trainingCertificate, $request->all());

            return response()->json([
                'data' => $this->progress->formatCertificate($certificate),
            ]);
        } catch (TrainingProgressException $exception) {
            return $this->progressError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function verifyCertificate(Request $request, string $reference): JsonResponse
    {
        try {
            return response()->json([
                'data' => $this->progress->verifyCertificate($request->user(), $reference),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    private function progressError(TrainingProgressException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'code' => $exception->codeKey(),
            'details' => $exception->details(),
        ], $exception->httpStatus());
    }
}
