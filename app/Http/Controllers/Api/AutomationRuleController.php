<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AutomationRule;
use App\Services\AutomationRuleException;
use App\Services\AutomationRuleService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Story 9.4: configure, simulate, publish, and evaluate automation rules.
 */
class AutomationRuleController extends Controller
{
    public function __construct(
        private AutomationRuleService $rules,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $items = $this->rules->list($request->user());

            return response()->json([
                'data' => $items->map(fn (AutomationRule $item) => $this->rules->format($item))->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $item = $this->rules->create($request->user(), $request->all());

            return response()->json([
                'data' => $this->rules->format($item),
            ], 201);
        } catch (AutomationRuleException $exception) {
            return $this->ruleError($exception);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function show(Request $request, AutomationRule $automationRule): JsonResponse
    {
        try {
            $item = $this->rules->show($request->user(), $automationRule);

            return response()->json([
                'data' => $this->rules->format($item),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function updateDraft(Request $request, AutomationRule $automationRule): JsonResponse
    {
        try {
            $item = $this->rules->updateDraft($request->user(), $automationRule, $request->all());

            return response()->json([
                'data' => $this->rules->format($item),
            ]);
        } catch (AutomationRuleException $exception) {
            return $this->ruleError($exception);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function validateDefinition(Request $request, AutomationRule $automationRule): JsonResponse
    {
        try {
            return response()->json([
                'data' => $this->rules->validate($request->user(), $automationRule),
            ]);
        } catch (AutomationRuleException $exception) {
            return $this->ruleError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function simulate(Request $request, AutomationRule $automationRule): JsonResponse
    {
        try {
            return response()->json([
                'data' => $this->rules->simulate($request->user(), $automationRule, $request->all()),
            ]);
        } catch (AutomationRuleException $exception) {
            return $this->ruleError($exception);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function publish(Request $request, AutomationRule $automationRule): JsonResponse
    {
        try {
            $item = $this->rules->publish($request->user(), $automationRule);

            return response()->json([
                'data' => $this->rules->format($item),
            ]);
        } catch (AutomationRuleException $exception) {
            return $this->ruleError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function setEnabled(Request $request, AutomationRule $automationRule): JsonResponse
    {
        try {
            $validated = $request->validate([
                'enabled' => ['required', 'boolean'],
            ]);
            $item = $this->rules->setEnabled($request->user(), $automationRule, (bool) $validated['enabled']);

            return response()->json([
                'data' => $this->rules->format($item),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function evaluate(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'event_type' => ['required', 'string'],
                'payload' => ['required', 'array'],
            ]);

            return response()->json([
                'data' => $this->rules->evaluateEvent(
                    $request->user(),
                    $validated['event_type'],
                    $validated['payload'],
                ),
            ]);
        } catch (AutomationRuleException $exception) {
            return $this->ruleError($exception);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function processRetries(Request $request): JsonResponse
    {
        try {
            $branchId = $request->query('branch_id');

            return response()->json([
                'data' => $this->rules->processRetries(
                    $request->user(),
                    $branchId !== null ? (int) $branchId : null,
                ),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    private function ruleError(AutomationRuleException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'code' => $exception->codeKey(),
            'details' => $exception->details(),
        ], $exception->httpStatus());
    }
}
