<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WelfareAssistanceConfirmation extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_WAIVED = 'waived';

    protected $fillable = [
        'welfare_assistance_delivery_id',
        'welfare_request_id',
        'status',
        'confirmed_at',
        'waiver_reason',
        'evidence',
        'confirmed_by_member_id',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
            'evidence' => 'array',
        ];
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(WelfareAssistanceDelivery::class, 'welfare_assistance_delivery_id');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(WelfareRequest::class, 'welfare_request_id');
    }
}
