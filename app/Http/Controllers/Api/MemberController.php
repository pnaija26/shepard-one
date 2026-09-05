<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Services\MemberDuplicateException;
use App\Services\MemberService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Story 2.1: member profile registration and maintenance API.
 */
class MemberController extends Controller
{
    public function __construct(
        private MemberService $members,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $records = $this->members->listFor($request->user(), $request->only(['status', 'search']));

            return response()->json([
                'data' => $records->map(
                    fn (Member $member) => $this->members->formatForViewer($member, $request->user()),
                )->values(),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $member = $this->members->register(
                $request->user(),
                $request->all(),
                $request->boolean('force'),
            );

            return response()->json([
                'data' => $this->members->formatForViewer($member, $request->user()),
            ], 201);
        } catch (MemberDuplicateException $e) {
            return response()->json(
                $this->members->formatDuplicateResponse($e->matches, $e->preservedInput),
                422,
            );
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function show(Request $request, Member $member): JsonResponse
    {
        try {
            $member = $this->members->findFor($request->user(), $member->id);

            return response()->json([
                'data' => $this->members->formatForViewer($member, $request->user()),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function update(Request $request, Member $member): JsonResponse
    {
        try {
            $member = $this->members->update($request->user(), $member, $request->all());

            return response()->json([
                'data' => $this->members->formatForViewer($member, $request->user()),
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function archive(Request $request, Member $member): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $member = $this->members->archive($request->user(), $member, $validated['reason'] ?? null);

            return response()->json([
                'data' => $this->members->formatForViewer($member, $request->user()),
                'message' => 'Member archived successfully.',
            ]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }
}
