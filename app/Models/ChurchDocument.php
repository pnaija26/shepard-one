<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Story 14.1: protected church document linked to operational records.
 */
class ChurchDocument extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_PROCESSING = 'processing';

    public const LIFECYCLE_ACTIVE = 'active';
    public const LIFECYCLE_ARCHIVED = 'archived';
    public const LIFECYCLE_PENDING_DELETION = 'pending_deletion';

    public const SCAN_PENDING = 'pending';
    public const SCAN_CLEAN = 'clean';
    public const SCAN_BLOCKED = 'blocked';

    protected $fillable = [
        'reference',
        'title',
        'category',
        'record_type',
        'record_id',
        'branch_id',
        'classification',
        'access_scope',
        'retention_policy',
        'retention_ends_at',
        'original_filename',
        'stored_filename',
        'mime_type',
        'size_bytes',
        'content_hash',
        'storage_disk',
        'storage_path',
        'version_number',
        'status',
        'lifecycle_status',
        'legal_hold',
        'archived_at',
        'archive_requested_at',
        'malware_scan_status',
        'thumbnail_path',
        'metadata',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'retention_ends_at' => 'datetime',
            'archived_at' => 'datetime',
            'archive_requested_at' => 'datetime',
            'legal_hold' => 'boolean',
        ];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'branch_id');
    }

    public function processingJobs(): HasMany
    {
        return $this->hasMany(ChurchDocumentProcessingJob::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ChurchDocumentVersion::class)->orderByDesc('version_number');
    }

    public function currentVersionRecord(): HasOne
    {
        return $this->hasOne(ChurchDocumentVersion::class)->where('is_current', true);
    }
}
