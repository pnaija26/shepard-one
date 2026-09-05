<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\MemberLifecyclePendingTransition;
use App\Services\MemberLifecycleService;
use App\Services\MemberLifecycleTransitionException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Story 2.4: member lifecycle tracking API.
 */
class MemberLifecycleController extends Controller
{
    public function __construct(
        private MemberLifecycleService $lifecycle,
    ) {
    }

    public function show(Request $request, Member $member): JsonResponse
    {
        try {
            return response()->json([
                'data' => $this->lifecycle->stateFor($request->user(), $member),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function transition(Request $request, Member $member): JsonResponse
    {
        try {
            $result = $this->lifecycle->requestTransition($request->user(), $member, $request->all());

            return response()->json(['data' => $result]);
        } catch (MemberLifecycleTransitionException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'missing' => $e->missing,
                'requires_approval' => $e->requiresApproval,
            ], 422);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function pendingIndex(Request $request): JsonResponse
    {
        try {
            $pending = $this->lifecycle->listPending($request->user());

            return response()->json(['data' => $pending]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function approvePending(Request $request, MemberLifecyclePendingTransition $pending): JsonResponse
    {
        try {
            return response()->json([
                'data' => $this->lifecycle->approvePending($request->user(), $pending),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function rejectPending(Request $request, MemberLifecyclePendingTransition $pending): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            return response()->json([
                'data' => $this->lifecycle->rejectPending($request->user(), $pending, $validated['reason'] ?? null),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }
}
