<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contribution;
use App\Models\ContributionReceipt;
use App\Models\GivingCampaign;
use App\Services\ContributionReconciliationException;
use App\Services\ContributionReconciliationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Story 11.2: reconcile contributions and issue receipts.
 */
class ContributionController extends Controller
{
    public function __construct(
        private ContributionReconciliationService $contributions,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $items = $this->contributions->list($request->user(), $request->query());

            return response()->json([
                'data' => $items->map(fn (Contribution $c) => $this->contributions->formatContribution($c))->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function show(Request $request, Contribution $contribution): JsonResponse
    {
        try {
            $item = $this->contributions->show($request->user(), $contribution);

            return response()->json(['data' => $this->contributions->formatContribution($item)]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function storeManual(Request $request): JsonResponse
    {
        try {
            $item = $this->contributions->createManual($request->user(), $request->all());

            return response()->json(['data' => $this->contributions->formatContribution($item)], 201);
        } catch (ContributionReconciliationException $exception) {
            return $this->err($exception);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function match(Request $request, Contribution $contribution): JsonResponse
    {
        try {
            $item = $this->contributions->match($request->user(), $contribution, $request->all());

            return response()->json(['data' => $this->contributions->formatContribution($item)]);
        } catch (ContributionReconciliationException $exception) {
            return $this->err($exception);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function needsResolution(Request $request, Contribution $contribution): JsonResponse
    {
        try {
            $item = $this->contributions->markNeedsResolution($request->user(), $contribution, $request->all());

            return response()->json(['data' => $this->contributions->formatContribution($item)]);
        } catch (ContributionReconciliationException $exception) {
            return $this->err($exception);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function reconcile(Request $request, Contribution $contribution): JsonResponse
    {
        try {
            $item = $this->contributions->reconcile($request->user(), $contribution, $request->all());

            return response()->json(['data' => $this->contributions->formatContribution($item)]);
        } catch (ContributionReconciliationException $exception) {
            return $this->err($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function correct(Request $request, Contribution $contribution): JsonResponse
    {
        try {
            $adjustment = $this->contributions->correct($request->user(), $contribution, $request->all());

            return response()->json([
                'data' => [
                    'id' => $adjustment->id,
                    'reference' => $adjustment->reference,
                    'adjustment_type' => $adjustment->adjustment_type,
                    'reason' => $adjustment->reason,
                    'occurred_at' => $adjustment->occurred_at?->toIso8601String(),
                ],
            ], 201);
        } catch (ContributionReconciliationException $exception) {
            return $this->err($exception);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function issueReceipt(Request $request, Contribution $contribution): JsonResponse
    {
        try {
            $receipt = $this->contributions->issueReceipt($request->user(), $contribution, $request->all());

            return response()->json(['data' => $this->contributions->formatReceipt($receipt)], 201);
        } catch (ContributionReconciliationException $exception) {
            return $this->err($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function voidReceipt(Request $request, ContributionReceipt $receipt): JsonResponse
    {
        try {
            $adjustment = $this->contributions->voidReceipt($request->user(), $receipt, $request->all());

            return response()->json([
                'data' => [
                    'id' => $adjustment->id,
                    'reference' => $adjustment->reference,
                    'adjustment_type' => $adjustment->adjustment_type,
                    'reason' => $adjustment->reason,
                ],
            ]);
        } catch (ContributionReconciliationException $exception) {
            return $this->err($exception);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function verifyReceipt(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'receipt_number' => ['required', 'string'],
            'verification_code' => ['required', 'string'],
        ]);

        $data = $this->contributions->verifyReceipt(
            $validated['receipt_number'],
            $validated['verification_code'],
        );

        if ($data === null) {
            return response()->json(['message' => 'Receipt not found.', 'code' => 'not_found'], 404);
        }

        return response()->json(['data' => $data]);
    }

    public function campaigns(Request $request): JsonResponse
    {
        try {
            $items = $this->contributions->listCampaigns($request->user());

            return response()->json([
                'data' => $items->map(fn (GivingCampaign $c) => [
                    'id' => $c->id,
                    'reference' => $c->reference,
                    'name' => $c->name,
                    'code' => $c->code,
                    'branch_id' => $c->branch_id,
                    'status' => $c->status,
                ])->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function storeCampaign(Request $request): JsonResponse
    {
        try {
            $item = $this->contributions->createCampaign($request->user(), $request->all());

            return response()->json([
                'data' => [
                    'id' => $item->id,
                    'reference' => $item->reference,
                    'name' => $item->name,
                    'code' => $item->code,
                    'branch_id' => $item->branch_id,
                    'status' => $item->status,
                ],
            ], 201);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    private function err(ContributionReconciliationException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'code' => $exception->codeKey(),
            'details' => $exception->details(),
        ], $exception->httpStatus());
    }
}
