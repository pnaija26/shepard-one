<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChurchServiceChange extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'church_service_id',
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

    public function churchService(): BelongsTo
    {
        return $this->belongsTo(ChurchService::class);
    }
}
