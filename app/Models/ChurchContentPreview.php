<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChurchContentPreview extends Model
{
    protected $fillable = [
        'church_content_version_id',
        'device',
        'result',
        'passed',
        'ran_by',
        'ran_at',
    ];

    protected function casts(): array
    {
        return [
            'result' => 'array',
            'passed' => 'boolean',
            'ran_at' => 'datetime',
        ];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ChurchContentVersion::class, 'church_content_version_id');
    }
}
