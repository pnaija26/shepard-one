<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\MemberDuplicateFlag;
use App\Services\MemberDuplicateService;
use App\Services\MemberMergeConflictException;
use App\Services\MemberService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Story 2.5: duplicate review and merge API.
 */
class MemberDuplicateController extends Controller
{
    public function __construct(
        private MemberDuplicateService $duplicates,
        private MemberService $members,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $status = $request->string('status', MemberDuplicateFlag::STATUS_PENDING)->toString();
            $flags = $this->duplicates->listFlags($request->user(), $status);

            return response()->json([
                'data' => $flags->map(function (MemberDuplicateFlag $flag) {
                    return [
                        'id' => $flag->id,
                        'confidence' => $flag->confidence,
                        'match_reason' => $flag->match_reason,
                        'match_signals' => $flag->match_signals,
                        'source' => $flag->source,
                        'status' => $flag->status,
                        'member_a' => $this->summary($flag->memberA),
                        'member_b' => $this->summary($flag->memberB),
                        'created_at' => $flag->created_at?->toIso8601String(),
                    ];
                })->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function show(Request $request, MemberDuplicateFlag $flag): JsonResponse
    {
        try {
            return response()->json([
                'data' => $this->duplicates->compare($request->user(), $flag),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function dismiss(Request $request, MemberDuplicateFlag $flag): JsonResponse
    {
        try {
            $updated = $this->duplicates->dismissFlag($request->user(), $flag);

            return response()->json([
                'data' => ['id' => $updated->id, 'status' => $updated->status],
                'message' => 'Duplicate flag dismissed.',
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function merge(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'survivor_id' => ['required', 'integer', 'exists:members,id'],
            'merged_member_id' => ['required', 'integer', 'exists:members,id', 'different:survivor_id'],
            'field_resolutions' => ['required', 'array'],
            'field_resolutions.*' => ['required', 'string', 'in:survivor,merged'],
            'flag_id' => ['nullable', 'integer', 'exists:member_duplicate_flags,id'],
        ]);

        try {
            $member = $this->duplicates->merge(
                $request->user(),
                (int) $validated['survivor_id'],
                (int) $validated['merged_member_id'],
                $validated['field_resolutions'],
                isset($validated['flag_id']) ? (int) $validated['flag_id'] : null,
            );

            return response()->json([
                'data' => $this->members->formatForViewer($member, $request->user()),
                'message' => 'Members merged successfully.',
            ]);
        } catch (MemberMergeConflictException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'conflicts' => $e->conflicts,
            ], 422);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function scan(Request $request, Member $member): JsonResponse
    {
        try {
            $this->members->findFor($request->user(), $member->id);
            $flags = $this->duplicates->scanAndFlagMember($member, 'manual_scan');

            return response()->json([
                'data' => collect($flags)->map(fn (MemberDuplicateFlag $flag) => [
                    'id' => $flag->id,
                    'member_a_id' => $flag->member_a_id,
                    'member_b_id' => $flag->member_b_id,
                    'confidence' => $flag->confidence,
                    'match_reason' => $flag->match_reason,
                ])->values(),
                'message' => count($flags) > 0
                    ? 'Potential duplicates flagged for review.'
                    : 'No new duplicate flags found.',
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    private function summary(?Member $member): ?array
    {
        if ($member === null) {
            return null;
        }

        return [
            'id' => $member->id,
            'membership_id' => $member->membership_id,
            'full_name' => $member->fullName(),
            'email' => $member->email,
            'phone' => $member->phone,
            'branch' => $member->branch ? ['id' => $member->branch->id, 'name' => $member->branch->name] : null,
        ];
    }
}
