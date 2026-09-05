<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WelfareApprovalConfig;
use App\Services\WelfareApprovalService;
use App\Services\WelfareRequestException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Story 7.3: welfare approval threshold configuration API.
 */
class WelfareApprovalController extends Controller
{
    public function __construct(
        private WelfareApprovalService $approvals,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $authz = app(\App\Services\AuthorizationService::class);
            if (! $authz->allows($request->user(), 'welfare.approvals.configure')
                && ! $authz->allows($request->user(), 'welfare.approvals.decide')
                && ! $authz->allows($request->user(), 'welfare.requests.read')) {
                throw new AuthorizationException('Forbidden.');
            }

            $this->approvals->ensureDefaultPublishedConfig($request->user());

            $items = WelfareApprovalConfig::query()
                ->with('versions')
                ->orderByDesc('id')
                ->limit(50)
                ->get();

            return response()->json([
                'data' => $items->map(fn (WelfareApprovalConfig $config) => $this->approvals->formatConfig($config))->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function publish(Request $request): JsonResponse
    {
        try {
            $configId = $request->input('config_id');
            $existing = $configId
                ? WelfareApprovalConfig::query()->findOrFail((int) $configId)
                : null;

            $config = $this->approvals->publishConfig($request->user(), $request->all(), $existing);

            return response()->json([
                'data' => $this->approvals->formatConfig($config),
            ], 201);
        } catch (WelfareRequestException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => $exception->codeKey(),
                'details' => $exception->details(),
            ], $exception->httpStatus());
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }
}
