<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OperationalTask;
use App\Services\OperationalTaskException;
use App\Services\OperationalTaskService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Story 9.1: assign and complete operational tasks.
 */
class OperationalTaskController extends Controller
{
    public function __construct(
        private OperationalTaskService $tasks,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $items = $this->tasks->list(
                $request->user(),
                $request->only(['status', 'assignee_id', 'department']),
            );

            return response()->json([
                'data' => $items->map(fn (OperationalTask $item) => $this->tasks->format($item))->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $item = $this->tasks->create($request->user(), $request->all());

            return response()->json([
                'data' => $this->tasks->format($item),
            ], 201);
        } catch (OperationalTaskException $exception) {
            return $this->taskError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function show(Request $request, OperationalTask $operationalTask): JsonResponse
    {
        try {
            $item = $this->tasks->show($request->user(), $operationalTask);

            return response()->json([
                'data' => $this->tasks->format($item),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function changeStatus(Request $request, OperationalTask $operationalTask): JsonResponse
    {
        try {
            $item = $this->tasks->changeStatus($request->user(), $operationalTask, $request->all());

            return response()->json([
                'data' => $this->tasks->format($item),
            ]);
        } catch (OperationalTaskException $exception) {
            return $this->taskError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function reassign(Request $request, OperationalTask $operationalTask): JsonResponse
    {
        try {
            $item = $this->tasks->reassign($request->user(), $operationalTask, $request->all());

            return response()->json([
                'data' => $this->tasks->format($item),
            ]);
        } catch (OperationalTaskException $exception) {
            return $this->taskError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function processOverdue(Request $request): JsonResponse
    {
        try {
            $counts = $this->tasks->processOverdue(
                $request->user(),
                $request->integer('branch_id') ?: null,
            );

            return response()->json(['data' => $counts]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    private function taskError(OperationalTaskException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'code' => $exception->codeKey(),
            'details' => $exception->details(),
        ], $exception->httpStatus());
    }
}
