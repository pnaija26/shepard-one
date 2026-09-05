<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use App\Services\ApiContractService;
use App\Services\ApiPlatformException;
use App\Services\ApiPlatformService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Story 15.3: API platform catalog, contract, and client management.
 */
class ApiPlatformController extends Controller
{
    public function __construct(
        private ApiPlatformService $platform,
        private ApiContractService $contract,
    ) {
    }

    public function catalog(Request $request): JsonResponse
    {
        try {
            return response()->json(['data' => $this->platform->catalog($request->user())]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function contract(Request $request): JsonResponse
    {
        try {
            $this->platform->catalog($request->user());

            return response()->json(['data' => $this->contract->contract()]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function validateContract(Request $request): JsonResponse
    {
        try {
            $this->platform->catalog($request->user());

            return response()->json(['data' => $this->contract->validateExecutableContract()]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function indexClients(Request $request): JsonResponse
    {
        try {
            $clients = $this->platform->listClients($request->user());

            return response()->json([
                'data' => $clients->map(fn (ApiClient $client) => $this->platform->formatClient($client))->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function storeClient(Request $request): JsonResponse
    {
        try {
            $created = $this->platform->createClient($request->user(), $request->all());

            return response()->json([
                'data' => array_merge(
                    $this->platform->formatClient($created['client']),
                    ['client_secret' => $created['client_secret']],
                ),
            ], 201);
        } catch (ApiPlatformException $exception) {
            return $this->platformError($exception);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function revokeClient(Request $request, ApiClient $apiClient): JsonResponse
    {
        try {
            $client = $this->platform->revokeClient($request->user(), $apiClient);

            return response()->json(['data' => $this->platform->formatClient($client)]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    private function platformError(ApiPlatformException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'code' => $exception->codeKey,
            'details' => $exception->details,
        ], $exception->getCode());
    }
}
