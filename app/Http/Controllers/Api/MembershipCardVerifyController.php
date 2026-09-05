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
 * Story 2.6: authorized membership card QR verification API.
 */
class MembershipCardVerifyController extends Controller
{
    public function __construct(
        private MembershipCardService $cards,
    ) {
    }

    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:2048'],
            'purpose' => ['required', 'string', 'max:64'],
        ]);

        try {
            return response()->json([
                'data' => $this->cards->verify(
                    $request->user(),
                    $validated['token'],
                    $validated['purpose'],
                ),
            ]);
        } catch (MembershipCardTokenException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'reason' => $e->reason,
                'verified' => false,
            ], 422);
        } catch (MembershipCardIneligibleException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'reasons' => $e->reasons,
                'verified' => false,
            ], 422);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function purposes(Request $request): JsonResponse
    {
        if (! app(\App\Services\AuthorizationService::class)->allows($request->user(), 'membership_card.scan')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $purposes = collect(config('membership_card.purposes', []))
            ->map(fn (array $config, string $key) => [
                'key' => $key,
                'label' => $config['label'],
            ])
            ->values();

        return response()->json(['data' => $purposes]);
    }
}
