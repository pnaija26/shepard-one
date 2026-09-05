<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\User;
use App\Models\WelfareAssistanceDelivery;
use App\Models\WelfareRequest;
use Illuminate\Database\Eloquent\Builder;

/**
 * Story 7.5: authorized welfare reporting with minimized beneficiary identity.
 */
class WelfareReportService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
    ) {
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function report(User $actor, array $filters = []): array
    {
        if (! $this->authorization->allows($actor, 'welfare.reports.read')) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }

        $validated = validator($filters, [
            'branch_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'request_type' => ['nullable', 'string', 'in:' . implode(',', config('welfare_requests.request_types', []))],
            'status' => ['nullable', 'string'],
            'period_from' => ['nullable', 'date'],
            'period_to' => ['nullable', 'date', 'after_or_equal:period_from'],
            'min_value' => ['nullable', 'numeric', 'min:0'],
            'max_value' => ['nullable', 'numeric', 'gte:min_value'],
            'include_identity' => ['nullable', 'boolean'],
        ])->validate();

        $includeIdentity = (bool) ($validated['include_identity'] ?? false);
        if ($includeIdentity && ! $this->authorization->allows($actor, 'welfare.reports.identity.read')) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }

        $query = WelfareRequest::query()->with(['branch:id,name', 'beneficiary:id,first_name,last_name']);
        $this->applyBranchScope($query, $actor);
        $this->applyFilters($query, $validated);

        $cases = $query->orderByDesc('id')->limit(500)->get();
        $caseIds = $cases->pluck('id')->all();

        $expenditure = empty($caseIds)
            ? 0.0
            : (float) WelfareAssistanceDelivery::query()
                ->whereIn('welfare_request_id', $caseIds)
                ->sum('amount');

        $approvedValue = (float) $cases->sum(fn (WelfareRequest $case) => (float) ($case->approved_value ?? 0));
        $requestedValue = (float) $cases->sum(fn (WelfareRequest $case) => (float) ($case->requested_value ?? 0));

        $byStatus = $cases->groupBy('status')->map->count()->all();
        $byType = $cases->groupBy('request_type')->map->count()->all();

        $outstanding = $cases->filter(fn (WelfareRequest $case) => in_array($case->status, [
            WelfareRequest::STATUS_FOLLOW_UP,
            WelfareRequest::STATUS_DISBURSED,
            WelfareRequest::STATUS_APPROVED,
            WelfareRequest::STATUS_PENDING_APPROVAL,
            WelfareRequest::STATUS_ESCALATED,
        ], true));

        $overdueFollowUps = $outstanding->filter(
            fn (WelfareRequest $case) => $case->follow_up_due_on !== null
                && $case->follow_up_due_on->isPast()
                && in_array($case->status, [WelfareRequest::STATUS_FOLLOW_UP, WelfareRequest::STATUS_DISBURSED], true)
        )->count();

        $uniqueBeneficiaries = $cases->pluck('beneficiary_member_id')->filter()->unique()->count();

        $canReadFinance = $this->authorization->allows($actor, 'welfare.finance.read')
            || $this->authorization->allows($actor, 'welfare.reports.read');

        $rows = $cases->map(function (WelfareRequest $case) use ($includeIdentity, $canReadFinance) {
            $row = [
                'id' => $case->id,
                'case_number' => $case->case_number,
                'branch_id' => $case->branch_id,
                'branch_name' => $case->relationLoaded('branch') ? $case->branch?->name : null,
                'request_type' => $case->request_type,
                'status' => $case->status,
                'priority' => $case->priority,
                'requested_value' => $canReadFinance ? $case->requested_value : null,
                'approved_value' => $canReadFinance ? $case->approved_value : null,
                'follow_up_due_on' => $case->follow_up_due_on?->toDateString(),
                'closed_at' => $case->closed_at?->toIso8601String(),
                'submitted_at' => $case->submitted_at?->toIso8601String(),
            ];

            if ($includeIdentity) {
                $row['beneficiary_member_id'] = $case->beneficiary_member_id;
                $row['beneficiary_name'] = $case->beneficiary?->fullName()
                    ?? $case->beneficiary_name;
            } else {
                $row['beneficiary_member_id'] = null;
                $row['beneficiary_name'] = null;
                $row['identity_minimized'] = true;
            }

            return $row;
        })->values()->all();

        $this->audit->record(
            actor: $actor,
            action: 'welfare.report.generated',
            category: AuditEvent::CATEGORY_BUSINESS,
            module: 'welfare',
            branchId: $validated['branch_id'] ?? $actor->branch_id,
            subjectType: 'welfare_report',
            subjectId: null,
            after: [
                'case_count' => $cases->count(),
                'include_identity' => $includeIdentity,
                'filters' => $validated,
            ],
        );

        return [
            'generated_at' => now()->toIso8601String(),
            'filters' => $validated,
            'identity_included' => $includeIdentity,
            'summary' => [
                'case_count' => $cases->count(),
                'beneficiary_count' => $uniqueBeneficiaries,
                'requested_value_total' => $canReadFinance ? round($requestedValue, 2) : null,
                'approved_value_total' => $canReadFinance ? round($approvedValue, 2) : null,
                'expenditure_total' => $canReadFinance ? round($expenditure, 2) : null,
                'outstanding_count' => $outstanding->count(),
                'overdue_follow_ups' => $overdueFollowUps,
                'closed_count' => (int) ($byStatus[WelfareRequest::STATUS_CLOSED] ?? 0),
                'by_status' => $byStatus,
                'by_request_type' => $byType,
            ],
            'cases' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['branch_id'])) {
            $query->where('branch_id', (int) $filters['branch_id']);
        }

        if (! empty($filters['request_type'])) {
            $query->where('request_type', $filters['request_type']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['period_from'])) {
            $query->where(function (Builder $inner) use ($filters): void {
                $inner->whereDate('submitted_at', '>=', $filters['period_from'])
                    ->orWhere(function (Builder $draft) use ($filters): void {
                        $draft->whereNull('submitted_at')->whereDate('created_at', '>=', $filters['period_from']);
                    });
            });
        }

        if (! empty($filters['period_to'])) {
            $query->where(function (Builder $inner) use ($filters): void {
                $inner->whereDate('submitted_at', '<=', $filters['period_to'])
                    ->orWhere(function (Builder $draft) use ($filters): void {
                        $draft->whereNull('submitted_at')->whereDate('created_at', '<=', $filters['period_to']);
                    });
            });
        }

        if (isset($filters['min_value'])) {
            $query->where(function (Builder $inner) use ($filters): void {
                $inner->where('approved_value', '>=', $filters['min_value'])
                    ->orWhere(function (Builder $req) use ($filters): void {
                        $req->whereNull('approved_value')->where('requested_value', '>=', $filters['min_value']);
                    });
            });
        }

        if (isset($filters['max_value'])) {
            $query->where(function (Builder $inner) use ($filters): void {
                $inner->where('approved_value', '<=', $filters['max_value'])
                    ->orWhere(function (Builder $req) use ($filters): void {
                        $req->whereNull('approved_value')->where('requested_value', '<=', $filters['max_value']);
                    });
            });
        }
    }

    private function applyBranchScope(Builder $query, User $actor): void
    {
        if ($actor->isChurchWide()) {
            return;
        }

        $scope = BranchScope::for($actor);
        $query->whereIn('branch_id', $scope->subtreeIds((int) $scope->branchId()));
    }
}
