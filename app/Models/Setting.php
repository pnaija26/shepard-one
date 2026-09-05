<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Setting extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'value',
        'draft_value',
        'type',
        'category',
        'branch_id',
        'is_public',
        'is_locked',
        'is_archived',
        'description',
    ];

    protected $casts = [
        'is_public' => 'boolean',
        'is_locked' => 'boolean',
        'is_archived' => 'boolean',
    ];

    public function references(): HasMany
    {
        return $this->hasMany(SettingReference::class);
    }

    public function hasReferences(): bool
    {
        return $this->references()->exists();
    }

    public function scopeActive($query)
    {
        return $query->where('is_archived', false);
    }
}
