<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\ConfigurationCategory;
use Illuminate\Support\Facades\Cache;

class ConfigurationService
{
    /**
     * Get a setting value by key
     */
    public function get($key, $default = null)
    {
        // Try to get from cache first
        $cached = Cache::get("setting_{$key}");
        if ($cached !== null) {
            return $cached;
        }

        $setting = Setting::where('key', $key)->first();
        
        if ($setting) {
            $value = $setting->value;
            // Handle different data types
            if ($setting->type === 'boolean') {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            } elseif ($setting->type === 'integer') {
                $value = (int) $value;
            } elseif ($setting->type === 'json') {
                $value = json_decode($value, true);
            }
            
            // Cache the result
            Cache::put("setting_{$key}", $value, now()->addHours(1));
            
            return $value;
        }

        return $default;
    }

    /**
     * Set a setting value
     */
    public function set($key, $value, $type = 'string', $category = null, $description = null)
    {
        // Handle different data types for storage
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value);
            $type = 'json';
        } elseif (is_bool($value)) {
            $value = $value ? '1' : '0';
            $type = 'boolean';
        } elseif (is_int($value)) {
            $value = (string) $value;
            $type = 'integer';
        }

        // Create or update the setting
        $setting = Setting::updateOrCreate(
            ['key' => $key],
            [
                'value' => $value,
                'type' => $type,
                'category' => $category,
                'description' => $description
            ]
        );

        // Clear cache for this setting
        Cache::forget("setting_{$key}");

        return $setting;
    }

    /**
     * Delete a setting
     */
    public function delete($key)
    {
        $setting = Setting::where('key', $key)->first();
        
        if ($setting) {
            $setting->delete();
            Cache::forget("setting_{$key}");
            return true;
        }
        
        return false;
    }

    /**
     * Get all settings by category
     */
    public function getByCategory($category)
    {
        return Setting::where('category', $category)->get();
    }

    /**
     * Get all settings with their categories
     */
    public function getAllSettings()
    {
        return Setting::with('category')->get();
    }

    /**
     * Create a configuration category
     */
    public function createCategory($name, $description = null, $keyPrefix = null, $isSystem = false)
    {
        return ConfigurationCategory::create([
            'name' => $name,
            'description' => $description,
            'key_prefix' => $keyPrefix,
            'is_system' => $isSystem
        ]);
    }

    /**
     * Get all configuration categories
     */
    public function getAllCategories()
    {
        return ConfigurationCategory::all();
    }

    /**
     * Get a category by name
     */
    public function getCategory($name)
    {
        return ConfigurationCategory::where('name', $name)->first();
    }

    /**
     * Delete a configuration category (only non-system categories)
     */
    public function deleteCategory($name)
    {
        $category = ConfigurationCategory::where('name', $name)->first();
        
        if ($category && !$category->is_system) {
            // First delete all settings in this category
            Setting::where('category', $name)->delete();
            
            // Then delete the category itself
            $category->delete();
            return true;
        }
        
        return false;
    }

    /**
     * Get public settings (those accessible to users)
     */
    public function getPublicSettings()
    {
        return Setting::where('is_public', true)->get();
    }
}