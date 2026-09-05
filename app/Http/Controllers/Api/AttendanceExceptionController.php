<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceException;
use App\Models\AttendanceExceptionRule;
use App\Services\AttendanceExceptionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Story 3.3: attendance exception rules and monitoring API.
 */
class AttendanceExceptionController extends Controller
{
    public function __construct(
        private AttendanceExceptionService $exceptions,
    ) {
    }

    public function rules(Request $request): JsonResponse
    {
        try {
            $rules = $this->exceptions->listRules($request->user());

            return response()->json([
                'data' => $rules->map(fn (AttendanceExceptionRule $rule) => $this->exceptions->formatRule($rule))->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function storeRule(Request $request): JsonResponse
    {
        try {
            $rule = $this->exceptions->createRule($request->user(), $request->all());

            return response()->json(['data' => $this->exceptions->formatRule($rule)], 201);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function publishRule(Request $request, AttendanceExceptionRule $rule): JsonResponse
    {
        $validated = $request->validate([
            'parameters' => ['nullable', 'array'],
            'exclusions' => ['nullable', 'array'],
            'correction_policy' => ['nullable', 'string'],
        ]);

        try {
            $version = $this->exceptions->publishRule(
                $request->user(),
                $rule,
                $validated['parameters'] ?? null,
                $validated['exclusions'] ?? null,
                $validated['correction_policy'] ?? null,
            );

            return response()->json([
                'data' => [
                    'rule' => $this->exceptions->formatRule($rule->fresh(['branch:id,name'])),
                    'version' => $version->version,
                ],
                'message' => 'Rule published successfully.',
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $exceptions = $this->exceptions->listExceptions($request->user(), $request->only(['status']));

            return response()->json([
                'data' => $exceptions->map(fn (AttendanceException $exception) => $this->exceptions->formatException($exception))->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function show(Request $request, AttendanceException $exception): JsonResponse
    {
        try {
            $exception = $this->exceptions->showException($request->user(), $exception);

            return response()->json(['data' => $this->exceptions->formatException($exception)]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }
}
