<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Story 1.6: a user's assignment to a role (AC2, AC3).
 *
 * `expires_at` implements time-limited grants — an expired assignment is not
 * counted when the effective permission set is computed, so revocation takes
 * effect on the next request without any deployment (AC3).
 */
class RoleAssignment extends Model
{
    protected $fillable = [
        'user_id',
        'role_id',
        'granted_by',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** True while this assignment is in force (AC3). */
    public function isActive(): bool
    {
        return $this->expires_at === null || $this->expires_at->isFuture();
    }
}
