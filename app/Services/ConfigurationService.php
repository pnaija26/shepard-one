<?php

namespace App\Services;

use App\Models\ConfigurationCategory;
use App\Models\Setting;
use App\Models\SettingReference;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * Story 1.7: governed platform settings with scope, locks, drafts, and references.
 */
class ConfigurationService
{
    public const CACHE_PREFIX = 'setting:';

    public function __construct(
        private AuthorizationService $authorization,
    ) {
    }

    public function listFor(User $actor, ?string $category = null): Collection
    {
        $this->assertCan($actor, 'config.read');

        $query = Setting::query()->active()->orderBy('key');

        if ($category !== null) {
            $query->where('category', $category);
        }

        if (! $actor->isChurchWide()) {
            $query->where(function ($q) use ($actor) {
                $q->whereNull('branch_id')
                    ->orWhere('branch_id', $actor->branch_id);
            });
        }

        return $query->get()->map(fn (Setting $s) => $this->formatSetting($s));
    }

    public function getActive(string $key, mixed $default = null): mixed
    {
        $cached = Cache::get(self::CACHE_PREFIX . $key);
        if ($cached !== null) {
            return $cached;
        }

        $setting = Setting::where('key', $key)->where('is_archived', false)->first();
        if (! $setting) {
            return $default;
        }

        $value = $this->castFromStorage($setting->value, $setting->type);
        Cache::put(self::CACHE_PREFIX . $key, $value, now()->addHour());

        return $value;
    }

    /**
     * Stage a draft change (AC3: active value unchanged until publish).
     */
    public function stage(User $actor, string $key, mixed $value, array $meta = []): Setting
    {
        $setting = $this->findEditable($actor, $key);
        $type = $meta['type'] ?? $setting->type;

        $this->validateValue($value, $type, $key);

        $setting->update([
            'draft_value' => $this->serializeValue($value, $type),
            'type' => $type,
            'description' => $meta['description'] ?? $setting->description,
        ]);

        return $setting->fresh();
    }

    /**
     * Publish a staged draft (AC3: reject invalid, keep last valid active value).
     */
    public function publish(User $actor, string $key): Setting
    {
        $setting = $this->findEditable($actor, $key);

        if ($setting->draft_value === null) {
            throw ValidationException::withMessages([
                'draft' => ['No pending changes to publish for this setting.'],
            ]);
        }

        $this->validateValue(
            $this->castFromStorage($setting->draft_value, $setting->type),
            $setting->type,
            $key,
        );

        $setting->update([
            'value' => $setting->draft_value,
            'draft_value' => null,
        ]);

        Cache::forget(self::CACHE_PREFIX . $key);

        return $setting->fresh();
    }

    public function create(User $actor, array $attrs): Setting
    {
        $this->assertCan($actor, 'config.manage');

        $key = (string) ($attrs['key'] ?? '');
        $type = (string) ($attrs['type'] ?? 'string');
        $value = $attrs['value'] ?? null;

        $this->validateValue($value, $type, $key);

        if (Setting::where('key', $key)->exists()) {
            throw ValidationException::withMessages(['key' => ['A setting with this key already exists.']]);
        }

        $branchId = $attrs['branch_id'] ?? null;
        if ($branchId !== null && ! $actor->isChurchWide()) {
            throw new ConfigurationLockedException('Branch administrators cannot create church-wide settings.');
        }

        return Setting::create([
            'key' => $key,
            'value' => $this->serializeValue($value, $type),
            'type' => $type,
            'category' => $attrs['category'] ?? null,
            'description' => $attrs['description'] ?? null,
            'is_public' => (bool) ($attrs['is_public'] ?? false),
            'is_locked' => (bool) ($attrs['is_locked'] ?? false),
            'branch_id' => $branchId,
        ]);
    }

    /**
     * AC2: block destructive delete when referenced; offer archival instead.
     */
    public function delete(User $actor, string $key, bool $forceArchive = false): void
    {
        $setting = $this->findEditable($actor, $key);

        if ($setting->hasReferences() && ! $forceArchive) {
            throw new ConfigurationReferencedException(
                'This setting is referenced by operational records and cannot be deleted. Archive it instead.'
            );
        }

        if ($setting->hasReferences() || $forceArchive) {
            $setting->update(['is_archived' => true, 'draft_value' => null]);
            Cache::forget(self::CACHE_PREFIX . $key);

            return;
        }

        $setting->delete();
        Cache::forget(self::CACHE_PREFIX . $key);
    }

    public function getAllCategories(): Collection
    {
        return ConfigurationCategory::orderBy('name')->get();
    }

    public function createCategory(string $name, ?string $description = null, ?string $keyPrefix = null, bool $isSystem = false): ConfigurationCategory
    {
        return ConfigurationCategory::create([
            'name' => $name,
            'description' => $description,
            'key_prefix' => $keyPrefix,
            'is_system' => $isSystem,
        ]);
    }

    public function deleteCategory(string $name): bool
    {
        $category = ConfigurationCategory::where('name', $name)->first();

        if (! $category || $category->is_system) {
            return false;
        }

        Setting::where('category', $name)->each(function (Setting $setting) {
            if ($setting->hasReferences()) {
                $setting->update(['is_archived' => true]);
            } else {
                $setting->delete();
            }
        });

        $category->delete();

        return true;
    }

    public function addReference(Setting $setting, string $referenceType, int $referenceId): SettingReference
    {
        return SettingReference::firstOrCreate([
            'setting_id' => $setting->id,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
        ]);
    }

    /**
     * Idempotent write for seeders and migrations (bypasses auth).
     */
    public function set(string $key, mixed $value, string $type = 'string', ?string $category = null, ?string $description = null): Setting
    {
        $serialized = $this->serializeValue($value, $type);

        return Setting::updateOrCreate(
            ['key' => $key],
            [
                'value' => $serialized,
                'type' => $type,
                'category' => $category,
                'description' => $description,
            ],
        );
    }

    private function findEditable(User $actor, string $key): Setting
    {
        $this->assertCan($actor, 'config.manage');

        $setting = Setting::where('key', $key)->where('is_archived', false)->first();
        if (! $setting) {
            throw ValidationException::withMessages(['key' => ['Setting not found.']]);
        }

        if ($setting->is_locked && ! $actor->isChurchWide()) {
            throw new ConfigurationLockedException('This setting is centrally locked and cannot be changed locally.');
        }

        if ($setting->branch_id !== null && ! $actor->isChurchWide() && (int) $setting->branch_id !== (int) $actor->branch_id) {
            throw new ConfigurationLockedException('This setting is outside your branch scope.');
        }

        return $setting;
    }

    private function assertCan(User $actor, string $action): void
    {
        if (! $this->authorization->allows($actor, $action)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function validateValue(mixed $value, string $type, string $key): void
    {
        if ($value === null || $value === '') {
            throw ValidationException::withMessages([
                'value' => ["{$key}: a value is required."],
            ]);
        }

        match ($type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === null
                ? throw ValidationException::withMessages(['value' => ["{$key}: must be a boolean."]])
                : null,
            'integer' => ! is_numeric($value)
                ? throw ValidationException::withMessages(['value' => ["{$key}: must be an integer."]])
                : null,
            'json' => $this->validateJson($value, $key),
            default => is_string($value) && strlen($value) > 500
                ? throw ValidationException::withMessages(['value' => ["{$key}: string value is too long."]])
                : null,
        };
    }

    private function validateJson(mixed $value, string $key): void
    {
        if (is_array($value)) {
            return;
        }

        json_decode((string) $value, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw ValidationException::withMessages(['value' => ["{$key}: invalid JSON."]]);
        }
    }

    private function serializeValue(mixed $value, string $type): string
    {
        return match ($type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
            'integer' => (string) (int) $value,
            'json' => is_string($value) ? $value : json_encode($value),
            default => (string) $value,
        };
    }

    private function castFromStorage(?string $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int) $value,
            'json' => json_decode($value, true),
            default => $value,
        };
    }

    /** @return array<string, mixed> */
    public function formatSetting(Setting $setting): array
    {
        return [
            'id' => $setting->id,
            'key' => $setting->key,
            'value' => $this->castFromStorage($setting->value, $setting->type),
            'draft_value' => $setting->draft_value !== null
                ? $this->castFromStorage($setting->draft_value, $setting->type)
                : null,
            'type' => $setting->type,
            'category' => $setting->category,
            'branch_id' => $setting->branch_id,
            'description' => $setting->description,
            'is_public' => $setting->is_public,
            'is_locked' => $setting->is_locked,
            'is_archived' => $setting->is_archived,
            'has_references' => $setting->relationLoaded('references')
                ? $setting->references->isNotEmpty()
                : $setting->hasReferences(),
        ];
    }
}
