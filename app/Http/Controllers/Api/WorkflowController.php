<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Workflow;
use App\Services\WorkflowException;
use App\Services\WorkflowService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Story 9.2: design and publish reusable workflows.
 */
class WorkflowController extends Controller
{
    public function __construct(
        private WorkflowService $workflows,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $items = $this->workflows->list($request->user());

            return response()->json([
                'data' => $items->map(fn (Workflow $item) => $this->workflows->format($item))->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $item = $this->workflows->create($request->user(), $request->all());

            return response()->json([
                'data' => $this->workflows->format($item),
            ], 201);
        } catch (WorkflowException $exception) {
            return $this->workflowError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function show(Request $request, Workflow $workflow): JsonResponse
    {
        try {
            $item = $this->workflows->show($request->user(), $workflow);

            return response()->json([
                'data' => $this->workflows->format($item),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function updateDraft(Request $request, Workflow $workflow): JsonResponse
    {
        try {
            $item = $this->workflows->updateDraft($request->user(), $workflow, $request->all());

            return response()->json([
                'data' => $this->workflows->format($item),
            ]);
        } catch (WorkflowException $exception) {
            return $this->workflowError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function visualize(Request $request, Workflow $workflow): JsonResponse
    {
        try {
            return response()->json([
                'data' => $this->workflows->visualize($request->user(), $workflow),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function validateDefinition(Request $request, Workflow $workflow): JsonResponse
    {
        try {
            return response()->json([
                'data' => $this->workflows->validate($request->user(), $workflow),
            ]);
        } catch (WorkflowException $exception) {
            return $this->workflowError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function test(Request $request, Workflow $workflow): JsonResponse
    {
        try {
            return response()->json([
                'data' => $this->workflows->test($request->user(), $workflow, $request->all()),
            ]);
        } catch (WorkflowException $exception) {
            return $this->workflowError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function publish(Request $request, Workflow $workflow): JsonResponse
    {
        try {
            $item = $this->workflows->publish($request->user(), $workflow, $request->all());

            return response()->json([
                'data' => $this->workflows->format($item),
            ]);
        } catch (WorkflowException $exception) {
            return $this->workflowError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    private function workflowError(WorkflowException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'code' => $exception->codeKey(),
            'details' => $exception->details(),
        ], $exception->httpStatus());
    }
}
