<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExternalServiceAdapter;
use App\Services\ExternalAdapterException;
use App\Services\ExternalAdapterRuntimeService;
use App\Services\ExternalAdapterService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Story 15.5: external service adapter configuration and runtime API.
 */
class ExternalAdapterController extends Controller
{
    public function __construct(
        private ExternalAdapterService $adapters,
        private ExternalAdapterRuntimeService $runtime,
    ) {
    }

    public function catalog(Request $request): JsonResponse
    {
        try {
            return response()->json(['data' => $this->adapters->catalog($request->user())]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $items = $this->adapters->list($request->user());

            return response()->json([
                'data' => $items->map(fn (ExternalServiceAdapter $item) => $this->adapters->format($item))->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $item = $this->adapters->create($request->user(), $request->all());

            return response()->json(['data' => $this->adapters->format($item)], 201);
        } catch (ExternalAdapterException $exception) {
            return $this->adapterError($exception);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function show(Request $request, ExternalServiceAdapter $externalServiceAdapter): JsonResponse
    {
        try {
            $item = $this->adapters->show($request->user(), $externalServiceAdapter);

            return response()->json(['data' => $this->adapters->format($item)]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function update(Request $request, ExternalServiceAdapter $externalServiceAdapter): JsonResponse
    {
        try {
            $item = $this->adapters->update($request->user(), $externalServiceAdapter, $request->all());

            return response()->json(['data' => $this->adapters->format($item)]);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function test(Request $request, ExternalServiceAdapter $externalServiceAdapter): JsonResponse
    {
        try {
            return response()->json(['data' => $this->adapters->testConnection($request->user(), $externalServiceAdapter)]);
        } catch (ExternalAdapterException $exception) {
            return $this->adapterError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function activate(Request $request, ExternalServiceAdapter $externalServiceAdapter): JsonResponse
    {
        try {
            $item = $this->adapters->activate($request->user(), $externalServiceAdapter);

            return response()->json(['data' => $this->adapters->format($item)]);
        } catch (ExternalAdapterException $exception) {
            return $this->adapterError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function disable(Request $request, ExternalServiceAdapter $externalServiceAdapter): JsonResponse
    {
        try {
            $item = $this->adapters->disable(
                $request->user(),
                $externalServiceAdapter,
                (string) $request->input('drain_policy', 'drain'),
            );

            return response()->json(['data' => $this->adapters->format($item)]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function replace(Request $request, ExternalServiceAdapter $externalServiceAdapter): JsonResponse
    {
        try {
            $item = $this->adapters->replace($request->user(), $externalServiceAdapter, $request->all());

            return response()->json(['data' => $this->adapters->format($item)]);
        } catch (ExternalAdapterException $exception) {
            return $this->adapterError($exception);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function invoke(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'capability' => ['required', 'string'],
                'branch_id' => ['nullable', 'integer', 'exists:organizations,id'],
                'payload' => ['required', 'array'],
                'idempotency_key' => ['nullable', 'string', 'max:128'],
            ]);

            $result = $this->runtime->invoke(
                $request->user(),
                $validated['capability'],
                $validated['payload'],
                $validated['branch_id'] ?? null,
                $validated['idempotency_key'] ?? null,
            );

            return response()->json(['data' => $result], 201);
        } catch (ExternalAdapterException $exception) {
            return $this->adapterError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function processDue(Request $request): JsonResponse
    {
        try {
            return response()->json(['data' => $this->runtime->processDue($request->user())]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    private function adapterError(ExternalAdapterException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'code' => $exception->codeKey,
            'details' => $exception->details,
        ], $exception->getCode());
    }
}
