<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Story 1.8 AC1: central writer for append-only audit events with redaction.
 */
class AuditService
{
    public function record(
        ?User $actor,
        string $action,
        string $category = AuditEvent::CATEGORY_SECURITY,
        ?Request $request = null,
        ?string $module = null,
        ?int $branchId = null,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?array $before = null,
        ?array $after = null,
        array $metadata = [],
    ): AuditEvent {
        return AuditEvent::create([
            'actor_id' => $actor?->id,
            'action' => $action,
            'category' => $category,
            'module' => $module,
            'branch_id' => $branchId ?? $actor?->branch_id,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent() ? mb_substr((string) $request->userAgent(), 0, 512) : null,
            'before_values' => $this->redact($before),
            'after_values' => $this->redact($after),
            'metadata' => $this->redact($metadata) ?: null,
            'created_at' => now(),
        ]);
    }

    /** Bridge Story 1.2 security events into the unified audit trail. */
    public function recordSecurityEvent(?User $actor, string $event, ?Request $request = null, array $detail = []): AuditEvent
    {
        return $this->record(
            actor: $actor,
            action: $event,
            category: AuditEvent::CATEGORY_SECURITY,
            request: $request,
            module: 'security',
            after: $detail ?: null,
        );
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    public function redact(?array $payload): ?array
    {
        if ($payload === null || $payload === []) {
            return null;
        }

        $keys = array_map('strtolower', config('audit.redact_keys', []));

        $walk = function (mixed $value) use (&$walk, $keys): mixed {
            if (! is_array($value)) {
                return $value;
            }

            $redacted = [];
            foreach ($value as $key => $item) {
                if (in_array(strtolower((string) $key), $keys, true)) {
                    $redacted[$key] = '[REDACTED]';
                } else {
                    $redacted[$key] = $walk($item);
                }
            }

            return $redacted;
        };

        return $walk($payload);
    }
}
