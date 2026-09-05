<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WelfareCaseEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'welfare_request_id',
        'event_type',
        'condition_type',
        'notes',
        'beneficiary_message',
        'from_officer_id',
        'to_officer_id',
        'actor_id',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(WelfareRequest::class, 'welfare_request_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
