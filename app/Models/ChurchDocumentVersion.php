<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Story 14.2: immutable church document version history.
 */
class ChurchDocumentVersion extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'church_document_id',
        'version_number',
        'original_filename',
        'stored_filename',
        'mime_type',
        'size_bytes',
        'content_hash',
        'storage_disk',
        'storage_path',
        'replacement_reason',
        'uploaded_by',
        'is_current',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'is_current' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(ChurchDocument::class, 'church_document_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
