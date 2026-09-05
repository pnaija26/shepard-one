<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Story 1.8: scoped search, export, and meta-audit for audit review.
 */
class AuditReviewService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
    ) {
    }

    public function canRead(User $user): bool
    {
        return $this->authorization->allows($user, 'audit.read');
    }

    public function canExport(User $user): bool
    {
        return $this->authorization->allows($user, 'audit.export');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function search(User $auditor, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $this->ensureCanRead($auditor);

        $query = $this->scopedQuery($auditor)
            ->with(['actor:id,name,email', 'branch:id,name'])
            ->orderByDesc('created_at');

        $this->applyFilters($query, $filters);

        return $query->paginate($perPage);
    }

    public function findForAuditor(User $auditor, int $id): AuditEvent
    {
        $this->ensureCanRead($auditor);

        $event = $this->scopedQuery($auditor)->with(['actor:id,name,email', 'branch:id,name'])->find($id);

        if ($event === null) {
            throw new AuthorizationException('Forbidden.');
        }

        return $event;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return AuditEvent[]
     */
    public function export(User $auditor, array $filters, ?Request $request = null): array
    {
        if (! $this->canExport($auditor)) {
            $this->recordDenied($auditor, 'export', $request, $filters);
            throw new AuthorizationException('Forbidden.');
        }

        $query = $this->scopedQuery($auditor)->orderByDesc('created_at');
        $this->applyFilters($query, $filters);

        $events = $query->limit(5000)->get()->all();

        $this->audit->record(
            actor: $auditor,
            action: AuditEvent::ACTION_AUDIT_EXPORTED,
            category: AuditEvent::CATEGORY_SECURITY,
            request: $request,
            module: 'audit',
            metadata: ['filters' => $filters, 'count' => count($events)],
        );

        return $events;
    }

    public function recordView(User $auditor, ?Request $request = null, array $filters = []): void
    {
        if (! $this->canRead($auditor)) {
            return;
        }

        $this->audit->record(
            actor: $auditor,
            action: AuditEvent::ACTION_AUDIT_VIEWED,
            category: AuditEvent::CATEGORY_SECURITY,
            request: $request,
            module: 'audit',
            metadata: ['filters' => $filters],
        );
    }

    public function recordDenied(?User $actor, string $operation, ?Request $request = null, array $context = []): void
    {
        $this->audit->record(
            actor: $actor,
            action: AuditEvent::ACTION_AUDIT_ACCESS_DENIED,
            category: AuditEvent::CATEGORY_SECURITY,
            request: $request,
            module: 'audit',
            metadata: array_merge(['operation' => $operation], $context),
        );
    }

    public function formatEvent(AuditEvent $event): array
    {
        return [
            'id' => $event->id,
            'actor_id' => $event->actor_id,
            'actor' => $event->actor ? [
                'id' => $event->actor->id,
                'name' => $event->actor->name,
                'email' => $event->actor->email,
            ] : null,
            'action' => $event->action,
            'category' => $event->category,
            'module' => $event->module,
            'branch_id' => $event->branch_id,
            'branch' => $event->branch ? [
                'id' => $event->branch->id,
                'name' => $event->branch->name,
            ] : null,
            'subject_type' => $event->subject_type,
            'subject_id' => $event->subject_id,
            'ip_address' => $event->ip_address,
            'user_agent' => $event->user_agent,
            'before_values' => $event->before_values,
            'after_values' => $event->after_values,
            'metadata' => $event->metadata,
            'created_at' => $event->created_at?->toIso8601String(),
        ];
    }

    private function ensureCanRead(User $auditor): void
    {
        if (! $this->canRead($auditor)) {
            $this->recordDenied($auditor, 'read');
            throw new AuthorizationException('Forbidden.');
        }
    }

    private function scopedQuery(User $auditor): Builder
    {
        $retentionFrom = now()->subDays(config('audit.retention_days', 2555));

        $query = AuditEvent::query()->where('created_at', '>=', $retentionFrom);

        if ($auditor->isChurchWide()) {
            return $query;
        }

        try {
            $scope = BranchScope::for($auditor);
        } catch (BranchScopeException) {
            return $query->whereRaw('1 = 0');
        }

        $allowedBranchIds = $scope->subtreeIds((int) $scope->branchId());

        return $query->where(function (Builder $q) use ($allowedBranchIds, $auditor) {
            $q->whereIn('branch_id', $allowedBranchIds)
                ->orWhere(function (Builder $qq) use ($auditor) {
                    $qq->whereNull('branch_id')
                        ->where('actor_id', $auditor->id);
                });
        });
    }

    /**
     * @param  Builder<AuditEvent>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }

        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }

        if (! empty($filters['actor_id'])) {
            $query->where('actor_id', (int) $filters['actor_id']);
        }

        if (! empty($filters['branch_id'])) {
            $query->where('branch_id', (int) $filters['branch_id']);
        }

        if (! empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }

        if (! empty($filters['module'])) {
            $query->where('module', $filters['module']);
        }

        if (! empty($filters['subject_type'])) {
            $query->where('subject_type', $filters['subject_type']);
        }

        if (! empty($filters['subject_id'])) {
            $query->where('subject_id', (int) $filters['subject_id']);
        }

        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }
    }
}
