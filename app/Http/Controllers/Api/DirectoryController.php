<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Services\MemberDirectoryService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Story 2.7: privacy-filtered church directory API.
 */
class DirectoryController extends Controller
{
    public function __construct(
        private MemberDirectoryService $directory,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $results = $this->directory->search(
                $request->user(),
                $request->only(['search', 'branch_id']),
            );

            return response()->json(['data' => $results]);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    public function show(Request $request, Member $member): JsonResponse
    {
        try {
            return response()->json([
                'data' => $this->directory->show($request->user(), $member),
            ]);
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }
    }

    public function export(Request $request): StreamedResponse|JsonResponse
    {
        try {
            $export = $this->directory->export(
                $request->user(),
                $request->only(['search', 'branch_id']),
            );

            return response()->streamDownload(
                static function () use ($export): void {
                    echo $export['content'];
                },
                $export['filename'],
                ['Content-Type' => 'text/csv'],
            );
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }
}
