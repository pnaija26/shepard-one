<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Services\WorkflowException;
use App\Services\WorkflowExecutionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Story 9.3: execute workflow instances, actions, and deadline processing.
 */
class WorkflowInstanceController extends Controller
{
    public function __construct(
        private WorkflowExecutionService $execution,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $items = $this->execution->listInstances(
                $request->user(),
                $request->only(['status', 'workflow_id', 'assignee_id', 'mine']),
            );

            return response()->json([
                'data' => $items->map(fn (WorkflowInstance $item) => $this->execution->formatInstance($item))->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function show(Request $request, WorkflowInstance $workflowInstance): JsonResponse
    {
        try {
            $item = $this->execution->showInstance($request->user(), $workflowInstance);

            return response()->json([
                'data' => $this->execution->formatInstance($item),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function start(Request $request, Workflow $workflow): JsonResponse
    {
        try {
            $item = $this->execution->start($request->user(), $workflow, $request->all());

            return response()->json([
                'data' => $this->execution->formatInstance($item),
            ], 201);
        } catch (WorkflowException $exception) {
            return $this->execError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function act(Request $request, WorkflowInstance $workflowInstance): JsonResponse
    {
        try {
            $item = $this->execution->act($request->user(), $workflowInstance, $request->all());

            return response()->json([
                'data' => $this->execution->formatInstance($item),
            ]);
        } catch (WorkflowException $exception) {
            return $this->execError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function processDeadlines(Request $request): JsonResponse
    {
        try {
            $counts = $this->execution->processDeadlines(
                $request->user(),
                $request->integer('branch_id') ?: null,
            );

            return response()->json(['data' => $counts]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    private function execError(WorkflowException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'code' => $exception->codeKey(),
            'details' => $exception->details(),
        ], $exception->httpStatus());
    }
}
