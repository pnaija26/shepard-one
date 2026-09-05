<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WelfareAssistanceDelivery;
use App\Models\WelfareRequest;
use App\Services\WelfareAssessmentService;
use App\Services\WelfareDeliveryService;
use App\Services\WelfareRequestException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Story 7.4: welfare assistance delivery and confirmation API.
 */
class WelfareDeliveryController extends Controller
{
    public function __construct(
        private WelfareDeliveryService $deliveries,
        private WelfareAssessmentService $assessments,
    ) {
    }

    public function store(Request $request, WelfareRequest $welfareRequest): JsonResponse
    {
        try {
            $delivery = $this->deliveries->recordDelivery($request->user(), $welfareRequest, $request->all());

            return response()->json([
                'data' => $this->deliveries->formatDelivery($delivery, $request->user()),
                'case' => $this->assessments->formatForActor($welfareRequest->fresh([
                    'deliveries.confirmation',
                    'assessments',
                    'approvalSteps',
                ]), $request->user()),
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

    public function confirm(Request $request, WelfareAssistanceDelivery $welfareAssistanceDelivery): JsonResponse
    {
        try {
            $confirmation = $this->deliveries->confirmDelivery(
                $request->user(),
                $welfareAssistanceDelivery,
                $request->all(),
            );

            $delivery = $welfareAssistanceDelivery->fresh(['confirmation']);

            return response()->json([
                'data' => [
                    'confirmation' => [
                        'id' => $confirmation->id,
                        'status' => $confirmation->status,
                        'confirmed_at' => $confirmation->confirmed_at?->toIso8601String(),
                        'waiver_reason' => $confirmation->waiver_reason,
                    ],
                    'delivery' => $this->deliveries->formatDelivery($delivery, $request->user()),
                    'case' => $this->assessments->formatForActor(
                        $delivery->request->fresh(['deliveries.confirmation']),
                        $request->user(),
                    ),
                ],
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
}
