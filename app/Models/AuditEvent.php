<?php

namespace App\Models;

use App\Services\AuditImmutabilityException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Story 1.8: append-only audit record for security and business events.
 */
class AuditEvent extends Model
{
    public const CATEGORY_SECURITY = 'security';
    public const CATEGORY_BUSINESS = 'business';

    public const ACTION_AUTH_LOGIN = 'auth.login';
    public const ACTION_AUTH_LOGOUT = 'auth.logout';
    public const ACTION_AUTH_LOGIN_FAILED = 'auth.login_failed';
    public const ACTION_AUDIT_VIEWED = 'audit.viewed';
    public const ACTION_AUDIT_EXPORTED = 'audit.exported';
    public const ACTION_AUDIT_ACCESS_DENIED = 'audit.access_denied';

    protected $table = 'audit_events';

    public $timestamps = false;

    protected $fillable = [
        'actor_id',
        'action',
        'category',
        'module',
        'branch_id',
        'subject_type',
        'subject_id',
        'ip_address',
        'user_agent',
        'before_values',
        'after_values',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'before_values' => 'array',
        'after_values' => 'array',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new AuditImmutabilityException('Audit records cannot be modified.');
        });

        static::deleting(function () {
            throw new AuditImmutabilityException('Audit records cannot be deleted.');
        });
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'branch_id');
    }
}
