<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NewsletterDelivery extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENT = 'sent';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_BOUNCED = 'bounced';
    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'newsletter_id',
        'newsletter_version_id',
        'member_id',
        'channel',
        'status',
        'skip_reason',
        'provider_ref',
        'is_test',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'is_test' => 'boolean',
            'sent_at' => 'datetime',
        ];
    }

    public function newsletter(): BelongsTo
    {
        return $this->belongsTo(Newsletter::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(NewsletterVersion::class, 'newsletter_version_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(NewsletterEvent::class);
    }
}
