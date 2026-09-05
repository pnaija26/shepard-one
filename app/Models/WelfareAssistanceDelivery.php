<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class WelfareAssistanceDelivery extends Model
{
    public const TYPE_DISBURSEMENT = 'disbursement';
    public const TYPE_IN_KIND = 'in_kind';

    protected $fillable = [
        'welfare_request_id',
        'branch_id',
        'delivery_type',
        'method',
        'amount',
        'currency',
        'delivered_on',
        'reference',
        'notes',
        'evidence',
        'approved_value_snapshot',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'approved_value_snapshot' => 'decimal:2',
            'delivered_on' => 'date:Y-m-d',
            'evidence' => 'array',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(WelfareRequest::class, 'welfare_request_id');
    }

    public function confirmation(): HasOne
    {
        return $this->hasOne(WelfareAssistanceConfirmation::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
