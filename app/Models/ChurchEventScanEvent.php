<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChurchEventScanEvent extends Model
{
    public const OUTCOME_ADMITTED = 'admitted';
    public const OUTCOME_REJECTED = 'rejected';

    public $timestamps = false;

    protected $fillable = [
        'church_event_id',
        'registration_id',
        'credential_jti',
        'outcome',
        'reason',
        'scanned_by',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(ChurchEvent::class, 'church_event_id');
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(ChurchEventRegistration::class, 'registration_id');
    }
}
