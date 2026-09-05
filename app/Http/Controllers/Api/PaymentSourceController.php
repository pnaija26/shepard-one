<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contribution;
use App\Models\PaymentSource;
use App\Services\PaymentSourceException;
use App\Services\PaymentSourceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Story 11.1: payment source configuration and signed webhook ingestion.
 */
class PaymentSourceController extends Controller
{
    public function __construct(
        private PaymentSourceService $payments,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $items = $this->payments->list($request->user());

            return response()->json([
                'data' => $items->map(fn (PaymentSource $s) => $this->payments->format($s))->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $item = $this->payments->create($request->user(), $request->all());

            return response()->json(['data' => $this->payments->format($item)], 201);
        } catch (PaymentSourceException $exception) {
            return $this->payError($exception);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function show(Request $request, PaymentSource $paymentSource): JsonResponse
    {
        try {
            $item = $this->payments->show($request->user(), $paymentSource);

            return response()->json(['data' => $this->payments->format($item)]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function update(Request $request, PaymentSource $paymentSource): JsonResponse
    {
        try {
            $item = $this->payments->update($request->user(), $paymentSource, $request->all());

            return response()->json(['data' => $this->payments->format($item)]);
        } catch (PaymentSourceException $exception) {
            return $this->payError($exception);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function test(Request $request, PaymentSource $paymentSource): JsonResponse
    {
        try {
            return response()->json(['data' => $this->payments->testConnection($request->user(), $paymentSource)]);
        } catch (PaymentSourceException $exception) {
            return $this->payError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function contributions(Request $request): JsonResponse
    {
        try {
            $sourceId = $request->query('payment_source_id');
            $items = $this->payments->listContributions(
                $request->user(),
                $sourceId !== null ? (int) $sourceId : null,
            );

            return response()->json([
                'data' => $items->map(fn (Contribution $c) => $this->payments->formatContribution($c))->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    /**
     * Authenticated simulation / manual ingest for finance admins.
     */
    public function ingest(Request $request, PaymentSource $paymentSource): JsonResponse
    {
        try {
            $raw = $request->getContent() ?: json_encode($request->all()) ?: '';
            $result = $this->payments->processWebhook(
                $paymentSource->provider,
                $request->all(),
                $raw,
                $request->header('X-Payment-Signature'),
                $request->user(),
                $paymentSource,
            );

            return response()->json(['data' => $result], $result['status'] === 'processed' ? 201 : 200);
        } catch (PaymentSourceException $exception) {
            return $this->payError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    /**
     * Public provider webhook endpoint (signature-authenticated).
     */
    public function webhook(Request $request, string $provider): JsonResponse
    {
        try {
            $raw = $request->getContent() ?: '';
            $payload = $request->all();
            if ($payload === [] && $raw !== '') {
                $decoded = json_decode($raw, true);
                $payload = is_array($decoded) ? $decoded : [];
            }

            $result = $this->payments->processWebhook(
                $provider,
                $payload,
                $raw,
                $request->header('X-Payment-Signature'),
                null,
                null,
            );

            return response()->json(['data' => $result], $result['status'] === 'processed' ? 201 : 200);
        } catch (PaymentSourceException $exception) {
            return $this->payError($exception);
        }
    }

    private function payError(PaymentSourceException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'code' => $exception->codeKey(),
            'details' => $exception->details(),
        ], $exception->httpStatus());
    }
}
