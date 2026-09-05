<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contribution extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_CANCELLED = 'cancelled';

    public const SOURCE_INTEGRATED = 'integrated';
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_IMPORTED = 'imported';

    public const RECON_UNMATCHED = 'unmatched';
    public const RECON_MATCHED = 'matched';
    public const RECON_NEEDS_RESOLUTION = 'needs_resolution';
    public const RECON_RECONCILED = 'reconciled';

    protected $fillable = [
        'reference',
        'payment_source_id',
        'provider',
        'source_type',
        'provider_payment_reference',
        'payment_reference',
        'status',
        'amount_cents',
        'currency',
        'category',
        'campaign_id',
        'branch_id',
        'member_id',
        'payer_linked',
        'payer_external_id',
        'provider_evidence',
        'reconciliation_status',
        'resolution_reason',
        'reconciled_by',
        'reconciled_at',
        'receipt_eligible',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'payer_linked' => 'boolean',
            'provider_evidence' => 'array',
            'receipt_eligible' => 'boolean',
            'occurred_at' => 'datetime',
            'reconciled_at' => 'datetime',
        ];
    }

    public function paymentSource(): BelongsTo
    {
        return $this->belongsTo(PaymentSource::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'branch_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(GivingCampaign::class, 'campaign_id');
    }

    public function reconciliationEvents(): HasMany
    {
        return $this->hasMany(ContributionReconciliationEvent::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(ContributionReceipt::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(ContributionAdjustment::class);
    }

    public function activeReceipt(): ?ContributionReceipt
    {
        return $this->receipts()
            ->where('status', ContributionReceipt::STATUS_ISSUED)
            ->orderByDesc('id')
            ->first();
    }
}
