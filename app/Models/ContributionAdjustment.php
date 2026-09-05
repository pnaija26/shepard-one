<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContributionAdjustment extends Model
{
    public const TYPE_VOID = 'void';
    public const TYPE_CORRECTION = 'correction';
    public const TYPE_AMOUNT_CHANGE = 'amount_change';
    public const TYPE_CATEGORY_CHANGE = 'category_change';

    protected $fillable = [
        'reference',
        'contribution_id',
        'receipt_id',
        'adjustment_type',
        'amount_delta_cents',
        'before_values',
        'after_values',
        'reason',
        'created_by',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_delta_cents' => 'integer',
            'before_values' => 'array',
            'after_values' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function contribution(): BelongsTo
    {
        return $this->belongsTo(Contribution::class);
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(ContributionReceipt::class, 'receipt_id');
    }
}
