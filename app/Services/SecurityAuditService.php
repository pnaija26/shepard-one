<?php

namespace App\Services;

use App\Models\SecurityAuditLog;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Story 1.2: records security audit events (e.g. failed MFA attempts).
 * Story 1.8: mirrors events into the unified audit_events trail.
 */
class SecurityAuditService
{
    public function __construct(
        private AuditService $audit,
    ) {
    }

    public function record(?User $user, string $event, ?Request $request = null, array $detail = []): SecurityAuditLog
    {
        $log = SecurityAuditLog::record(
            $user,
            $event,
            $request?->ip(),
            $detail,
        );

        $this->audit->recordSecurityEvent($user, $event, $request, $detail);

        return $log;
    }
}
