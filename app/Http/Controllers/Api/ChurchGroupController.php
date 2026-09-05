<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChurchGroup;
use App\Models\ChurchGroupJoinRequest;
use App\Models\ChurchGroupMembership;
use App\Services\ChurchGroupService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Story 6.1: church group organization API.
 */
class ChurchGroupController extends Controller
{
    public function __construct(
        private ChurchGroupService $groups,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $items = $this->groups->listGroups($request->user(), $request->only('status', 'group_type'));

            return response()->json([
                'data' => $items->map(fn (ChurchGroup $group) => $this->groups->formatGroup($group))->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $group = $this->groups->createGroup($request->user(), $request->all());

            return response()->json(['data' => $this->groups->formatGroup($group)], 201);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function show(Request $request, ChurchGroup $churchGroup): JsonResponse
    {
        try {
            $group = $this->groups->showGroup($request->user(), $churchGroup);

            return response()->json(['data' => $this->groups->formatGroup($group)]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function update(Request $request, ChurchGroup $churchGroup): JsonResponse
    {
        try {
            $group = $this->groups->updateGroup($request->user(), $churchGroup, $request->all());

            return response()->json(['data' => $this->groups->formatGroup($group)]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function activate(Request $request, ChurchGroup $churchGroup): JsonResponse
    {
        try {
            $group = $this->groups->activateGroup($request->user(), $churchGroup);

            return response()->json(['data' => $this->groups->formatGroup($group)]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function assignMember(Request $request, ChurchGroup $churchGroup): JsonResponse
    {
        try {
            $membership = $this->groups->assignMember($request->user(), $churchGroup, $request->all());

            return response()->json(['data' => $this->groups->formatMembership($membership)], 201);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function transferMember(Request $request, ChurchGroup $churchGroup, ChurchGroupMembership $membership): JsonResponse
    {
        try {
            $transferred = $this->groups->transferMember($request->user(), $churchGroup, $membership, $request->all());

            return response()->json(['data' => $this->groups->formatMembership($transferred)]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function removeMember(Request $request, ChurchGroup $churchGroup, ChurchGroupMembership $membership): JsonResponse
    {
        try {
            $removed = $this->groups->removeMember($request->user(), $churchGroup, $membership, $request->all());

            return response()->json(['data' => $this->groups->formatMembership($removed)]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function submitJoinRequest(Request $request, ChurchGroup $churchGroup): JsonResponse
    {
        try {
            $joinRequest = $this->groups->submitJoinRequest($request->user(), $churchGroup, $request->all());

            return response()->json(['data' => $joinRequest], 201);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function reviewJoinRequest(Request $request, ChurchGroupJoinRequest $joinRequest): JsonResponse
    {
        try {
            $result = $this->groups->reviewJoinRequest($request->user(), $joinRequest, $request->all());

            if ($result['decision'] === 'approved') {
                return response()->json([
                    'data' => [
                        'decision' => 'approved',
                        'membership' => $this->groups->formatMembership($result['membership']),
                    ],
                ]);
            }

            return response()->json([
                'data' => [
                    'decision' => 'rejected',
                    'join_request' => $result['join_request'],
                ],
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }
}
