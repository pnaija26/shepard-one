<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Story 14.2: short-lived protected document access grants.
 */
class ChurchDocumentAccessGrant extends Model
{
    public const MODE_PREVIEW = 'preview';
    public const MODE_DOWNLOAD = 'download';

    protected $fillable = [
        'church_document_id',
        'church_document_version_id',
        'user_id',
        'mode',
        'token_hash',
        'expires_at',
        'used_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(ChurchDocument::class, 'church_document_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ChurchDocumentVersion::class, 'church_document_version_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
