<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Story 10.3: preview evidence for a template version.
 */
class MessageTemplatePreview extends Model
{
    protected $fillable = [
        'message_template_version_id',
        'sample_data',
        'rendered',
        'passed',
        'ran_by',
        'ran_at',
    ];

    protected function casts(): array
    {
        return [
            'sample_data' => 'array',
            'rendered' => 'array',
            'passed' => 'boolean',
            'ran_at' => 'datetime',
        ];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(MessageTemplateVersion::class, 'message_template_version_id');
    }
}
