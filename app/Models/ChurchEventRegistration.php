<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ChurchEventRegistration extends Model
{
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_WAITLISTED = 'waitlisted';
    public const STATUS_CANCELLED = 'cancelled';

    public const PAYMENT_NOT_REQUIRED = 'not_required';
    public const PAYMENT_PENDING = 'pending';
    public const PAYMENT_PAID = 'paid';

    protected $fillable = [
        'church_event_id',
        'person_type',
        'person_id',
        'registrant_name',
        'registrant_email',
        'registrant_phone',
        'channel',
        'status',
        'confirmation_code',
        'credential_jti',
        'payment_status',
        'consent_data_processing',
        'registered_by',
        'admitted_at',
    ];

    protected function casts(): array
    {
        return [
            'consent_data_processing' => 'boolean',
            'admitted_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(ChurchEvent::class, 'church_event_id');
    }

    public function person(): MorphTo
    {
        return $this->morphTo();
    }

    public function registrar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    public function scanEvents(): HasMany
    {
        return $this->hasMany(ChurchEventScanEvent::class, 'registration_id');
    }
}
