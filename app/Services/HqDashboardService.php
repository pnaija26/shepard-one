<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\CareCase;
use App\Models\ChurchEvent;
use App\Models\Contribution;
use App\Models\FollowUp;
use App\Models\Member;
use App\Models\MemberMilestone;
use App\Models\Organization;
use App\Models\ServiceTeam;
use App\Models\ServiceTeamAssignment;
use App\Models\User;
use App\Models\Visitor;
use App\Models\VolunteerProfile;
use App\Models\WelfareRequest;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Story 12.5: church-wide HQ leadership dashboard, branch comparison, and drill-down.
 */
class HqDashboardService
{
    public function __construct(
        private AuthorizationService $authorization,
    ) {
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function dashboard(User $actor, array $filters = []): array
    {
        $this->assertCan($actor, 'hq.dashboard.read');

        [$from, $to, $prevFrom, $prevTo, $period] = $this->parsePeriod($filters);
        $branchId = $this->resolveOptionalBranchFilter($actor, $filters);

        $metrics = [];
        foreach (config('hq_dashboard.metrics', []) as $metric) {
            $metrics[$metric] = $this->buildMetric(
                $metric,
                $actor,
                $branchId,
                $from,
                $to,
                $prevFrom,
                $prevTo,
            );
        }

        $branch = $branchId !== null
            ? Organization::query()->find($branchId, ['id', 'name', 'identifier', 'type'])
            : null;

        return [
            'generated_at' => now()->toIso8601String(),
            'layout' => 'hq_leadership',
            'scope' => BranchScope::for($actor)->isChurchWide() ? 'church_wide' : 'regional',
            'branch_filter' => $branch ? [
                'id' => $branch->id,
                'name' => $branch->name,
                'identifier' => $branch->identifier,
            ] : null,
            'period' => $period,
            'definitions' => $this->metricDefinitions($actor),
            'disclosure_policy' => config('hq_dashboard.disclosure'),
            'metrics' => $metrics,
            'branch_comparison' => $this->branchComparison($actor, $from, $to, $branchId),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function drillDown(User $actor, string $metric, array $filters = []): array
    {
        $this->assertCan($actor, 'hq.dashboard.read');

        $allowed = config('hq_dashboard.metrics', []);
        if (! in_array($metric, $allowed, true)) {
            throw ValidationException::withMessages(['metric' => ['Unknown dashboard metric.']]);
        }

        $permission = config("hq_dashboard.metric_permissions.{$metric}");
        if ($permission && ! $this->allows($actor, $permission)) {
            throw new AuthorizationException('Forbidden.');
        }

        [$from, $to, $prevFrom, $prevTo] = $this->parsePeriod($filters);
        $branchId = $this->resolveOptionalBranchFilter($actor, $filters);
        $limit = min((int) ($filters['limit'] ?? config('hq_dashboard.drill_down_limit', 25)), 100);

        $records = match ($metric) {
            'members' => $this->drillMembers($actor, $branchId, $from, $to, $limit),
            'growth' => $this->drillGrowth($actor, $branchId, $from, $to, $limit),
            'attendance' => $this->drillAttendance($actor, $branchId, $from, $to, $limit),
            'visitors' => $this->drillVisitors($actor, $branchId, $from, $to, $limit),
            'converts' => $this->drillConverts($actor, $branchId, $limit),
            'baptisms' => $this->drillBaptisms($actor, $branchId, $from, $to, $limit),
            'teams' => $this->drillTeams($actor, $branchId, $limit),
            'volunteers' => $this->drillVolunteers($actor, $branchId, $limit),
            'welfare' => $this->drillWelfare($actor, $branchId, $from, $to, $limit),
            'care' => $this->drillCare($actor, $branchId, $limit),
            'events' => $this->drillEvents($actor, $branchId, $limit),
            'giving' => $this->drillGiving($actor, $branchId, $from, $to, $limit),
            'follow_up' => $this->drillFollowUps($actor, $branchId, $limit),
            'branch_performance' => $this->drillBranchPerformance($actor, $from, $to, $branchId),
            default => [],
        };

        $widget = $this->buildMetric($metric, $actor, $branchId, $from, $to, $prevFrom, $prevTo);

        return [
            'metric' => $metric,
            'branch_id' => $branchId,
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'definition' => config("hq_dashboard.metric_definitions.{$metric}"),
            'filters' => $filters,
            'widget_total' => ($widget['state'] ?? '') === 'ready' ? ($widget['total'] ?? 0) : null,
            'record_count' => count($records),
            'records' => $records,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function branchComparison(User $actor, Carbon $from, Carbon $to, ?int $branchFilter): array
    {
        if (! $this->allows($actor, 'organizations.read') || $branchFilter !== null) {
            return $this->unauthorizedMetric('branch_performance');
        }

        $branches = Organization::query()
            ->where('type', 'branch')
            ->where('is_active', true)
            ->orderBy('name');

        BranchScope::for($actor)->applyToQuery($branches);

        $rows = [];
        $suppressedCount = 0;

        foreach ($branches->get(['id', 'name', 'identifier']) as $branch) {
            $row = [
                'branch_id' => $branch->id,
                'branch_name' => $branch->name,
                'identifier' => $branch->identifier,
                'metrics' => [],
            ];

            foreach (config('hq_dashboard.branch_comparison_metrics', []) as $metric) {
                $permission = config("hq_dashboard.metric_permissions.{$metric}");
                if ($permission && ! $this->allows($actor, $permission)) {
                    $row['metrics'][$metric] = ['state' => 'unauthorized'];

                    continue;
                }

                $value = $this->branchMetricValue($metric, $actor, (int) $branch->id, $from, $to);
                $disclosure = $this->applyDisclosure($value, $metric);
                if ($disclosure['suppressed']) {
                    $suppressedCount++;
                }
                $row['metrics'][$metric] = $disclosure;
            }

            $rows[] = $row;
        }

        return [
            'state' => $rows === [] ? 'empty' : 'ready',
            'definition' => config('hq_dashboard.metric_definitions.branch_performance'),
            'branches' => $rows,
            'suppressed_cells' => $suppressedCount,
            'drill_down' => 'branch_performance',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMetric(
        string $metric,
        User $actor,
        ?int $branchId,
        Carbon $from,
        Carbon $to,
        Carbon $prevFrom,
        Carbon $prevTo,
    ): array {
        $permission = config("hq_dashboard.metric_permissions.{$metric}");
        if ($permission && ! $this->allows($actor, $permission)) {
            return $this->unauthorizedMetric($metric);
        }

        $widget = match ($metric) {
            'members' => $this->membersMetric($actor, $branchId, $from, $to, $prevFrom, $prevTo),
            'growth' => $this->growthMetric($actor, $branchId, $from, $to, $prevFrom, $prevTo),
            'attendance' => $this->attendanceMetric($actor, $branchId, $from, $to, $prevFrom, $prevTo),
            'visitors' => $this->visitorsMetric($actor, $branchId, $from, $to, $prevFrom, $prevTo),
            'converts' => $this->convertsMetric($actor, $branchId),
            'baptisms' => $this->baptismsMetric($actor, $branchId, $from, $to, $prevFrom, $prevTo),
            'teams' => $this->teamsMetric($actor, $branchId),
            'volunteers' => $this->volunteersMetric($actor, $branchId),
            'welfare' => $this->welfareMetric($actor, $branchId, $from, $to, $prevFrom, $prevTo),
            'care' => $this->careMetric($actor, $branchId),
            'events' => $this->eventsMetric($actor, $branchId),
            'giving' => $this->givingMetric($actor, $branchId, $from, $to, $prevFrom, $prevTo),
            'follow_up' => $this->followUpMetric($actor, $branchId),
            'branch_performance' => $this->branchComparison($actor, $from, $to, $branchId),
            default => $this->unauthorizedMetric($metric),
        };

        $widget['definition'] = config("hq_dashboard.metric_definitions.{$metric}");

        return $widget;
    }

    private function branchMetricValue(string $metric, User $actor, int $branchId, Carbon $from, Carbon $to): int
    {
        return match ($metric) {
            'members' => Member::query()
                ->where('branch_id', $branchId)
                ->where('lifecycle_status', 'active')
                ->whereNull('merged_into_id')
                ->count(),
            'growth' => Member::query()
                ->where('branch_id', $branchId)
                ->whereNull('merged_into_id')
                ->whereBetween('created_at', [$from, $to])
                ->count(),
            'attendance' => AttendanceRecord::query()
                ->where('branch_id', $branchId)
                ->whereBetween('gathering_date', [$from->toDateString(), $to->toDateString()])
                ->count(),
            'giving' => (int) Contribution::query()
                ->where('branch_id', $branchId)
                ->where('status', Contribution::STATUS_SUCCEEDED)
                ->where('reconciliation_status', Contribution::RECON_RECONCILED)
                ->whereBetween('occurred_at', [$from, $to])
                ->sum('amount_cents'),
            'care' => $this->careCount($actor, $branchId),
            'welfare' => WelfareRequest::query()
                ->where('branch_id', $branchId)
                ->where('status', '!=', WelfareRequest::STATUS_DRAFT)
                ->whereIn('status', [
                    WelfareRequest::STATUS_SUBMITTED,
                    WelfareRequest::STATUS_UNDER_ASSESSMENT,
                    WelfareRequest::STATUS_PENDING_APPROVAL,
                    WelfareRequest::STATUS_APPROVED,
                    WelfareRequest::STATUS_FOLLOW_UP,
                ])
                ->count(),
            default => 0,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function membersMetric(User $actor, ?int $branchId, Carbon $from, Carbon $to, Carbon $prevFrom, Carbon $prevTo): array
    {
        $query = Member::query()
            ->where('lifecycle_status', 'active')
            ->whereNull('merged_into_id');
        $this->applyDashboardBranchScope($query, $actor, $branchId);
        $active = $query->count();

        $newCurrent = Member::query()->whereNull('merged_into_id');
        $this->applyDashboardBranchScope($newCurrent, $actor, $branchId);
        $newCurrent->whereBetween('created_at', [$from, $to]);
        $newInPeriod = $newCurrent->count();

        $newPrevious = Member::query()->whereNull('merged_into_id');
        $this->applyDashboardBranchScope($newPrevious, $actor, $branchId);
        $newPrevious->whereBetween('created_at', [$prevFrom, $prevTo]);
        $prevNew = $newPrevious->count();

        return $this->metricReady('members', [
            'active_members' => $active,
            'new_in_period' => $newInPeriod,
        ], $active, $prevNew, now());
    }

    /**
     * @return array<string, mixed>
     */
    private function growthMetric(User $actor, ?int $branchId, Carbon $from, Carbon $to, Carbon $prevFrom, Carbon $prevTo): array
    {
        $currentQuery = Member::query()->whereNull('merged_into_id');
        $this->applyDashboardBranchScope($currentQuery, $actor, $branchId);
        $current = (clone $currentQuery)->whereBetween('created_at', [$from, $to])->count();

        $previousQuery = Member::query()->whereNull('merged_into_id');
        $this->applyDashboardBranchScope($previousQuery, $actor, $branchId);
        $previous = (clone $previousQuery)->whereBetween('created_at', [$prevFrom, $prevTo])->count();

        return $this->metricReady('growth', ['new_members' => $current], $current, $previous, now());
    }

    /**
     * @return array<string, mixed>
     */
    private function attendanceMetric(User $actor, ?int $branchId, Carbon $from, Carbon $to, Carbon $prevFrom, Carbon $prevTo): array
    {
        $currentQuery = AttendanceRecord::query();
        $this->applyDashboardBranchScope($currentQuery, $actor, $branchId);
        $current = (clone $currentQuery)
            ->whereBetween('gathering_date', [$from->toDateString(), $to->toDateString()])
            ->count();

        $previousQuery = AttendanceRecord::query();
        $this->applyDashboardBranchScope($previousQuery, $actor, $branchId);
        $previous = (clone $previousQuery)
            ->whereBetween('gathering_date', [$prevFrom->toDateString(), $prevTo->toDateString()])
            ->count();

        $latestQuery = AttendanceRecord::query();
        $this->applyDashboardBranchScope($latestQuery, $actor, $branchId);
        $latest = $latestQuery->orderByDesc('gathering_date')->value('gathering_date');

        $staleDays = (int) config('hq_dashboard.stale_thresholds.attendance_days', 14);
        $freshness = $latest === null || Carbon::parse($latest)->lt(now()->subDays($staleDays))
            ? 'stale'
            : 'current';

        return $this->metricReady('attendance', ['records_in_period' => $current], $current, $previous, $latest ? Carbon::parse($latest) : now(), $freshness);
    }

    /**
     * @return array<string, mixed>
     */
    private function visitorsMetric(User $actor, ?int $branchId, Carbon $from, Carbon $to, Carbon $prevFrom, Carbon $prevTo): array
    {
        $current = $this->visitorPeriodQuery($actor, $branchId, $from, $to)->count();
        $previous = $this->visitorPeriodQuery($actor, $branchId, $prevFrom, $prevTo)->count();

        return $this->metricReady('visitors', ['period_visitors' => $current], $current, $previous, now());
    }

    /**
     * @return array<string, mixed>
     */
    private function convertsMetric(User $actor, ?int $branchId): array
    {
        $query = Member::query()
            ->where('lifecycle_stage', 'convert')
            ->where('lifecycle_status', 'active')
            ->whereNull('merged_into_id');
        $this->applyDashboardBranchScope($query, $actor, $branchId);
        $total = $query->count();

        return $this->metricReady('converts', ['active_converts' => $total], $total, $total, now());
    }

    /**
     * @return array<string, mixed>
     */
    private function baptismsMetric(User $actor, ?int $branchId, Carbon $from, Carbon $to, Carbon $prevFrom, Carbon $prevTo): array
    {
        $currentQuery = MemberMilestone::query()
            ->where('type', 'baptism')
            ->where('active', true)
            ->whereBetween('occurred_on', [$from->toDateString(), $to->toDateString()])
            ->whereHas('member', function (Builder $member) use ($actor, $branchId): void {
                $member->whereNull('merged_into_id');
                $this->applyDashboardBranchScope($member, $actor, $branchId);
            });
        $current = $currentQuery->count();

        $previousQuery = MemberMilestone::query()
            ->where('type', 'baptism')
            ->where('active', true)
            ->whereBetween('occurred_on', [$prevFrom->toDateString(), $prevTo->toDateString()])
            ->whereHas('member', function (Builder $member) use ($actor, $branchId): void {
                $member->whereNull('merged_into_id');
                $this->applyDashboardBranchScope($member, $actor, $branchId);
            });
        $previous = $previousQuery->count();

        return $this->metricReady('baptisms', ['baptisms_in_period' => $current], $current, $previous, now());
    }

    /**
     * @return array<string, mixed>
     */
    private function teamsMetric(User $actor, ?int $branchId): array
    {
        $teamsQuery = ServiceTeam::query()->where('status', ServiceTeam::STATUS_ACTIVE);
        $this->applyDashboardBranchScope($teamsQuery, $actor, $branchId);
        $activeTeams = $teamsQuery->count();

        $assignmentsQuery = ServiceTeamAssignment::query()
            ->where('status', ServiceTeamAssignment::STATUS_ACTIVE)
            ->whereHas('team', function (Builder $team) use ($actor, $branchId): void {
                $team->where('status', ServiceTeam::STATUS_ACTIVE);
                $this->applyDashboardBranchScope($team, $actor, $branchId);
            });
        $activeAssignments = $assignmentsQuery->count();

        return $this->metricReady('teams', [
            'active_teams' => $activeTeams,
            'active_assignments' => $activeAssignments,
        ], $activeTeams, $activeTeams, now());
    }

    /**
     * @return array<string, mixed>
     */
    private function volunteersMetric(User $actor, ?int $branchId): array
    {
        $volunteersQuery = VolunteerProfile::query()->where('status', VolunteerProfile::STATUS_ACTIVE);
        $this->applyDashboardBranchScope($volunteersQuery, $actor, $branchId);
        $active = $volunteersQuery->count();

        $membersQuery = Member::query()
            ->where('lifecycle_status', 'active')
            ->whereNull('merged_into_id');
        $this->applyDashboardBranchScope($membersQuery, $actor, $branchId);
        $memberCount = $membersQuery->count();

        $rate = $memberCount > 0 ? round(($active / $memberCount) * 100, 1) : 0.0;

        return $this->metricReady('volunteers', [
            'active_profiles' => $active,
            'participation_rate_percent' => $rate,
        ], $active, $active, now());
    }

    /**
     * @return array<string, mixed>
     */
    private function welfareMetric(User $actor, ?int $branchId, Carbon $from, Carbon $to, Carbon $prevFrom, Carbon $prevTo): array
    {
        $openStatuses = [
            WelfareRequest::STATUS_SUBMITTED,
            WelfareRequest::STATUS_UNDER_ASSESSMENT,
            WelfareRequest::STATUS_PENDING_APPROVAL,
            WelfareRequest::STATUS_APPROVED,
            WelfareRequest::STATUS_FOLLOW_UP,
        ];

        $openQuery = WelfareRequest::query()->whereIn('status', $openStatuses);
        $this->applyDashboardBranchScope($openQuery, $actor, $branchId);
        $open = $openQuery->count();

        $submittedQuery = WelfareRequest::query()
            ->where('status', '!=', WelfareRequest::STATUS_DRAFT)
            ->whereBetween('submitted_at', [$from, $to]);
        $this->applyDashboardBranchScope($submittedQuery, $actor, $branchId);
        $submittedCurrent = $submittedQuery->count();

        $submittedPrevQuery = WelfareRequest::query()
            ->where('status', '!=', WelfareRequest::STATUS_DRAFT)
            ->whereBetween('submitted_at', [$prevFrom, $prevTo]);
        $this->applyDashboardBranchScope($submittedPrevQuery, $actor, $branchId);
        $submittedPrevious = $submittedPrevQuery->count();

        $disclosure = $this->applyDisclosure($open, 'welfare');

        return array_merge(
            $this->metricReady('welfare', [
                'open_cases' => $disclosure['suppressed'] ? null : $open,
                'submitted_in_period' => $submittedCurrent,
                'suppressed' => $disclosure['suppressed'],
            ], $disclosure['suppressed'] ? 0 : $open, $submittedPrevious, now()),
            ['disclosure' => $disclosure],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function careMetric(User $actor, ?int $branchId): array
    {
        $open = $this->careCount($actor, $branchId);
        $disclosure = $this->applyDisclosure($open, 'care');

        return array_merge(
            $this->metricReady('care', [
                'open_cases' => $disclosure['suppressed'] ? null : $open,
                'suppressed' => $disclosure['suppressed'],
            ], $disclosure['suppressed'] ? 0 : $open, $open, now()),
            ['disclosure' => $disclosure],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function eventsMetric(User $actor, ?int $branchId): array
    {
        $windowEnd = now()->addDays((int) config('hq_dashboard.upcoming_event_window_days', 30))->toDateString();

        $query = ChurchEvent::query()
            ->where('status', ChurchEvent::STATUS_PUBLISHED)
            ->where('event_date', '>=', now()->toDateString())
            ->where('event_date', '<=', $windowEnd);
        $this->applyDashboardBranchScope($query, $actor, $branchId);
        $upcoming = $query->count();

        return $this->metricReady('events', ['upcoming_events' => $upcoming], $upcoming, $upcoming, now());
    }

    /**
     * @return array<string, mixed>
     */
    private function givingMetric(User $actor, ?int $branchId, Carbon $from, Carbon $to, Carbon $prevFrom, Carbon $prevTo): array
    {
        $currentQuery = Contribution::query()
            ->where('status', Contribution::STATUS_SUCCEEDED)
            ->where('reconciliation_status', Contribution::RECON_RECONCILED)
            ->whereBetween('occurred_at', [$from, $to]);
        $this->applyDashboardBranchScope($currentQuery, $actor, $branchId);
        $currentCents = (int) $currentQuery->sum('amount_cents');

        $previousQuery = Contribution::query()
            ->where('status', Contribution::STATUS_SUCCEEDED)
            ->where('reconciliation_status', Contribution::RECON_RECONCILED)
            ->whereBetween('occurred_at', [$prevFrom, $prevTo]);
        $this->applyDashboardBranchScope($previousQuery, $actor, $branchId);
        $previousCents = (int) $previousQuery->sum('amount_cents');

        $latestQuery = Contribution::query()->where('status', Contribution::STATUS_SUCCEEDED);
        $this->applyDashboardBranchScope($latestQuery, $actor, $branchId);
        $latest = $latestQuery->orderByDesc('occurred_at')->value('occurred_at');

        $staleDays = (int) config('hq_dashboard.stale_thresholds.giving_days', 7);
        $freshness = $latest === null || Carbon::parse($latest)->lt(now()->subDays($staleDays))
            ? 'stale'
            : 'current';

        return $this->metricReady('giving', [
            'total_cents' => $currentCents,
            'currency' => 'USD',
            'identity_minimized' => true,
        ], $currentCents, $previousCents, $latest ? Carbon::parse($latest) : now(), $freshness);
    }

    /**
     * @return array<string, mixed>
     */
    private function followUpMetric(User $actor, ?int $branchId): array
    {
        $openStatuses = [FollowUp::STATUS_ASSIGNED, FollowUp::STATUS_IN_PROGRESS, FollowUp::STATUS_ESCALATED];

        $openQuery = FollowUp::query()->whereIn('status', $openStatuses);
        $this->applyDashboardBranchScope($openQuery, $actor, $branchId);
        $open = $openQuery->count();

        $overdueQuery = FollowUp::query()
            ->whereIn('status', $openStatuses)
            ->whereDate('due_date', '<', now()->toDateString());
        $this->applyDashboardBranchScope($overdueQuery, $actor, $branchId);
        $overdue = $overdueQuery->count();

        return $this->metricReady('follow_up', [
            'open_assignments' => $open,
            'overdue' => $overdue,
        ], $open, $open, now());
    }

    private function careCount(User $actor, ?int $branchId): int
    {
        $openStatuses = [
            CareCase::STATUS_OPEN,
            CareCase::STATUS_ASSIGNED,
            CareCase::STATUS_IN_PROGRESS,
            CareCase::STATUS_ESCALATED,
        ];

        $query = CareCase::query()->whereIn('status', $openStatuses);
        $this->applyDashboardBranchScope($query, $actor, $branchId);
        $this->applyCareVisibilityFilter($query, $actor);

        return $query->count();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function drillMembers(User $actor, ?int $branchId, Carbon $from, Carbon $to, int $limit): array
    {
        $query = Member::query()->whereNull('merged_into_id')->whereBetween('created_at', [$from, $to]);
        $this->applyDashboardBranchScope($query, $actor, $branchId);

        return $query->with('branch:id,name')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'membership_id', 'branch_id', 'first_name', 'last_name', 'lifecycle_stage', 'created_at'])
            ->map(fn (Member $member) => [
                'id' => $member->id,
                'membership_id' => $member->membership_id,
                'branch_name' => $member->branch?->name,
                'name' => trim($member->first_name . ' ' . $member->last_name),
                'lifecycle_stage' => $member->lifecycle_stage,
                'registered_at' => $member->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function drillGrowth(User $actor, ?int $branchId, Carbon $from, Carbon $to, int $limit): array
    {
        return $this->drillMembers($actor, $branchId, $from, $to, $limit);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function drillAttendance(User $actor, ?int $branchId, Carbon $from, Carbon $to, int $limit): array
    {
        $query = AttendanceRecord::query()
            ->whereBetween('gathering_date', [$from->toDateString(), $to->toDateString()]);
        $this->applyDashboardBranchScope($query, $actor, $branchId);

        return $query->with('branch:id,name')
            ->orderByDesc('gathering_date')
            ->limit($limit)
            ->get(['id', 'branch_id', 'gathering_date', 'status', 'service_type'])
            ->map(fn (AttendanceRecord $record) => [
                'id' => $record->id,
                'branch_name' => $record->branch?->name,
                'gathering_date' => $record->gathering_date?->toDateString(),
                'status' => $record->status,
                'service_type' => $record->service_type,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function drillVisitors(User $actor, ?int $branchId, Carbon $from, Carbon $to, int $limit): array
    {
        return $this->visitorPeriodQuery($actor, $branchId, $from, $to)
            ->with('branch:id,name')
            ->orderByDesc('first_visit_at')
            ->limit($limit)
            ->get(['id', 'branch_id', 'first_name', 'last_name', 'first_visit_at', 'original_source'])
            ->map(fn (Visitor $visitor) => [
                'id' => $visitor->id,
                'branch_name' => $visitor->branch?->name,
                'name' => $visitor->fullName(),
                'first_visit_at' => $visitor->first_visit_at?->toIso8601String(),
                'source' => $visitor->original_source,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function drillConverts(User $actor, ?int $branchId, int $limit): array
    {
        $query = Member::query()
            ->where('lifecycle_stage', 'convert')
            ->where('lifecycle_status', 'active')
            ->whereNull('merged_into_id');
        $this->applyDashboardBranchScope($query, $actor, $branchId);

        return $query->with('branch:id,name')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get(['id', 'branch_id', 'membership_id', 'first_name', 'last_name', 'updated_at'])
            ->map(fn (Member $member) => [
                'id' => $member->id,
                'branch_name' => $member->branch?->name,
                'membership_id' => $member->membership_id,
                'name' => trim($member->first_name . ' ' . $member->last_name),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function drillBaptisms(User $actor, ?int $branchId, Carbon $from, Carbon $to, int $limit): array
    {
        return MemberMilestone::query()
            ->where('type', 'baptism')
            ->where('active', true)
            ->whereBetween('occurred_on', [$from->toDateString(), $to->toDateString()])
            ->whereHas('member', function (Builder $member) use ($actor, $branchId): void {
                $member->whereNull('merged_into_id');
                $this->applyDashboardBranchScope($member, $actor, $branchId);
            })
            ->with(['member:id,branch_id,first_name,last_name,membership_id', 'member.branch:id,name'])
            ->orderByDesc('occurred_on')
            ->limit($limit)
            ->get()
            ->map(fn (MemberMilestone $milestone) => [
                'id' => $milestone->id,
                'member_id' => $milestone->member_id,
                'branch_name' => $milestone->member?->branch?->name,
                'member_name' => $milestone->member
                    ? trim($milestone->member->first_name . ' ' . $milestone->member->last_name)
                    : null,
                'occurred_on' => $milestone->occurred_on?->toDateString(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function drillTeams(User $actor, ?int $branchId, int $limit): array
    {
        $query = ServiceTeam::query()->where('status', ServiceTeam::STATUS_ACTIVE);
        $this->applyDashboardBranchScope($query, $actor, $branchId);

        return $query->with('branch:id,name')
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'branch_id', 'name', 'category', 'status'])
            ->map(fn (ServiceTeam $team) => [
                'id' => $team->id,
                'branch_name' => $team->branch?->name,
                'name' => $team->name,
                'category' => $team->category,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function drillVolunteers(User $actor, ?int $branchId, int $limit): array
    {
        $query = VolunteerProfile::query()->where('status', VolunteerProfile::STATUS_ACTIVE);
        $this->applyDashboardBranchScope($query, $actor, $branchId);

        return $query->with(['member:id,first_name,last_name', 'branch:id,name'])
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (VolunteerProfile $profile) => [
                'id' => $profile->id,
                'branch_name' => $profile->branch?->name,
                'member_name' => $profile->member
                    ? trim($profile->member->first_name . ' ' . $profile->member->last_name)
                    : null,
                'status' => $profile->status,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function drillWelfare(User $actor, ?int $branchId, Carbon $from, Carbon $to, int $limit): array
    {
        $query = WelfareRequest::query()
            ->where('status', '!=', WelfareRequest::STATUS_DRAFT)
            ->whereBetween('submitted_at', [$from, $to]);
        $this->applyDashboardBranchScope($query, $actor, $branchId);

        return $query->with('branch:id,name')
            ->orderByDesc('submitted_at')
            ->limit($limit)
            ->get(['id', 'branch_id', 'case_number', 'request_type', 'status', 'submitted_at'])
            ->map(fn (WelfareRequest $case) => [
                'id' => $case->id,
                'branch_name' => $case->branch?->name,
                'case_number' => $case->case_number,
                'request_type' => $case->request_type,
                'status' => $case->status,
                'identity_minimized' => true,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function drillCare(User $actor, ?int $branchId, int $limit): array
    {
        $openStatuses = [
            CareCase::STATUS_OPEN,
            CareCase::STATUS_ASSIGNED,
            CareCase::STATUS_IN_PROGRESS,
            CareCase::STATUS_ESCALATED,
        ];

        $query = CareCase::query()->whereIn('status', $openStatuses);
        $this->applyDashboardBranchScope($query, $actor, $branchId);
        $this->applyCareVisibilityFilter($query, $actor);

        return $query->with('branch:id,name')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'branch_id', 'case_number', 'category', 'status', 'priority'])
            ->map(fn (CareCase $case) => [
                'id' => $case->id,
                'branch_name' => $case->branch?->name,
                'case_number' => $case->case_number,
                'category' => $case->category,
                'status' => $case->status,
                'sensitive_details_omitted' => true,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function drillEvents(User $actor, ?int $branchId, int $limit): array
    {
        $windowEnd = now()->addDays((int) config('hq_dashboard.upcoming_event_window_days', 30))->toDateString();

        $query = ChurchEvent::query()
            ->where('status', ChurchEvent::STATUS_PUBLISHED)
            ->where('event_date', '>=', now()->toDateString())
            ->where('event_date', '<=', $windowEnd);
        $this->applyDashboardBranchScope($query, $actor, $branchId);

        return $query->with('branch:id,name')
            ->orderBy('event_date')
            ->limit($limit)
            ->get(['id', 'branch_id', 'title', 'event_date', 'venue'])
            ->map(fn (ChurchEvent $event) => [
                'id' => $event->id,
                'branch_name' => $event->branch?->name,
                'title' => $event->title,
                'event_date' => $event->event_date?->toDateString(),
                'venue' => $event->venue,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function drillGiving(User $actor, ?int $branchId, Carbon $from, Carbon $to, int $limit): array
    {
        $query = Contribution::query()
            ->where('status', Contribution::STATUS_SUCCEEDED)
            ->where('reconciliation_status', Contribution::RECON_RECONCILED)
            ->whereBetween('occurred_at', [$from, $to]);
        $this->applyDashboardBranchScope($query, $actor, $branchId);

        return $query->with('branch:id,name')
            ->orderByDesc('occurred_at')
            ->limit($limit)
            ->get(['id', 'branch_id', 'reference', 'amount_cents', 'currency', 'category', 'occurred_at'])
            ->map(fn (Contribution $contribution) => [
                'id' => $contribution->id,
                'branch_name' => $contribution->branch?->name,
                'reference' => $contribution->reference,
                'amount_cents' => $contribution->amount_cents,
                'category' => $contribution->category,
                'donor_identity_omitted' => true,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function drillFollowUps(User $actor, ?int $branchId, int $limit): array
    {
        $openStatuses = [FollowUp::STATUS_ASSIGNED, FollowUp::STATUS_IN_PROGRESS, FollowUp::STATUS_ESCALATED];

        $query = FollowUp::query()->whereIn('status', $openStatuses);
        $this->applyDashboardBranchScope($query, $actor, $branchId);

        return $query->with('branch:id,name')
            ->orderBy('due_date')
            ->limit($limit)
            ->get(['id', 'branch_id', 'reason', 'status', 'priority', 'due_date', 'is_restricted'])
            ->map(fn (FollowUp $followUp) => [
                'id' => $followUp->id,
                'branch_name' => $followUp->branch?->name,
                'reason' => $followUp->is_restricted ? 'Restricted follow-up' : $followUp->reason,
                'status' => $followUp->status,
                'due_date' => $followUp->due_date?->toDateString(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function drillBranchPerformance(User $actor, Carbon $from, Carbon $to, ?int $branchFilter): array
    {
        $comparison = $this->branchComparison($actor, $from, $to, $branchFilter);

        return $comparison['branches'] ?? [];
    }

    /**
     * @return array<string, string>
     */
    private function metricDefinitions(User $actor): array
    {
        $definitions = [];
        foreach (config('hq_dashboard.metric_definitions', []) as $key => $definition) {
            $permission = config("hq_dashboard.metric_permissions.{$key}");
            if ($permission && ! $this->allows($actor, $permission)) {
                continue;
            }
            $definitions[$key] = $definition;
        }

        return $definitions;
    }

    /**
     * @return array{value: int|null, suppressed: bool, reason?: string}
     */
    private function applyDisclosure(int $value, string $metric): array
    {
        $min = (int) config('hq_dashboard.disclosure.min_cohort_size', 5);
        $sensitive = in_array($metric, config('hq_dashboard.disclosure.sensitive_metrics', []), true);

        if ($sensitive && $value > 0 && $value < $min) {
            return [
                'value' => null,
                'suppressed' => true,
                'reason' => 'small_group_suppression',
            ];
        }

        return [
            'value' => $value,
            'suppressed' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function resolveOptionalBranchFilter(User $actor, array $filters): ?int
    {
        if (empty($filters['branch_id'])) {
            return null;
        }

        $branchId = (int) $filters['branch_id'];
        $branch = Organization::query()->findOrFail($branchId);

        if ($branch->type !== 'branch') {
            throw ValidationException::withMessages([
                'branch_id' => ['Branch filter must reference a branch organization.'],
            ]);
        }

        BranchScope::for($actor)->assertIncludes($branch);

        return $branchId;
    }

    private function visitorPeriodQuery(User $actor, ?int $branchId, Carbon $from, Carbon $to): Builder
    {
        $query = Visitor::query()->where(function (Builder $inner) use ($from, $to): void {
            $inner->whereBetween('first_visit_at', [$from, $to])
                ->orWhere(function (Builder $fallback) use ($from, $to): void {
                    $fallback->whereNull('first_visit_at')
                        ->whereBetween('created_at', [$from, $to]);
                });
        });
        $this->applyDashboardBranchScope($query, $actor, $branchId);

        return $query;
    }

    private function applyDashboardBranchScope(Builder $query, User $actor, ?int $branchId, string $column = 'branch_id'): void
    {
        if ($branchId !== null) {
            $query->where($column, $branchId);

            return;
        }

        $ids = $this->scopedBranchIds($actor);
        $query->whereIn($column, $ids ?: [0]);
    }

    /**
     * @return list<int>
     */
    private function scopedBranchIds(User $actor): array
    {
        $query = Organization::query()
            ->where('type', 'branch')
            ->where('is_active', true);

        BranchScope::for($actor)->applyToQuery($query);

        return $query->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function applyCareVisibilityFilter(Builder $query, User $actor): void
    {
        if ($this->allows($actor, 'care.cases.sensitive.read')) {
            $query->where(function (Builder $inner) use ($actor): void {
                $inner->where('confidentiality', '!=', 'assigned_only')
                    ->orWhere('assigned_officer_id', $actor->id)
                    ->orWhere('created_by', $actor->id);
            });

            return;
        }

        $query->where(function (Builder $inner) use ($actor): void {
            $inner->where('assigned_officer_id', $actor->id)
                ->orWhere('created_by', $actor->id);
        });
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: Carbon, 3: Carbon, 4: array<string, mixed>}
     */
    private function parsePeriod(array $filters): array
    {
        $days = (int) config('hq_dashboard.default_period_days', 30);
        $to = ! empty($filters['period_to'])
            ? Carbon::parse($filters['period_to'])->endOfDay()
            : now()->endOfDay();
        $from = ! empty($filters['period_from'])
            ? Carbon::parse($filters['period_from'])->startOfDay()
            : $to->copy()->subDays($days - 1)->startOfDay();

        $span = max(1, $from->diffInDays($to) + 1);
        $prevTo = $from->copy()->subDay()->endOfDay();
        $prevFrom = $prevTo->copy()->subDays($span - 1)->startOfDay();

        return [
            $from,
            $to,
            $prevFrom,
            $prevTo,
            [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'previous_from' => $prevFrom->toDateString(),
                'previous_to' => $prevTo->toDateString(),
                'days' => $span,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function metricReady(
        string $metric,
        array $summary,
        int $total,
        int $previousTotal,
        Carbon $dataAsOf,
        string $freshness = 'current',
    ): array {
        return [
            'state' => $total === 0 && $previousTotal === 0 ? 'empty' : 'ready',
            'total' => $total,
            'previous_total' => $previousTotal,
            'trend' => $this->trend($total, $previousTotal),
            'data_as_of' => $dataAsOf->toIso8601String(),
            'freshness' => $freshness,
            'drill_down' => $metric,
            'summary' => $summary,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function unauthorizedMetric(string $metric): array
    {
        return [
            'state' => 'unauthorized',
            'drill_down' => $metric,
        ];
    }

    private function trend(int $current, int $previous): string
    {
        if ($current > $previous) {
            return 'up';
        }

        if ($current < $previous) {
            return 'down';
        }

        return 'flat';
    }

    private function allows(User $actor, string $action): bool
    {
        return $this->authorization->allows($actor, $action);
    }

    private function assertCan(User $actor, string $action): void
    {
        if (! $this->allows($actor, $action)) {
            throw new AuthorizationException('Forbidden.');
        }
    }
}
