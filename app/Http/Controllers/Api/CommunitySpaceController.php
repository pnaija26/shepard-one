<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CommunitySpace;
use App\Models\CommunitySpaceMessage;
use App\Models\User;
use App\Services\CommunitySpaceException;
use App\Services\CommunitySpaceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Story 10.6: moderated community spaces.
 */
class CommunitySpaceController extends Controller
{
    public function __construct(
        private CommunitySpaceService $spaces,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $items = $this->spaces->list($request->user());

            return response()->json([
                'data' => $items->map(fn (CommunitySpace $s) => $this->spaces->formatSpace($s))->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $item = $this->spaces->create($request->user(), $request->all());

            return response()->json(['data' => $this->spaces->formatSpace($item)], 201);
        } catch (CommunitySpaceException $exception) {
            return $this->csError($exception);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function show(Request $request, CommunitySpace $communitySpace): JsonResponse
    {
        try {
            $item = $this->spaces->show($request->user(), $communitySpace);

            return response()->json(['data' => $this->spaces->formatSpace($item)]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function addMember(Request $request, CommunitySpace $communitySpace): JsonResponse
    {
        try {
            $membership = $this->spaces->addMember($request->user(), $communitySpace, $request->all());

            return response()->json([
                'data' => [
                    'id' => $membership->id,
                    'user_id' => $membership->user_id,
                    'role' => $membership->role,
                    'status' => $membership->status,
                    'joined_at' => $membership->joined_at?->toIso8601String(),
                ],
            ], 201);
        } catch (CommunitySpaceException $exception) {
            return $this->csError($exception);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function messages(Request $request, CommunitySpace $communitySpace): JsonResponse
    {
        try {
            $limit = (int) $request->query('limit', 50);
            $items = $this->spaces->listMessages($request->user(), $communitySpace, $limit);

            return response()->json([
                'data' => $items->map(fn (CommunitySpaceMessage $m) => $this->spaces->formatMessage($m))->values(),
            ]);
        } catch (CommunitySpaceException $exception) {
            return $this->csError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function postMessage(Request $request, CommunitySpace $communitySpace): JsonResponse
    {
        try {
            $message = $this->spaces->postMessage($request->user(), $communitySpace, $request->all());

            return response()->json(['data' => $this->spaces->formatMessage($message)], 201);
        } catch (CommunitySpaceException $exception) {
            return $this->csError($exception);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function search(Request $request, CommunitySpace $communitySpace): JsonResponse
    {
        try {
            $q = (string) $request->query('q', '');
            $items = $this->spaces->search($request->user(), $communitySpace, $q);

            return response()->json([
                'data' => $items->map(fn (CommunitySpaceMessage $m) => $this->spaces->formatMessage($m))->values(),
            ]);
        } catch (CommunitySpaceException $exception) {
            return $this->csError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function pin(Request $request, CommunitySpace $communitySpace, CommunitySpaceMessage $message): JsonResponse
    {
        try {
            $item = $this->spaces->pinMessage($request->user(), $communitySpace, $message, $request->all());

            return response()->json(['data' => $this->spaces->formatMessage($item)]);
        } catch (CommunitySpaceException $exception) {
            return $this->csError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function restrict(Request $request, CommunitySpace $communitySpace, CommunitySpaceMessage $message): JsonResponse
    {
        try {
            $item = $this->spaces->restrictMessage($request->user(), $communitySpace, $message, $request->all());

            return response()->json(['data' => $this->spaces->formatMessage($item)]);
        } catch (CommunitySpaceException $exception) {
            return $this->csError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function remove(Request $request, CommunitySpace $communitySpace, CommunitySpaceMessage $message): JsonResponse
    {
        try {
            $item = $this->spaces->removeMessage($request->user(), $communitySpace, $message, $request->all());

            return response()->json(['data' => $this->spaces->formatMessage($item)]);
        } catch (CommunitySpaceException $exception) {
            return $this->csError($exception);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function report(Request $request, CommunitySpace $communitySpace, CommunitySpaceMessage $message): JsonResponse
    {
        try {
            $event = $this->spaces->reportMessage($request->user(), $communitySpace, $message, $request->all());

            return response()->json([
                'data' => [
                    'id' => $event->id,
                    'action' => $event->action,
                    'reason' => $event->reason,
                    'occurred_at' => $event->occurred_at?->toIso8601String(),
                ],
            ], 201);
        } catch (CommunitySpaceException $exception) {
            return $this->csError($exception);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function moderateParticipant(Request $request, CommunitySpace $communitySpace, User $user): JsonResponse
    {
        try {
            $membership = $this->spaces->moderateParticipant($request->user(), $communitySpace, $user, $request->all());

            return response()->json([
                'data' => [
                    'id' => $membership->id,
                    'user_id' => $membership->user_id,
                    'status' => $membership->status,
                    'moderation_reason' => $membership->moderation_reason,
                ],
            ]);
        } catch (CommunitySpaceException $exception) {
            return $this->csError($exception);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function configureIntegration(Request $request, CommunitySpace $communitySpace): JsonResponse
    {
        try {
            $integration = $this->spaces->configureIntegration($request->user(), $communitySpace, $request->all());

            return response()->json([
                'data' => [
                    'id' => $integration->id,
                    'provider' => $integration->provider,
                    'enabled' => $integration->enabled,
                    'consent_documented' => $integration->consent_documented,
                    'moderation_boundary' => $integration->moderation_boundary,
                    'configured_at' => $integration->configured_at?->toIso8601String(),
                ],
            ]);
        } catch (CommunitySpaceException $exception) {
            return $this->csError($exception);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function purgeExpired(Request $request): JsonResponse
    {
        try {
            $branchId = $request->query('branch_id');

            return response()->json([
                'data' => $this->spaces->purgeExpired(
                    $request->user(),
                    $branchId !== null ? (int) $branchId : null,
                ),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    private function csError(CommunitySpaceException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'code' => $exception->codeKey(),
            'details' => $exception->details(),
        ], $exception->httpStatus());
    }
}
