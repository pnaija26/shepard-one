<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Newsletter extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_SENDING = 'sending';
    public const STATUS_SENT = 'sent';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'reference',
        'name',
        'branch_id',
        'status',
        'current_version',
        'approved_version',
        'audience_type',
        'audience_params',
        'scheduled_at',
        'sent_at',
        'created_by',
        'updated_by',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'audience_params' => 'array',
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'branch_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(NewsletterVersion::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(NewsletterDelivery::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(NewsletterEvent::class);
    }
}
