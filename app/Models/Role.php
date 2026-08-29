<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Story 1.6: a named bundle of scoped permissions (role_permissions rows).
 *
 * `is_super_admin` marks the break-glass tier protected by AC4 — removing or
 * neutering the last viable super-admin path is blocked unless an approved
 * break-glass procedure is used, and the attempt is always recorded.
 */
class Role extends Model
{
    public const SUPER_ADMIN = 'super_admin';

    protected $fillable = [
        'name',
        'description',
        'is_super_admin',
        'is_system',
    ];

    protected $casts = [
        'is_super_admin' => 'boolean',
        'is_system' => 'boolean',
    ];

    public function permissions(): HasMany
    {
        return $this->hasMany(RolePermission::class);
    }

    /** All assignments (including expired — filter with activeAssignments). */
    public function assignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }

    /** Assignments that are still in force right now (AC3: expired = inactive). */
    public function activeAssignments(): HasMany
    {
        return $this->assignments()
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }
}
