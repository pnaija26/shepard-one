<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Story 1.2: security audit trail for MFA and authentication events.
 */
class SecurityAuditLog extends Model
{
    public const EVENT_MFA_VERIFICATION_FAILED = 'mfa.verification_failed';
    public const EVENT_MFA_VERIFICATION_SUCCEEDED = 'mfa.verification_succeeded';
    public const EVENT_MFA_ENROLLMENT_COMPLETED = 'mfa.enrollment_completed';
    public const EVENT_MFA_ACCESS_DENIED = 'mfa.access_denied';

    protected $table = 'security_audit_log';

    protected $fillable = [
        'user_id',
        'event',
        'ip_address',
        'detail',
    ];

    protected $casts = [
        'detail' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function record(?User $user, string $event, ?string $ipAddress = null, array $detail = []): self
    {
        return static::create([
            'user_id' => $user?->id,
            'event' => $event,
            'ip_address' => $ipAddress,
            'detail' => $detail ?: null,
        ]);
    }
}
