<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WelfareRequest;
use App\Services\WelfareAssessmentService;
use App\Services\WelfareApprovalService;
use App\Services\WelfareRequestException;
use App\Services\WelfareRequestService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Stories 7.1–7.2: welfare request submission and assessment API.
 */
class WelfareRequestController extends Controller
{
    public function __construct(
        private WelfareRequestService $welfare,
        private WelfareAssessmentService $assessments,
        private WelfareApprovalService $approvals,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $items = $this->welfare->listRequests($request->user(), $request->only('status'));

            return response()->json([
                'data' => $items->map(fn (WelfareRequest $item) => $this->assessments->formatForActor($item, $request->user()))->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function myRequests(Request $request): JsonResponse
    {
        try {
            $items = $this->welfare->listMyRequests($request->user());

            return response()->json([
                'data' => $items->map(fn (WelfareRequest $item) => $this->assessments->formatForActor($item, $request->user()))->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $item = $this->welfare->saveDraft($request->user(), $request->all());

            return response()->json([
                'data' => $this->assessments->formatForActor($item, $request->user()),
            ], 201);
        } catch (WelfareRequestException $exception) {
            return $this->welfareError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function show(Request $request, WelfareRequest $welfareRequest): JsonResponse
    {
        try {
            $item = $this->welfare->showRequest($request->user(), $welfareRequest);

            return response()->json([
                'data' => $this->assessments->formatForActor($item, $request->user()),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function update(Request $request, WelfareRequest $welfareRequest): JsonResponse
    {
        try {
            $item = $this->welfare->saveDraft($request->user(), $request->all(), $welfareRequest);

            return response()->json([
                'data' => $this->assessments->formatForActor($item, $request->user()),
            ]);
        } catch (WelfareRequestException $exception) {
            return $this->welfareError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function submit(Request $request, WelfareRequest $welfareRequest): JsonResponse
    {
        try {
            $item = $this->welfare->submitRequest($request->user(), $welfareRequest);

            return response()->json([
                'data' => $this->assessments->formatForActor($item, $request->user()),
            ]);
        } catch (WelfareRequestException $exception) {
            return $this->welfareError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function assign(Request $request, WelfareRequest $welfareRequest): JsonResponse
    {
        try {
            $item = $this->assessments->assign($request->user(), $welfareRequest, $request->all());

            return response()->json([
                'data' => $this->assessments->formatForActor($item, $request->user()),
            ]);
        } catch (WelfareRequestException $exception) {
            return $this->welfareError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function assess(Request $request, WelfareRequest $welfareRequest): JsonResponse
    {
        try {
            $item = $this->assessments->recordAssessment($request->user(), $welfareRequest, $request->all());

            return response()->json([
                'data' => $this->assessments->formatForActor($item, $request->user()),
            ]);
        } catch (WelfareRequestException $exception) {
            return $this->welfareError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function recordCondition(Request $request, WelfareRequest $welfareRequest): JsonResponse
    {
        try {
            $item = $this->assessments->recordCondition($request->user(), $welfareRequest, $request->all());

            return response()->json([
                'data' => $this->assessments->formatForActor($item, $request->user()),
            ]);
        } catch (WelfareRequestException $exception) {
            return $this->welfareError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function attemptApproval(Request $request, WelfareRequest $welfareRequest): JsonResponse
    {
        try {
            $item = $this->assessments->attemptApproval($request->user(), $welfareRequest, $request->all());

            return response()->json([
                'data' => $this->assessments->formatForActor($item, $request->user()),
            ]);
        } catch (WelfareRequestException $exception) {
            return $this->welfareError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function decide(Request $request, WelfareRequest $welfareRequest): JsonResponse
    {
        try {
            $item = $this->approvals->decide($request->user(), $welfareRequest, $request->all());

            return response()->json([
                'data' => $this->assessments->formatForActor($item, $request->user()),
            ]);
        } catch (WelfareRequestException $exception) {
            return $this->welfareError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function reevaluateApprovals(Request $request, WelfareRequest $welfareRequest): JsonResponse
    {
        try {
            $item = $this->approvals->reevaluate($request->user(), $welfareRequest, $request->all());

            return response()->json([
                'data' => $this->assessments->formatForActor($item, $request->user()),
            ]);
        } catch (WelfareRequestException $exception) {
            return $this->welfareError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    private function welfareError(WelfareRequestException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'code' => $exception->codeKey(),
            'details' => $exception->details(),
        ], $exception->httpStatus());
    }
}
