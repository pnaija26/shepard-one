<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\ConfigurationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Story 1.7: governed platform configuration API.
 */
class ConfigurationController extends Controller
{
    public function __construct(
        private ConfigurationService $configuration,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $settings = $this->configuration->listFor(
            $request->user(),
            $request->query('category'),
        );

        return response()->json(['data' => $settings]);
    }

    public function show(Request $request, string $key): JsonResponse
    {
        $settings = $this->configuration->listFor($request->user());
        $setting = $settings->firstWhere('key', $key);

        if ($setting === null) {
            return response()->json(['message' => 'Setting not found.'], 404);
        }

        return response()->json(['data' => $setting]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'key' => 'required|string|max:191|unique:settings,key',
            'value' => 'required',
            'type' => 'string|in:string,integer,boolean,json',
            'category' => 'nullable|string|max:64',
            'description' => 'nullable|string',
            'is_public' => 'boolean',
            'is_locked' => 'boolean',
            'branch_id' => 'nullable|integer|exists:organizations,id',
        ]);

        $setting = $this->configuration->create($request->user(), $validated);

        return response()->json([
            'data' => $this->configuration->formatSetting($setting),
        ], 201);
    }

    public function update(Request $request, string $key): JsonResponse
    {
        $validated = $request->validate([
            'value' => 'required',
            'type' => 'sometimes|string|in:string,integer,boolean,json',
            'description' => 'nullable|string',
            'publish' => 'boolean',
        ]);

        if ($request->boolean('publish')) {
            $this->configuration->stage($request->user(), $key, $validated['value'], $validated);
            $setting = $this->configuration->publish($request->user(), $key);
        } else {
            $setting = $this->configuration->stage($request->user(), $key, $validated['value'], $validated);
        }

        return response()->json([
            'data' => $this->configuration->formatSetting($setting),
        ]);
    }

    public function publish(Request $request, string $key): JsonResponse
    {
        $setting = $this->configuration->publish($request->user(), $key);

        return response()->json([
            'data' => $this->configuration->formatSetting($setting),
            'message' => 'Configuration published successfully.',
        ]);
    }

    public function destroy(Request $request, string $key): JsonResponse
    {
        $validated = $request->validate([
            'archive' => 'boolean',
        ]);

        $this->configuration->delete(
            $request->user(),
            $key,
            $request->boolean('archive'),
        );

        return response()->json(['message' => 'Setting removed successfully.']);
    }

    public function categories(Request $request): JsonResponse
    {
        if (! app(\App\Services\AuthorizationService::class)->allows($request->user(), 'config.read')) {
            abort(403);
        }

        return response()->json([
            'data' => $this->configuration->getAllCategories(),
        ]);
    }

    public function createCategory(Request $request): JsonResponse
    {
        if (! app(\App\Services\AuthorizationService::class)->allows($request->user(), 'config.manage')) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:64|unique:configuration_categories,name',
            'description' => 'nullable|string',
            'key_prefix' => 'nullable|string|max:64',
            'is_system' => 'boolean',
        ]);

        $category = $this->configuration->createCategory(
            $validated['name'],
            $validated['description'] ?? null,
            $validated['key_prefix'] ?? null,
            $validated['is_system'] ?? false,
        );

        return response()->json(['data' => $category], 201);
    }

    public function deleteCategory(Request $request, string $name): JsonResponse
    {
        if (! app(\App\Services\AuthorizationService::class)->allows($request->user(), 'config.manage')) {
            abort(403);
        }

        if (! $this->configuration->deleteCategory($name)) {
            return response()->json(['message' => 'Category not found or cannot be deleted.'], 404);
        }

        return response()->json(['message' => 'Category deleted successfully.']);
    }
}
