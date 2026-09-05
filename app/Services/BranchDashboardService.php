<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\CareCase;
use App\Models\ChurchEvent;
use App\Models\Contribution;
use App\Models\FollowUp;
use App\Models\Member;
use App\Models\Organization;
use App\Models\ServiceTeam;
use App\Models\User;
use App\Models\Visitor;
use App\Models\VolunteerProfile;
use App\Models\WelfareRequest;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Story 12.4: permission-scoped branch administrator dashboard and drill-down.
 */
class BranchDashboardService
{
    public function __construct(
        private AuthorizationService $authorization,
    ) {
    }

    /**
     * @return Collection<int, Organization>
     */
    public function listAccessibleBranches(User $actor): Collection
    {
        $this->assertCan($actor, 'branch.dashboard.read');

        $query = Organization::query()
            ->where('type', 'branch')
            ->where('is_active', true)
            ->orderBy('name');

        BranchScope::for($actor)->applyToQuery($query);

        return $query->limit(100)->get(['id', 'name', 'identifier', 'type']);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function dashboard(User $actor, Organization $branch, array $filters = []): array
    {
        $this->assertCan($actor, 'branch.dashboard.read');
        $this->assertBranchTarget($branch);
        BranchScope::for($actor)->assertIncludes($branch);

        [$from, $to, $prevFrom, $prevTo, $period] = $this->parsePeriod($filters);
        $branchId = (int) $branch->id;

        $metrics = [];
        foreach (config('branch_dashboard.metrics', []) as $metric) {
            $metrics[$metric] = $this->buildMetric($metric, $actor, $branchId, $from, $to, $prevFrom, $prevTo);
        }

        return [
            'branch' => [
                'id' => $branch->id,
                'name' => $branch->name,
                'identifier' => $branch->identifier,
                'type' => $branch->type,
            ],
            'generated_at' => now()->toIso8601String(),
            'layout' => 'branch_administrator',
            'period' => $period,
            'metrics' => $metrics,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function drillDown(User $actor, Organization $branch, string $metric, array $filters = []): array
    {
        $this->assertCan($actor, 'branch.dashboard.read');
        $this->assertBranchTarget($branch);
        BranchScope::for($actor)->assertIncludes($branch);

        $allowed = config('branch_dashboard.metrics', []);
        if (! in_array($metric, $allowed, true)) {
            throw ValidationException::withMessages(['metric' => ['Unknown dashboard metric.']]);
        }

        $permission = config("branch_dashboard.metric_permissions.{$metric}");
        if ($permission && ! $this->allows($actor, $permission)) {
            throw new AuthorizationException('Forbidden.');
        }

        [$from, $to, $prevFrom, $prevTo] = $this->parsePeriod($filters);
        $branchId = (int) $branch->id;
        $limit = min((int) ($filters['limit'] ?? config('branch_dashboard.drill_down_limit', 25)), 100);

        $records = match ($metric) {
            'members' => $this->drillMembers($branchId, $from, $to, $limit),
            'visitors' => $this->drillVisitors($branchId, $from, $to, $limit),
            'converts' => $this->drillConverts($branchId, $limit),
            'attendance' => $this->drillAttendance($branchId, $from, $to, $limit),
            'teams' => $this->drillTeams($branchId, $limit),
            'volunteers' => $this->drillVolunteers($branchId, $limit),
            'welfare' => $this->drillWelfare($branchId, $from, $to, $limit),
            'care' => $this->drillCare($actor, $branchId, $limit),
            'events' => $this->drillEvents($branchId, $limit),
            'giving' => $this->drillGiving($branchId, $from, $to, $limit),
            'growth' => $this->drillGrowth($branchId, $from, $to, $limit),
            'follow_up' => $this->drillFollowUps($branchId, $limit),
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
            'filters' => $filters,
            'widget_total' => ($widget['state'] ?? '') === 'ready' ? ($widget['total'] ?? 0) : null,
            'record_count' => count($records),
            'records' => $records,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMetric(
        string $metric,
        User $actor,
        int $branchId,
        Carbon $from,
        Carbon $to,
        Carbon $prevFrom,
        Carbon $prevTo,
    ): array {
        $permission = config("branch_dashboard.metric_permissions.{$metric}");
        if ($permission && ! $this->allows($actor, $permission)) {
            return $this->unauthorizedMetric($metric);
        }

        return match ($metric) {
            'members' => $this->membersMetric($branchId, $from, $to, $prevFrom, $prevTo),
            'visitors' => $this->visitorsMetric($branchId, $from, $to, $prevFrom, $prevTo),
            'converts' => $this->convertsMetric($branchId),
            'attendance' => $this->attendanceMetric($branchId, $from, $to, $prevFrom, $prevTo),
            'teams' => $this->teamsMetric($branchId),
            'volunteers' => $this->volunteersMetric($branchId),
            'welfare' => $this->welfareMetric($branchId, $from, $to, $prevFrom, $prevTo),
            'care' => $this->careMetric($actor, $branchId),
            'events' => $this->eventsMetric($branchId),
            'giving' => $this->givingMetric($branchId, $from, $to, $prevFrom, $prevTo),
            'growth' => $this->growthMetric($branchId, $from, $to, $prevFrom, $prevTo),
            'follow_up' => $this->followUpMetric($branchId),
            default => $this->unauthorizedMetric($metric),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function membersMetric(int $branchId, Carbon $from, Carbon $to, Carbon $prevFrom, Carbon $prevTo): array
    {
        $active = Member::query()
            ->where('branch_id', $branchId)
            ->where('lifecycle_status', 'active')
            ->whereNull('merged_into_id')
            ->count();

        $newCurrent = Member::query()
            ->where('branch_id', $branchId)
            ->whereNull('merged_into_id')
            ->whereBetween('created_at', [$from, $to])
            ->count();

        $newPrevious = Member::query()
            ->where('branch_id', $branchId)
            ->whereNull('merged_into_id')
            ->whereBetween('created_at', [$prevFrom, $prevTo])
            ->count();

        return $this->metricReady('members', [
            'active_members' => $active,
            'new_in_period' => $newCurrent,
        ], $active, $newPrevious, now());
    }

    /**
     * @return array<string, mixed>
     */
    private function visitorsMetric(int $branchId, Carbon $from, Carbon $to, Carbon $prevFrom, Carbon $prevTo): array
    {
        $current = $this->visitorPeriodQuery($branchId, $from, $to)->count();
        $previous = $this->visitorPeriodQuery($branchId, $prevFrom, $prevTo)->count();
        $latest = Visitor::query()
            ->where('branch_id', $branchId)
            ->orderByDesc('first_visit_at')
            ->value('first_visit_at');

        return $this->metricReady('visitors', [
            'period_visitors' => $current,
        ], $current, $previous, $latest ? Carbon::parse($latest) : now());
    }

    /**
     * @return array<string, mixed>
     */
    private function convertsMetric(int $branchId): array
    {
        $total = Member::query()
            ->where('branch_id', $branchId)
            ->where('lifecycle_stage', 'convert')
            ->where('lifecycle_status', 'active')
            ->whereNull('merged_into_id')
            ->count();

        return $this->metricReady('converts', [
            'active_converts' => $total,
        ], $total, $total, now());
    }

    /**
     * @return array<string, mixed>
     */
    private function attendanceMetric(int $branchId, Carbon $from, Carbon $to, Carbon $prevFrom, Carbon $prevTo): array
    {
        $current = AttendanceRecord::query()
            ->where('branch_id', $branchId)
            ->whereBetween('gathering_date', [$from->toDateString(), $to->toDateString()])
            ->count();

        $previous = AttendanceRecord::query()
            ->where('branch_id', $branchId)
            ->whereBetween('gathering_date', [$prevFrom->toDateString(), $prevTo->toDateString()])
            ->count();

        $latest = AttendanceRecord::query()
            ->where('branch_id', $branchId)
            ->orderByDesc('gathering_date')
            ->value('gathering_date');

        $staleDays = (int) config('branch_dashboard.stale_thresholds.attendance_days', 14);
        $freshness = $latest === null || Carbon::parse($latest)->lt(now()->subDays($staleDays))
            ? 'stale'
            : 'current';

        return $this->metricReady('attendance', [
            'records_in_period' => $current,
        ], $current, $previous, $latest ? Carbon::parse($latest) : now(), $freshness);
    }

    /**
     * @return array<string, mixed>
     */
    private function teamsMetric(int $branchId): array
    {
        $active = ServiceTeam::query()
            ->where('branch_id', $branchId)
            ->where('status', ServiceTeam::STATUS_ACTIVE)
            ->count();

        return $this->metricReady('teams', [
            'active_teams' => $active,
        ], $active, $active, now());
    }

    /**
     * @return array<string, mixed>
     */
    private function volunteersMetric(int $branchId): array
    {
        $active = VolunteerProfile::query()
            ->where('branch_id', $branchId)
            ->where('status', VolunteerProfile::STATUS_ACTIVE)
            ->count();

        return $this->metricReady('volunteers', [
            'active_profiles' => $active,
        ], $active, $active, now());
    }

    /**
     * @return array<string, mixed>
     */
    private function welfareMetric(int $branchId, Carbon $from, Carbon $to, Carbon $prevFrom, Carbon $prevTo): array
    {
        $openStatuses = [
            WelfareRequest::STATUS_SUBMITTED,
            WelfareRequest::STATUS_UNDER_ASSESSMENT,
            WelfareRequest::STATUS_RETURNED_FOR_INFO,
            WelfareRequest::STATUS_PENDING_REVIEW,
            WelfareRequest::STATUS_PENDING_APPROVAL,
            WelfareRequest::STATUS_APPROVED,
            WelfareRequest::STATUS_ESCALATED,
            WelfareRequest::STATUS_DISBURSED,
            WelfareRequest::STATUS_FOLLOW_UP,
        ];

        $open = WelfareRequest::query()
            ->where('branch_id', $branchId)
            ->whereIn('status', $openStatuses)
            ->count();

        $submittedCurrent = WelfareRequest::query()
            ->where('branch_id', $branchId)
            ->where('status', '!=', WelfareRequest::STATUS_DRAFT)
            ->whereBetween('submitted_at', [$from, $to])
            ->count();

        $submittedPrevious = WelfareRequest::query()
            ->where('branch_id', $branchId)
            ->where('status', '!=', WelfareRequest::STATUS_DRAFT)
            ->whereBetween('submitted_at', [$prevFrom, $prevTo])
            ->count();

        return $this->metricReady('welfare', [
            'open_cases' => $open,
            'submitted_in_period' => $submittedCurrent,
        ], $open, $submittedPrevious, now());
    }

    /**
     * @return array<string, mixed>
     */
    private function careMetric(User $actor, int $branchId): array
    {
        $openStatuses = [
            CareCase::STATUS_OPEN,
            CareCase::STATUS_ASSIGNED,
            CareCase::STATUS_IN_PROGRESS,
            CareCase::STATUS_ESCALATED,
        ];

        $query = CareCase::query()
            ->where('branch_id', $branchId)
            ->whereIn('status', $openStatuses);

        $this->applyCareVisibilityFilter($query, $actor);

        $open = $query->count();

        return $this->metricReady('care', [
            'open_cases' => $open,
        ], $open, $open, now());
    }

    /**
     * @return array<string, mixed>
     */
    private function eventsMetric(int $branchId): array
    {
        $windowEnd = now()->addDays((int) config('branch_dashboard.upcoming_event_window_days', 30))->toDateString();

        $upcoming = ChurchEvent::query()
            ->where('branch_id', $branchId)
            ->where('status', ChurchEvent::STATUS_PUBLISHED)
            ->where('event_date', '>=', now()->toDateString())
            ->where('event_date', '<=', $windowEnd)
            ->count();

        return $this->metricReady('events', [
            'upcoming_events' => $upcoming,
        ], $upcoming, $upcoming, now());
    }

    /**
     * @return array<string, mixed>
     */
    private function givingMetric(int $branchId, Carbon $from, Carbon $to, Carbon $prevFrom, Carbon $prevTo): array
    {
        $currentCents = (int) Contribution::query()
            ->where('branch_id', $branchId)
            ->where('status', Contribution::STATUS_SUCCEEDED)
            ->where('reconciliation_status', Contribution::RECON_RECONCILED)
            ->whereBetween('occurred_at', [$from, $to])
            ->sum('amount_cents');

        $previousCents = (int) Contribution::query()
            ->where('branch_id', $branchId)
            ->where('status', Contribution::STATUS_SUCCEEDED)
            ->where('reconciliation_status', Contribution::RECON_RECONCILED)
            ->whereBetween('occurred_at', [$prevFrom, $prevTo])
            ->sum('amount_cents');

        $latest = Contribution::query()
            ->where('branch_id', $branchId)
            ->where('status', Contribution::STATUS_SUCCEEDED)
            ->orderByDesc('occurred_at')
            ->value('occurred_at');

        $staleDays = (int) config('branch_dashboard.stale_thresholds.giving_days', 7);
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
    private function growthMetric(int $branchId, Carbon $from, Carbon $to, Carbon $prevFrom, Carbon $prevTo): array
    {
        $current = Member::query()
            ->where('branch_id', $branchId)
            ->whereNull('merged_into_id')
            ->whereBetween('created_at', [$from, $to])
            ->count();

        $previous = Member::query()
            ->where('branch_id', $branchId)
            ->whereNull('merged_into_id')
            ->whereBetween('created_at', [$prevFrom, $prevTo])
            ->count();

        return $this->metricReady('growth', [
            'new_members' => $current,
        ], $current, $previous, now());
    }

    /**
     * @return array<string, mixed>
     */
    private function followUpMetric(int $branchId): array
    {
        $openStatuses = [
            FollowUp::STATUS_ASSIGNED,
            FollowUp::STATUS_IN_PROGRESS,
            FollowUp::STATUS_ESCALATED,
        ];

        $open = FollowUp::query()
            ->where('branch_id', $branchId)
            ->whereIn('status', $openStatuses)
            ->count();

        $overdue = FollowUp::query()
            ->where('branch_id', $branchId)
            ->whereIn('status', $openStatuses)
            ->whereDate('due_date', '<', now()->toDateString())
            ->count();

        return $this->metricReady('follow_up', [
            'open_assignments' => $open,
            'overdue' => $overdue,
        ], $open, $open, now());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function drillMembers(int $branchId, Carbon $from, Carbon $to, int $limit): array
    {
        return Member::query()
            ->where('branch_id', $branchId)
            ->whereNull('merged_into_id')
            ->whereBetween('created_at', [$from, $to])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'membership_id', 'first_name', 'last_name', 'lifecycle_stage', 'lifecycle_status', 'created_at'])
            ->map(fn (Member $member) => [
                'id' => $member->id,
                'membership_id' => $member->membership_id,
                'name' => trim($member->first_name . ' ' . $member->last_name),
                'lifecycle_stage' => $member->lifecycle_stage,
                'lifecycle_status' => $member->lifecycle_status,
                'registered_at' => $member->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function drillVisitors(int $branchId, Carbon $from, Carbon $to, int $limit): array
    {
        return $this->visitorPeriodQuery($branchId, $from, $to)
            ->orderByDesc('first_visit_at')
            ->limit($limit)
            ->get(['id', 'first_name', 'last_name', 'first_visit_at', 'original_source'])
            ->map(fn (Visitor $visitor) => [
                'id' => $visitor->id,
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
    private function drillConverts(int $branchId, int $limit): array
    {
        return Member::query()
            ->where('branch_id', $branchId)
            ->where('lifecycle_stage', 'convert')
            ->where('lifecycle_status', 'active')
            ->whereNull('merged_into_id')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get(['id', 'membership_id', 'first_name', 'last_name', 'updated_at'])
            ->map(fn (Member $member) => [
                'id' => $member->id,
                'membership_id' => $member->membership_id,
                'name' => trim($member->first_name . ' ' . $member->last_name),
                'updated_at' => $member->updated_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function drillAttendance(int $branchId, Carbon $from, Carbon $to, int $limit): array
    {
        return AttendanceRecord::query()
            ->where('branch_id', $branchId)
            ->whereBetween('gathering_date', [$from->toDateString(), $to->toDateString()])
            ->orderByDesc('gathering_date')
            ->limit($limit)
            ->get(['id', 'subject_type', 'subject_id', 'gathering_date', 'status', 'service_type'])
            ->map(fn (AttendanceRecord $record) => [
                'id' => $record->id,
                'subject_type' => class_basename($record->subject_type),
                'subject_id' => $record->subject_id,
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
    private function drillTeams(int $branchId, int $limit): array
    {
        return ServiceTeam::query()
            ->where('branch_id', $branchId)
            ->where('status', ServiceTeam::STATUS_ACTIVE)
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'category', 'status'])
            ->map(fn (ServiceTeam $team) => [
                'id' => $team->id,
                'name' => $team->name,
                'category' => $team->category,
                'status' => $team->status,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function drillVolunteers(int $branchId, int $limit): array
    {
        return VolunteerProfile::query()
            ->where('branch_id', $branchId)
            ->where('status', VolunteerProfile::STATUS_ACTIVE)
            ->with('member:id,first_name,last_name,membership_id')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (VolunteerProfile $profile) => [
                'id' => $profile->id,
                'member_id' => $profile->member_id,
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
    private function drillWelfare(int $branchId, Carbon $from, Carbon $to, int $limit): array
    {
        return WelfareRequest::query()
            ->where('branch_id', $branchId)
            ->where('status', '!=', WelfareRequest::STATUS_DRAFT)
            ->whereBetween('submitted_at', [$from, $to])
            ->orderByDesc('submitted_at')
            ->limit($limit)
            ->get(['id', 'case_number', 'request_type', 'status', 'priority', 'submitted_at'])
            ->map(fn (WelfareRequest $case) => [
                'id' => $case->id,
                'case_number' => $case->case_number,
                'request_type' => $case->request_type,
                'status' => $case->status,
                'priority' => $case->priority,
                'submitted_at' => $case->submitted_at?->toIso8601String(),
                'identity_minimized' => true,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function drillCare(User $actor, int $branchId, int $limit): array
    {
        $openStatuses = [
            CareCase::STATUS_OPEN,
            CareCase::STATUS_ASSIGNED,
            CareCase::STATUS_IN_PROGRESS,
            CareCase::STATUS_ESCALATED,
        ];

        $query = CareCase::query()
            ->where('branch_id', $branchId)
            ->whereIn('status', $openStatuses);

        $this->applyCareVisibilityFilter($query, $actor);

        return $query
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'case_number', 'category', 'status', 'priority', 'confidentiality'])
            ->map(fn (CareCase $case) => [
                'id' => $case->id,
                'case_number' => $case->case_number,
                'category' => $case->category,
                'status' => $case->status,
                'priority' => $case->priority,
                'sensitive_details_omitted' => true,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function drillEvents(int $branchId, int $limit): array
    {
        $windowEnd = now()->addDays((int) config('branch_dashboard.upcoming_event_window_days', 30))->toDateString();

        return ChurchEvent::query()
            ->where('branch_id', $branchId)
            ->where('status', ChurchEvent::STATUS_PUBLISHED)
            ->where('event_date', '>=', now()->toDateString())
            ->where('event_date', '<=', $windowEnd)
            ->orderBy('event_date')
            ->limit($limit)
            ->get(['id', 'title', 'event_date', 'venue', 'status'])
            ->map(fn (ChurchEvent $event) => [
                'id' => $event->id,
                'title' => $event->title,
                'event_date' => $event->event_date?->toDateString(),
                'venue' => $event->venue,
                'status' => $event->status,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function drillGiving(int $branchId, Carbon $from, Carbon $to, int $limit): array
    {
        return Contribution::query()
            ->where('branch_id', $branchId)
            ->where('status', Contribution::STATUS_SUCCEEDED)
            ->where('reconciliation_status', Contribution::RECON_RECONCILED)
            ->whereBetween('occurred_at', [$from, $to])
            ->orderByDesc('occurred_at')
            ->limit($limit)
            ->get(['id', 'reference', 'amount_cents', 'currency', 'category', 'occurred_at'])
            ->map(fn (Contribution $contribution) => [
                'id' => $contribution->id,
                'reference' => $contribution->reference,
                'amount_cents' => $contribution->amount_cents,
                'currency' => $contribution->currency,
                'category' => $contribution->category,
                'occurred_at' => $contribution->occurred_at?->toIso8601String(),
                'donor_identity_omitted' => true,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function drillGrowth(int $branchId, Carbon $from, Carbon $to, int $limit): array
    {
        return $this->drillMembers($branchId, $from, $to, $limit);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function drillFollowUps(int $branchId, int $limit): array
    {
        $openStatuses = [
            FollowUp::STATUS_ASSIGNED,
            FollowUp::STATUS_IN_PROGRESS,
            FollowUp::STATUS_ESCALATED,
        ];

        return FollowUp::query()
            ->where('branch_id', $branchId)
            ->whereIn('status', $openStatuses)
            ->orderBy('due_date')
            ->limit($limit)
            ->get(['id', 'reason', 'status', 'priority', 'due_date', 'is_restricted'])
            ->map(fn (FollowUp $followUp) => [
                'id' => $followUp->id,
                'reason' => $followUp->is_restricted ? 'Restricted follow-up' : $followUp->reason,
                'status' => $followUp->status,
                'priority' => $followUp->priority,
                'due_date' => $followUp->due_date?->toDateString(),
                'is_restricted' => $followUp->is_restricted,
            ])
            ->values()
            ->all();
    }

  /**
     * @return array{0: Carbon, 1: Carbon, 2: Carbon, 3: Carbon, 4: array<string, mixed>}
     */
    private function parsePeriod(array $filters): array
    {
        $days = (int) config('branch_dashboard.default_period_days', 30);
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

    private function visitorPeriodQuery(int $branchId, Carbon $from, Carbon $to): Builder
    {
        return Visitor::query()
            ->where('branch_id', $branchId)
            ->where(function (Builder $inner) use ($from, $to): void {
                $inner->whereBetween('first_visit_at', [$from, $to])
                    ->orWhere(function (Builder $fallback) use ($from, $to): void {
                        $fallback->whereNull('first_visit_at')
                            ->whereBetween('created_at', [$from, $to]);
                    });
            });
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

    private function assertBranchTarget(Organization $branch): void
    {
        if ($branch->type !== 'branch') {
            throw ValidationException::withMessages([
                'branch' => ['Dashboard is only available for branch organizations.'],
            ]);
        }
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
