<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ConfigurationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ConfigurationController extends Controller
{
    protected $configurationService;

    public function __construct(ConfigurationService $configurationService)
    {
        $this->configurationService = $configurationService;
    }

    /**
     * Get all configuration settings
     */
    public function index(Request $request): JsonResponse
    {
        $category = $request->query('category');
        
        if ($category) {
            $settings = $this->configurationService->getByCategory($category);
        } else {
            $settings = $this->configurationService->getAllSettings();
        }
        
        return response()->json([
            'success' => true,
            'data' => $settings
        ]);
    }

    /**
     * Get a specific configuration setting
     */
    public function show($key): JsonResponse
    {
        $setting = $this->configurationService->get($key);
        
        if ($setting === null) {
            return response()->json([
                'success' => false,
                'message' => 'Setting not found'
            ], 404);
        }
        
        return response()->json([
            'success' => true,
            'data' => [
                'key' => $key,
                'value' => $setting
            ]
        ]);
    }

    /**
     * Create or update a configuration setting
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'key' => 'required|string|unique:settings,key',
            'value' => 'required',
            'type' => 'string|in:string,integer,boolean,json',
            'category' => 'string',
            'description' => 'string|nullable',
            'is_public' => 'boolean'
        ]);

        $setting = $this->configurationService->set(
            $validated['key'],
            $validated['value'],
            $validated['type'] ?? 'string',
            $validated['category'],
            $validated['description']
        );

        return response()->json([
            'success' => true,
            'message' => 'Setting saved successfully',
            'data' => $setting
        ]);
    }

    /**
     * Update a configuration setting
     */
    public function update(Request $request, $key): JsonResponse
    {
        $validated = $request->validate([
            'value' => 'required',
            'type' => 'string|in:string,integer,boolean,json',
            'category' => 'string',
            'description' => 'string|nullable',
            'is_public' => 'boolean'
        ]);

        // Check if setting exists
        $existing = $this->configurationService->get($key);
        
        if ($existing === null) {
            return response()->json([
                'success' => false,
                'message' => 'Setting not found'
            ], 404);
        }

        $setting = $this->configurationService->set(
            $key,
            $validated['value'],
            $validated['type'] ?? 'string',
            $validated['category'],
            $validated['description']
        );

        return response()->json([
            'success' => true,
            'message' => 'Setting updated successfully',
            'data' => $setting
        ]);
    }

    /**
     * Delete a configuration setting
     */
    public function destroy($key): JsonResponse
    {
        $deleted = $this->configurationService->delete($key);
        
        if (!$deleted) {
            return response()->json([
                'success' => false,
                'message' => 'Setting not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Setting deleted successfully'
        ]);
    }

    /**
     * Get all configuration categories
     */
    public function categories(): JsonResponse
    {
        $categories = $this->configurationService->getAllCategories();
        
        return response()->json([
            'success' => true,
            'data' => $categories
        ]);
    }

    /**
     * Create a new configuration category
     */
    public function createCategory(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|unique:configuration_categories,name',
            'description' => 'string|nullable',
            'key_prefix' => 'string|nullable',
            'is_system' => 'boolean'
        ]);

        $category = $this->configurationService->createCategory(
            $validated['name'],
            $validated['description'],
            $validated['key_prefix'],
            $validated['is_system'] ?? false
        );

        return response()->json([
            'success' => true,
            'message' => 'Category created successfully',
            'data' => $category
        ]);
    }

    /**
     * Delete a configuration category
     */
    public function deleteCategory($name): JsonResponse
    {
        $deleted = $this->configurationService->deleteCategory($name);
        
        if (!$deleted) {
            return response()->json([
                'success' => false,
                'message' => 'Category not found or cannot be deleted'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Category deleted successfully'
        ]);
    }
}