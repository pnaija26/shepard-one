<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChurchEventChangeEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'church_event_id',
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

    public function churchEvent(): BelongsTo
    {
        return $this->belongsTo(ChurchEvent::class);
    }
}