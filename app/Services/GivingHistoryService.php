<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Contribution;
use App\Models\ContributionAdjustment;
use App\Models\GivingStatement;
use App\Models\Member;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Story 11.3: permission-appropriate giving history, statements, and finance reports.
 */
class GivingHistoryService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
    ) {
    }

    /**
     * Member self-service history for a period (own linked + approved contributions only).
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function memberHistory(User $actor, array $filters = []): array
    {
        $this->assertMemberGivingEnabled($actor);
        $member = $this->requireActorMember($actor);
        [$from, $to] = $this->parsePeriod($filters);

        // Explicitly ignore any client-supplied member_id (parameter tampering).
        if (isset($filters['member_id']) && (int) $filters['member_id'] !== (int) $member->id) {
            $this->denyAccess($actor, 'giving.member_history.tamper', [
                'requested_member_id' => (int) $filters['member_id'],
                'actor_member_id' => $member->id,
            ]);
            throw new GivingAccessException('You cannot access another member\'s giving history.', 'forbidden_member', 403);
        }

        $items = $this->memberScopedQuery($member, $from, $to)
            ->with(['campaign:id,name,code', 'branch:id,name'])
            ->orderByDesc('occurred_at')
            ->limit(200)
            ->get();

        return [
            'member_id' => $member->id,
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'total_cents' => (int) $items->sum('amount_cents'),
            'currency' => $items->first()?->currency ?? 'USD',
            'count' => $items->count(),
            'items' => $items->map(fn (Contribution $c) => $this->formatMemberLine($c))->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function requestStatement(User $actor, array $filters = []): GivingStatement
    {
        $this->assertMemberGivingEnabled($actor);
        $member = $this->requireActorMember($actor);
        [$from, $to] = $this->parsePeriod($filters);

        if (isset($filters['member_id']) && (int) $filters['member_id'] !== (int) $member->id) {
            $this->denyAccess($actor, 'giving.statement.tamper', [
                'requested_member_id' => (int) $filters['member_id'],
                'actor_member_id' => $member->id,
            ]);
            throw new GivingAccessException('You cannot request a statement for another member.', 'forbidden_member', 403);
        }

        $items = $this->memberScopedQuery($member, $from, $to)
            ->orderBy('occurred_at')
            ->limit(500)
            ->get();

        $byCategory = [];
        foreach ($items as $item) {
            $byCategory[$item->category] = ($byCategory[$item->category] ?? 0) + (int) $item->amount_cents;
        }

        $statement = GivingStatement::create([
            'reference' => 'GS-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6)),
            'member_id' => $member->id,
            'requested_by' => $actor->id,
            'period_from' => $from->toDateString(),
            'period_to' => $to->toDateString(),
            'total_cents' => (int) $items->sum('amount_cents'),
            'currency' => $items->first()?->currency ?? 'USD',
            'line_count' => $items->count(),
            'totals_by_category' => $byCategory,
            'line_items' => $items->map(fn (Contribution $c) => $this->formatMemberLine($c))->values()->all(),
            'generated_at' => now(),
        ]);

        $this->audit->record(
            actor: $actor,
            action: 'giving.statement_generated',
            category: AuditEvent::CATEGORY_BUSINESS,
            module: 'payments',
            branchId: $member->branch_id,
            subjectType: GivingStatement::class,
            subjectId: $statement->id,
            after: [
                'reference' => $statement->reference,
                'total_cents' => $statement->total_cents,
                'line_count' => $statement->line_count,
            ],
        );

        return $statement;
    }

    /**
     * Finance report with optional donor identity (minimized by default).
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function financeReport(User $actor, array $filters = []): array
    {
        if (! $this->authorization->allows($actor, 'payments.giving.reports')) {
            $this->denyAccess($actor, 'giving.report.denied', ['filters' => $this->sanitizeFilters($filters)]);
            throw new GivingAccessException('Giving reports are not permitted for this role.', 'forbidden', 403);
        }

        [$from, $to] = $this->parsePeriod($filters);
        $includeIdentity = $this->authorization->allows($actor, 'payments.giving.identity')
            && (bool) ($filters['include_identity'] ?? false);

        $query = Contribution::query()
            ->where('status', Contribution::STATUS_SUCCEEDED)
            ->whereBetween('occurred_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);

        $this->applyBranchScope($query, $actor);

        if (! empty($filters['branch_id'])) {
            $branchId = (int) $filters['branch_id'];
            if (! $this->isInBranchScope($actor, $branchId)) {
                $this->denyAccess($actor, 'giving.report.branch_denied', ['branch_id' => $branchId]);
                throw new GivingAccessException('Branch is outside your scope.', 'forbidden_branch', 403);
            }
            $query->where('branch_id', $branchId);
        }
        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }
        if (! empty($filters['campaign_id'])) {
            $query->where('campaign_id', (int) $filters['campaign_id']);
        }
        if (! empty($filters['reconciliation_status'])) {
            $query->where('reconciliation_status', $filters['reconciliation_status']);
        } else {
            // Default: reconciled confirmed funds for reporting.
            $query->where('reconciliation_status', Contribution::RECON_RECONCILED);
        }

        $limit = min((int) ($filters['limit'] ?? config('payments.report_default_limit', 200)), 500);
        $contributions = $query->with(['campaign:id,name,code', 'branch:id,name', 'member:id,first_name,last_name'])
            ->orderByDesc('occurred_at')
            ->limit($limit)
            ->get();

        $contributionIds = $contributions->pluck('id')->all();
        $adjustments = ContributionAdjustment::query()
            ->whereIn('contribution_id', $contributionIds ?: [0])
            ->get()
            ->groupBy('contribution_id');

        $grossCents = (int) $contributions->sum('amount_cents');
        $adjustmentDelta = (int) $adjustments->flatten()->sum('amount_delta_cents');

        $byCategory = [];
        $byCampaign = [];
        $byBranch = [];
        foreach ($contributions as $c) {
            $byCategory[$c->category] = ($byCategory[$c->category] ?? 0) + (int) $c->amount_cents;
            $campKey = $c->campaign_id ? (string) $c->campaign_id : 'none';
            $byCampaign[$campKey] = ($byCampaign[$campKey] ?? 0) + (int) $c->amount_cents;
            $branchKey = $c->branch_id ? (string) $c->branch_id : 'none';
            $byBranch[$branchKey] = ($byBranch[$branchKey] ?? 0) + (int) $c->amount_cents;
        }

        $rows = $contributions->map(function (Contribution $c) use ($includeIdentity, $adjustments) {
            $row = [
                'id' => $c->id,
                'reference' => $c->reference,
                'amount_cents' => $c->amount_cents,
                'currency' => $c->currency,
                'category' => $c->category,
                'campaign_id' => $c->campaign_id,
                'campaign_code' => $c->campaign?->code,
                'branch_id' => $c->branch_id,
                'status' => $c->status,
                'reconciliation_status' => $c->reconciliation_status,
                'occurred_at' => $c->occurred_at?->toIso8601String(),
                'adjustment_count' => ($adjustments->get($c->id) ?? collect())->count(),
                'adjustment_delta_cents' => (int) ($adjustments->get($c->id) ?? collect())->sum('amount_delta_cents'),
            ];

            if ($includeIdentity) {
                $row['member_id'] = $c->member_id;
                $row['member_name'] = $c->member
                    ? trim(($c->member->first_name ?? '') . ' ' . ($c->member->last_name ?? ''))
                    : null;
            } else {
                $row['donor'] = $c->member_id ? 'linked' : 'anonymous';
            }

            return $row;
        })->values()->all();

        $this->audit->record(
            actor: $actor,
            action: 'giving.report_viewed',
            category: AuditEvent::CATEGORY_BUSINESS,
            module: 'payments',
            branchId: $actor->branch_id,
            after: [
                'period_from' => $from->toDateString(),
                'period_to' => $to->toDateString(),
                'include_identity' => $includeIdentity,
                'row_count' => count($rows),
                'gross_cents' => $grossCents,
            ],
        );

        return [
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'filters' => $this->sanitizeFilters($filters),
            'identity_included' => $includeIdentity,
            'totals' => [
                'gross_cents' => $grossCents,
                'adjustment_delta_cents' => $adjustmentDelta,
                'net_cents' => $grossCents + $adjustmentDelta,
                'count' => count($rows),
                'by_category' => $byCategory,
                'by_campaign' => $byCampaign,
                'by_branch' => $byBranch,
            ],
            'records' => $rows,
            'policy' => [
                'donor_identity' => $includeIdentity ? 'explicitly_authorized' : 'minimized',
                'note' => 'Totals reconcile to confirmed contributions plus recorded adjustments.',
            ],
        ];
    }

    /**
     * Unauthorized export/search/dashboard path — deny and audit.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function denyUnauthorizedPath(User $actor, string $path, array $context = []): array
    {
        $this->denyAccess($actor, 'giving.unauthorized_path', array_merge([
            'path' => $path,
        ], $this->sanitizeFilters($context)));

        throw new GivingAccessException(
            'Financial values and donor identities are not available on this path.',
            'financial_redacted',
            403,
            ['path' => $path, 'redacted' => true],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function formatStatement(GivingStatement $statement): array
    {
        return [
            'id' => $statement->id,
            'reference' => $statement->reference,
            'member_id' => $statement->member_id,
            'period_from' => $statement->period_from?->toDateString(),
            'period_to' => $statement->period_to?->toDateString(),
            'total_cents' => $statement->total_cents,
            'currency' => $statement->currency,
            'line_count' => $statement->line_count,
            'totals_by_category' => $statement->totals_by_category,
            'line_items' => $statement->line_items,
            'generated_at' => $statement->generated_at?->toIso8601String(),
        ];
    }

    private function memberScopedQuery(Member $member, Carbon $from, Carbon $to): Builder
    {
        $query = Contribution::query()
            ->where('member_id', $member->id)
            ->where('payer_linked', true)
            ->whereIn('status', config('payments.member_history_statuses', [Contribution::STATUS_SUCCEEDED]))
            ->whereBetween('occurred_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);

        if (config('payments.member_history_requires_reconciled', true)) {
            $query->where('reconciliation_status', Contribution::RECON_RECONCILED);
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatMemberLine(Contribution $c): array
    {
        return [
            'id' => $c->id,
            'reference' => $c->reference,
            'amount_cents' => $c->amount_cents,
            'currency' => $c->currency,
            'category' => $c->category,
            'campaign' => $c->relationLoaded('campaign') && $c->campaign
                ? ['id' => $c->campaign->id, 'name' => $c->campaign->name, 'code' => $c->campaign->code]
                : null,
            'branch_id' => $c->branch_id,
            'occurred_at' => $c->occurred_at?->toIso8601String(),
            'payment_reference' => $c->payment_reference ?? $c->provider_payment_reference,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: Carbon, 1: Carbon}
     */
    private function parsePeriod(array $filters): array
    {
        $to = isset($filters['to']) ? Carbon::parse($filters['to']) : now();
        $from = isset($filters['from']) ? Carbon::parse($filters['from']) : $to->copy()->subYear();

        if ($from->gt($to)) {
            throw new GivingAccessException('Invalid period: from must be before to.', 'invalid_period', 422);
        }

        return [$from->startOfDay(), $to->endOfDay()];
    }

    private function assertMemberGivingEnabled(User $actor): void
    {
        if (! config('payments.member_giving_enabled', true)) {
            throw new GivingAccessException('Member giving access is disabled.', 'giving_disabled', 403);
        }

        if (! $this->authorization->allows($actor, 'payments.giving.self')) {
            $this->denyAccess($actor, 'giving.self.denied', []);
            throw new GivingAccessException('Member giving access is not enabled for this account.', 'forbidden', 403);
        }
    }

    private function requireActorMember(User $actor): Member
    {
        $member = Member::query()->where('user_id', $actor->id)->whereNull('archived_at')->first();
        if ($member === null) {
            throw new GivingAccessException('No member profile is linked to this account.', 'no_member_profile', 422);
        }

        return $member;
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function denyAccess(User $actor, string $action, array $after): void
    {
        $this->audit->record(
            actor: $actor,
            action: $action,
            category: AuditEvent::CATEGORY_SECURITY,
            module: 'payments',
            branchId: $actor->branch_id,
            after: array_merge(['decision' => 'denied'], $after),
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function sanitizeFilters(array $filters): array
    {
        $allowed = ['from', 'to', 'branch_id', 'category', 'campaign_id', 'reconciliation_status', 'status', 'limit', 'include_identity'];
        $clean = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $filters)) {
                $clean[$key] = $filters[$key];
            }
        }

        return $clean;
    }

    private function applyBranchScope(Builder $query, User $actor): void
    {
        if ($actor->isChurchWide()) {
            return;
        }

        try {
            $scope = BranchScope::for($actor);
            $query->where(function (Builder $inner) use ($scope): void {
                $inner->whereNull('branch_id')
                    ->orWhereIn('branch_id', $scope->subtreeIds((int) $scope->branchId()));
            });
        } catch (BranchScopeException) {
            $query->whereRaw('1 = 0');
        }
    }

    private function isInBranchScope(User $actor, int $branchId): bool
    {
        if ($actor->isChurchWide()) {
            return true;
        }

        try {
            return BranchScope::for($actor)->includes($branchId);
        } catch (BranchScopeException) {
            return false;
        }
    }
}
