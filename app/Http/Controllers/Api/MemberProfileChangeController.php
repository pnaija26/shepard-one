<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MemberProfileChangeRequest;
use App\Services\MemberSelfServiceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Story 2.2: officer review of pending member profile changes.
 */
class MemberProfileChangeController extends Controller
{
    public function __construct(
        private MemberSelfServiceService $selfService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $requests = $this->selfService->listPendingReviews(
                $request->user(),
                $request->query('branch_id') ? (int) $request->query('branch_id') : null,
            );

            return response()->json([
                'data' => $requests->map(
                    fn (MemberProfileChangeRequest $row) => $this->selfService->formatChangeRequest($row),
                )->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function approve(Request $request, MemberProfileChangeRequest $changeRequest): JsonResponse
    {
        try {
            $updated = $this->selfService->approveChange($request->user(), $changeRequest);

            return response()->json([
                'data' => $this->selfService->formatChangeRequest($updated),
                'message' => 'Profile change approved.',
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function reject(Request $request, MemberProfileChangeRequest $changeRequest): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $updated = $this->selfService->rejectChange(
                $request->user(),
                $changeRequest,
                $validated['reason'] ?? null,
            );

            return response()->json([
                'data' => $this->selfService->formatChangeRequest($updated),
                'message' => 'Profile change rejected.',
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }
}
