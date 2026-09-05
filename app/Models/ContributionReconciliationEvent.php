<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContributionReconciliationEvent extends Model
{
    protected $fillable = [
        'contribution_id',
        'actor_id',
        'action',
        'from_status',
        'to_status',
        'before_values',
        'after_values',
        'notes',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'before_values' => 'array',
            'after_values' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function contribution(): BelongsTo
    {
        return $this->belongsTo(Contribution::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
