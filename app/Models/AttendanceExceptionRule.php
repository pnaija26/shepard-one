<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceExceptionRule extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';

    protected $fillable = [
        'name',
        'rule_type',
        'branch_id',
        'service_type',
        'status',
        'current_version',
        'created_by',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'branch_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(AttendanceExceptionRuleVersion::class, 'rule_id');
    }

    public function exceptions(): HasMany
    {
        return $this->hasMany(AttendanceException::class, 'rule_id');
    }

    public function latestVersion(): ?AttendanceExceptionRuleVersion
    {
        return $this->versions()->orderByDesc('version')->first();
    }
}
