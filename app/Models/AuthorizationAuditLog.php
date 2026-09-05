<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Story 1.6: append-only authorization audit trail (AC3, AC4).
 *
 * Records every role/permission change, assignment lifecycle event, cache
 * invalidation, and — critically — any blocked last-super-admin attempt or
 * approved break-glass procedure so the security history is reconstructable.
 */
class AuthorizationAuditLog extends Model
{
    protected $table = 'authorization_audit_log';

    public const EVENT_ROLE_CREATED = 'role.created';
    public const EVENT_ROLE_UPDATED = 'role.updated';
    public const EVENT_ROLE_DELETED = 'role.deleted';
    public const EVENT_PERMISSION_GRANTED = 'permission.granted';
    public const EVENT_PERMISSION_REVOKED = 'permission.revoked';
    public const EVENT_ASSIGNMENT_MADE = 'assignment.made';
    public const EVENT_ASSIGNMENT_REMOVED = 'assignment.removed';
    public const EVENT_CACHE_INVALIDATED = 'cache.invalidated';
    public const EVENT_LAST_SUPER_ADMIN_BLOCKED = 'last_super_admin.blocked';
    public const EVENT_BREAK_GLASS_APPROVED = 'break_glass.approved';

    protected $fillable = [
        'actor_id',
        'event',
        'subject_type',
        'subject_id',
        'detail',
    ];

    protected $casts = [
        'detail' => 'array',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** Convenience writer used across the authorization services. */
    public static function record(?User $actor, string $event, ?string $subjectType = null, ?int $subjectId = null, array $detail = []): self
    {
        return static::create([
            'actor_id' => $actor?->id,
            'event' => $event,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'detail' => $detail ?: null,
        ]);
    }
}
