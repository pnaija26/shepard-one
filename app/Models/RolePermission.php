<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Story 1.6: one scoped permission row on a role (AC1).
 *
 * Dimensions:
 *   scope_type/scope_id — global, or an organization unit (branch, ministry,
 *                         department, team, group, campus, location) whose
 *                         subtree the grant covers;
 *   module / function_name / record_type — optional narrowing of what is
 *                         permitted within that scope;
 *   action — the supported action granted ('*' = any action).
 */
class RolePermission extends Model
{
    public const SCOPE_GLOBAL = 'global';

    /** Organization-unit types usable as a permission scope (AC1 list). */
    public const SCOPE_TYPES = [
        self::SCOPE_GLOBAL,
        'organization',
        'branch',
        'ministry',
        'department',
        'team',
        'group',
        'campus',
        'location',
    ];

    protected $fillable = [
        'role_id',
        'scope_type',
        'scope_id',
        'module',
        'function_name',
        'record_type',
        'action',
    ];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** True when this row is a global (unscoped) grant. */
    public function isGlobal(): bool
    {
        return $this->scope_type === self::SCOPE_GLOBAL;
    }
}
