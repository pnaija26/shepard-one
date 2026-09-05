<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use App\Services\WebhookDeliveryService;
use App\Services\WebhookException;
use App\Services\WebhookSubscriptionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Story 15.4: outbound webhook subscriptions and delivery management.
 */
class OutboundWebhookController extends Controller
{
    public function __construct(
        private WebhookSubscriptionService $subscriptions,
        private WebhookDeliveryService $deliveries,
    ) {
    }

    public function catalog(Request $request): JsonResponse
    {
        try {
            return response()->json(['data' => $this->subscriptions->catalog($request->user())]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $items = $this->subscriptions->list($request->user());

            return response()->json([
                'data' => $items->map(fn (WebhookSubscription $item) => $this->subscriptions->format($item))->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $created = $this->subscriptions->create($request->user(), $request->all());

            return response()->json([
                'data' => array_merge(
                    $this->subscriptions->format($created['subscription']),
                    ['signing_secret' => $created['signing_secret']],
                ),
            ], 201);
        } catch (WebhookException $exception) {
            return $this->webhookError($exception);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function show(Request $request, WebhookSubscription $webhookSubscription): JsonResponse
    {
        try {
            $item = $this->subscriptions->show($request->user(), $webhookSubscription);

            return response()->json([
                'data' => array_merge($this->subscriptions->format($item), [
                    'deliveries' => $item->deliveries->map(
                        fn (WebhookDelivery $delivery) => $this->deliveries->formatDelivery($delivery),
                    )->values(),
                ]),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function verify(Request $request, WebhookSubscription $webhookSubscription): JsonResponse
    {
        try {
            $item = $this->subscriptions->verify($request->user(), $webhookSubscription);

            return response()->json(['data' => $this->subscriptions->format($item)]);
        } catch (WebhookException $exception) {
            return $this->webhookError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function revoke(Request $request, WebhookSubscription $webhookSubscription): JsonResponse
    {
        try {
            $item = $this->subscriptions->revoke($request->user(), $webhookSubscription);

            return response()->json(['data' => $this->subscriptions->format($item)]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function rotateSecret(Request $request, WebhookSubscription $webhookSubscription): JsonResponse
    {
        try {
            $rotated = $this->subscriptions->rotateSecret($request->user(), $webhookSubscription);

            return response()->json([
                'data' => array_merge(
                    $this->subscriptions->format($rotated['subscription']),
                    ['signing_secret' => $rotated['signing_secret']],
                ),
            ]);
        } catch (WebhookException $exception) {
            return $this->webhookError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function dispatchEvent(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'event_type' => ['required', 'string'],
                'branch_id' => ['nullable', 'integer', 'exists:organizations,id'],
                'payload' => ['required', 'array'],
                'idempotency_key' => ['nullable', 'string', 'max:128'],
            ]);

            $result = $this->deliveries->dispatchEvent(
                $request->user(),
                $validated['event_type'],
                $validated['payload'],
                $validated['branch_id'] ?? null,
                $validated['idempotency_key'] ?? null,
            );

            return response()->json(['data' => $result], 201);
        } catch (WebhookException $exception) {
            return $this->webhookError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function processDue(Request $request): JsonResponse
    {
        try {
            return response()->json(['data' => $this->deliveries->processDue($request->user())]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    private function webhookError(WebhookException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'code' => $exception->codeKey,
            'details' => $exception->details,
        ], $exception->getCode());
    }
}
