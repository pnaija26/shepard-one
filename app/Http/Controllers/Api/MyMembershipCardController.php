<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MembershipCardIneligibleException;
use App\Services\MembershipCardService;
use App\Services\MembershipCardTokenException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Story 2.6: member digital membership card API.
 */
class MyMembershipCardController extends Controller
{
    public function __construct(
        private MembershipCardService $cards,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        try {
            return response()->json([
                'data' => $this->cards->cardForMember($request->user()),
            ]);
        } catch (MembershipCardIneligibleException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'reasons' => $e->reasons,
                'eligible' => false,
            ], 422);
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }
    }
}
