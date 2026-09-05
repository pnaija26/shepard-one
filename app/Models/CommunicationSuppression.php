<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Story 10.1: unsubscribe / bounce / manual suppression list.
 */
class CommunicationSuppression extends Model
{
    protected $fillable = [
        'member_id',
        'channel',
        'reason',
        'active',
        'created_by',
        'suppressed_at',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'suppressed_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
