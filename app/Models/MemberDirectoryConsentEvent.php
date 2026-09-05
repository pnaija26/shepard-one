<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberDirectoryConsentEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'member_id',
        'actor_id',
        'consent_directory',
        'visibility_before',
        'visibility_after',
        'effective_at',
        'created_at',
    ];

    protected $casts = [
        'consent_directory' => 'boolean',
        'visibility_before' => 'array',
        'visibility_after' => 'array',
        'effective_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
