<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NewsletterPreview extends Model
{
    protected $fillable = [
        'newsletter_version_id',
        'viewport',
        'result',
        'passed',
        'ran_by',
        'ran_at',
    ];

    protected function casts(): array
    {
        return [
            'result' => 'array',
            'passed' => 'boolean',
            'ran_at' => 'datetime',
        ];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(NewsletterVersion::class, 'newsletter_version_id');
    }
}
