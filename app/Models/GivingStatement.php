<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GivingStatement extends Model
{
    protected $fillable = [
        'reference',
        'member_id',
        'requested_by',
        'period_from',
        'period_to',
        'total_cents',
        'currency',
        'line_count',
        'totals_by_category',
        'line_items',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'period_from' => 'date',
            'period_to' => 'date',
            'total_cents' => 'integer',
            'line_count' => 'integer',
            'totals_by_category' => 'array',
            'line_items' => 'array',
            'generated_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
