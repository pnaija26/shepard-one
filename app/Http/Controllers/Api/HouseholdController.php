<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Household;
use App\Models\Member;
use App\Services\HouseholdContactOverwriteException;
use App\Services\HouseholdConflictException;
use App\Services\HouseholdService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Story 2.3: household organization API.
 */
class HouseholdController extends Controller
{
    public function __construct(
        private HouseholdService $households,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $records = $this->households->listFor($request->user());

            return response()->json([
                'data' => $records->map(
                    fn (Household $household) => $this->households->formatForViewer(
                        $this->households->findFor($request->user(), $household->id),
                        $request->user(),
                    ),
                )->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $household = $this->households->create($request->user(), $request->all());

            return response()->json([
                'data' => $this->households->formatForViewer($household, $request->user()),
            ], 201);
        } catch (HouseholdConflictException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function show(Request $request, Household $household): JsonResponse
    {
        try {
            $household = $this->households->findFor($request->user(), $household->id);

            return response()->json([
                'data' => $this->households->formatForViewer($household, $request->user()),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function update(Request $request, Household $household): JsonResponse
    {
        try {
            $household = $this->households->updateHousehold(
                $request->user(),
                $household,
                $request->all(),
                $request->boolean('confirm_overwrite'),
            );

            return response()->json([
                'data' => $this->households->formatForViewer($household, $request->user()),
            ]);
        } catch (HouseholdContactOverwriteException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'conflicts' => $e->conflicts,
                'confirm_overwrite_required' => true,
            ], 409);
        } catch (HouseholdConflictException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function addMember(Request $request, Household $household): JsonResponse
    {
        $validated = $request->validate([
            'member_id' => ['required', 'integer', 'exists:members,id'],
            'relationship_type' => ['required', 'string'],
        ]);

        try {
            $this->households->addMember(
                $request->user(),
                $household,
                (int) $validated['member_id'],
                $validated['relationship_type'],
            );

            $household = $this->households->findFor($request->user(), $household->id);

            return response()->json([
                'data' => $this->households->formatForViewer($household, $request->user()),
            ]);
        } catch (HouseholdConflictException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function changeRelationship(Request $request, Household $household, Member $member): JsonResponse
    {
        $validated = $request->validate([
            'relationship_type' => ['required', 'string'],
        ]);

        try {
            $this->households->changeRelationship(
                $request->user(),
                $household,
                $member,
                $validated['relationship_type'],
            );

            $household = $this->households->findFor($request->user(), $household->id);

            return response()->json([
                'data' => $this->households->formatForViewer($household, $request->user()),
            ]);
        } catch (HouseholdConflictException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function removeMember(Request $request, Household $household, Member $member): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->households->removeMember(
                $request->user(),
                $household,
                $member,
                $validated['reason'] ?? null,
            );

            $household = $this->households->findFor($request->user(), $household->id);

            return response()->json([
                'data' => $this->households->formatForViewer($household, $request->user()),
            ]);
        } catch (HouseholdConflictException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }
}
