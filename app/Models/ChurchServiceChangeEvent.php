<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChurchServiceChangeEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'church_service_id',
        'event_type',
        'payload',
        'processed_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function churchService(): BelongsTo
    {
        return $this->belongsTo(ChurchService::class);
    }
}
