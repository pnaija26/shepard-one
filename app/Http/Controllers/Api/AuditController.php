<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditImmutabilityException;
use App\Services\AuditReviewService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Story 1.8: protected audit review API.
 */
class AuditController extends Controller
{
    public function __construct(
        private AuditReviewService $auditReview,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $filters = $request->only([
                'from', 'to', 'actor_id', 'branch_id', 'action', 'module',
                'subject_type', 'subject_id', 'category',
            ]);

            $paginator = $this->auditReview->search(
                $request->user(),
                $filters,
                (int) $request->query('per_page', 25),
            );

            $this->auditReview->recordView($request->user(), $request, $filters);

            return response()->json([
                'data' => collect($paginator->items())->map(
                    fn ($event) => $this->auditReview->formatEvent($event),
                )->values(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $event = $this->auditReview->findForAuditor($request->user(), $id);

            $this->auditReview->recordView($request->user(), $request, ['event_id' => $id]);

            return response()->json([
                'data' => $this->auditReview->formatEvent($event),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function export(Request $request): JsonResponse
    {
        try {
            $filters = $request->only([
                'from', 'to', 'actor_id', 'branch_id', 'action', 'module',
                'subject_type', 'subject_id', 'category',
            ]);

            $events = $this->auditReview->export($request->user(), $filters, $request);

            return response()->json([
                'data' => collect($events)->map(
                    fn ($event) => $this->auditReview->formatEvent($event),
                )->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    /**
     * AC4: explicit guard — no supported path may mutate audit records.
     */
    public function destroy(): JsonResponse
    {
        return response()->json(['message' => 'Audit records cannot be deleted.'], 403);
    }

    public function update(): JsonResponse
    {
        return response()->json(['message' => 'Audit records cannot be modified.'], 403);
    }
}
