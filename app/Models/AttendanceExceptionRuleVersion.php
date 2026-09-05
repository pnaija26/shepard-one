<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceExceptionRuleVersion extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'rule_id',
        'version',
        'parameters',
        'exclusions',
        'correction_policy',
        'published_by',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'exclusions' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AttendanceExceptionRule::class, 'rule_id');
    }

    public function exceptions(): HasMany
    {
        return $this->hasMany(AttendanceException::class, 'rule_version_id');
    }
}
