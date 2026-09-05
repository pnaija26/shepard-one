<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MemberDirectoryService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Story 2.7: member directory privacy settings API.
 */
class MyDirectorySettingsController extends Controller
{
    public function __construct(
        private MemberDirectoryService $directory,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        try {
            return response()->json([
                'data' => $this->directory->settingsFor($request->user()),
            ]);
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }
    }

    public function update(Request $request): JsonResponse
    {
        try {
            return response()->json([
                'data' => $this->directory->updateSettings($request->user(), $request->all()),
                'message' => 'Directory privacy settings saved.',
            ]);
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }
    }
}
