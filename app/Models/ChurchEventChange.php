<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChurchEventChange extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'church_event_id',
        'change_type',
        'before_state',
        'after_state',
        'summary',
        'changed_by',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'before_state' => 'array',
            'after_state' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function churchEvent(): BelongsTo
    {
        return $this->belongsTo(ChurchEvent::class);
    }
}