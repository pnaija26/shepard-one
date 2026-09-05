<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComposableDashboardPreview extends Model
{
    protected $fillable = [
        'composable_dashboard_version_id',
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
        return $this->belongsTo(ComposableDashboardVersion::class, 'composable_dashboard_version_id');
    }

    public function runner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ran_by');
    }
}
