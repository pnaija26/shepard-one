<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MemberProfileChangeRequest;
use App\Services\MemberSelfServiceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Story 2.2: authenticated member self-service profile API.
 */
class MyProfileController extends Controller
{
    public function __construct(
        private MemberSelfServiceService $selfService,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        try {
            return response()->json([
                'data' => $this->selfService->profileFor($request->user()),
            ]);
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }
    }

    public function update(Request $request): JsonResponse
    {
        try {
            $result = $this->selfService->updateProfile($request->user(), $request->all());

            return response()->json([
                'data' => $this->selfService->profileFor($request->user()),
                'changes' => $result,
            ]);
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }
    }
}
