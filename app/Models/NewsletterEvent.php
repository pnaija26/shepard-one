<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NewsletterEvent extends Model
{
    protected $fillable = [
        'newsletter_id',
        'newsletter_delivery_id',
        'event_type',
        'provider',
        'payload',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function newsletter(): BelongsTo
    {
        return $this->belongsTo(Newsletter::class);
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(NewsletterDelivery::class, 'newsletter_delivery_id');
    }
}
