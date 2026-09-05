<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomReportPreview extends Model
{
    protected $fillable = [
        'custom_report_version_id',
        'preview_payload',
        'ran_at',
        'ran_by',
    ];

    protected function casts(): array
    {
        return [
            'preview_payload' => 'array',
            'ran_at' => 'datetime',
        ];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(CustomReportVersion::class, 'custom_report_version_id');
    }
}
