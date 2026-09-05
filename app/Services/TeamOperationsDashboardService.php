<?php

namespace App\Services;

use App\Models\ChurchEvent;
use App\Models\ChurchService;
use App\Models\FollowUp;
use App\Models\Member;
use App\Models\MemberNotification;
use App\Models\OnboardingEnrollment;
use App\Models\OperationalIncident;
use App\Models\ServiceTeam;
use App\Models\ServiceTeamAssignment;
use App\Models\TeamAttendanceRecord;
use App\Models\TeamOccurrence;
use App\Models\TeamReport;
use App\Models\TeamRoster;
use App\Models\TeamRosterSlot;
use App\Models\User;
use App\Models\VolunteerProfile;
use App\Models\VolunteerProfileChange;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Story 5.8 / 12.3: permission-scoped team leader operations dashboard and drill-down.
 */
class TeamOperationsDashboardService
{
    public function __construct(
        private AuthorizationService $authorization,
    ) {
    }

    /**
     * @return Collection<int, ServiceTeam>
     */
    public function listAccessibleTeams(User $actor): Collection
    {
        $this->assertCan($actor, 'teams.dashboard.read');

        $query = ServiceTeam::query()
            ->where('status', ServiceTeam::STATUS_ACTIVE)
            ->orderBy('name');

        $this->applyBranchScope($query, $actor);

        $teams = $query->limit(200)->get(['id', 'name', 'branch_id', 'category', 'status', 'leaders']);

        if ($this->authorization->allows($actor, 'teams.read')) {
            return $teams->take(50)->values();
        }

        return $teams
            ->filter(fn (ServiceTeam $team) => $this->isTeamLeader($actor, $team))
            ->take(50)
            ->values();
    }

    public function dashboard(User $actor, ServiceTeam $team): array
    {
        $this->assertCan($actor, 'teams.dashboard.read');
        $this->assertTeamAccessible($actor, $team);

        $memberIds = $this->teamMemberIds($team);
        $widgets = [
            'membership' => $this->membershipWidget($actor, $team),
            'availability' => $this->availabilityWidget($actor, $team),
            'attendance' => $this->attendanceWidget($actor, $team),
            'services' => $this->servicesWidget($actor, $team),
            'rosters' => $this->rostersWidget($actor, $team),
            'assignments' => $this->assignmentsWidget($actor, $team),
            'reports' => $this->reportsWidget($actor, $team),
            'tasks' => $this->tasksWidget($actor, $team),
            'follow_ups' => $this->followUpsWidget($actor, $team),
            'new_members' => $this->newMembersWidget($actor, $team),
            'training' => $this->trainingWidget($actor, $team, $memberIds),
            'events' => $this->eventsWidget($actor, $team),
            'notifications' => $this->notificationsWidget($actor, $team),
            'indicators' => $this->indicatorsWidget($actor, $team, $memberIds),
            'issues' => $this->issuesWidget($actor, $team),
        ];

        $version = $this->computeVersion($team, $actor);

        return [
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'branch_id' => $team->branch_id,
                'category' => $team->category,
                'status' => $team->status,
            ],
            'generated_at' => now()->toIso8601String(),
            'version' => $version,
            'layout' => 'team_leader',
            'priority_actions' => $this->priorityActions($actor, $team, $widgets),
            'widgets' => $widgets,
        ];
    }

    /**
     * Story 12.3: refresh dashboard after a leader action; fail closed on stale version.
     *
     * @param  array<string, mixed>  $payload
     */
    public function syncAfterAction(User $actor, ServiceTeam $team, array $payload = []): array
    {
        $this->assertCan($actor, 'teams.dashboard.read');
        $this->assertTeamAccessible($actor, $team);

        $expected = (string) ($payload['expected_version'] ?? '');
        $current = $this->computeVersion($team, $actor);

        if ($expected !== '' && ! hash_equals($current, $expected)) {
            throw new TeamDashboardConflictException(
                'Dashboard data changed while you were working. Refresh to see the latest version before continuing.',
                $current,
            );
        }

        return $this->dashboard($actor, $team->fresh() ?? $team);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function drillDown(User $actor, ServiceTeam $team, string $widget, array $filters = []): array
    {
        $this->assertCan($actor, 'teams.dashboard.read');
        $this->assertTeamAccessible($actor, $team);

        $allowed = config('team_dashboard.widgets', []);
        if (! in_array($widget, $allowed, true)) {
            throw ValidationException::withMessages(['widget' => ['Unknown dashboard widget.']]);
        }

        $records = match ($widget) {
            'membership' => $this->drillMembership($actor, $team, $filters),
            'availability' => $this->drillAvailability($actor, $team, $filters),
            'attendance' => $this->drillAttendance($actor, $team, $filters),
            'services' => $this->drillServices($actor, $team, $filters),
            'rosters' => $this->drillRosters($actor, $team, $filters),
            'assignments' => $this->drillAssignments($actor, $team, $filters),
            'reports' => $this->drillReports($actor, $team, $filters),
            'tasks', 'follow_ups' => $this->drillTasks($actor, $team, $filters),
            'new_members' => $this->drillNewMembers($actor, $team, $filters),
            'training' => $this->drillTraining($actor, $team, $filters),
            'events' => $this->drillEvents($actor, $team, $filters),
            'notifications' => $this->drillNotifications($actor, $team, $filters),
            'issues' => $this->drillIssues($actor, $team, $filters),
            'indicators' => $this->drillIndicators($actor, $team, $filters),
            default => [],
        };

        return [
            'widget' => $widget,
            'team_id' => $team->id,
            'filters' => $filters,
            'records' => $records,
            'next_actions' => $this->nextActions($actor, $widget),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function membershipWidget(User $actor, ServiceTeam $team): array
    {
        if (! $this->allows($actor, 'teams.assignments.read')) {
            return $this->unauthorizedWidget('membership');
        }

        $active = ServiceTeamAssignment::query()
            ->where('service_team_id', $team->id)
            ->where('status', ServiceTeamAssignment::STATUS_ACTIVE)
            ->count();

        $pending = ServiceTeamAssignment::query()
            ->where('service_team_id', $team->id)
            ->where('status', ServiceTeamAssignment::STATUS_PENDING)
            ->count();

        $minimum = (int) ($team->minimum_staffing['minimum_per_session'] ?? 0);

        return [
            'state' => $active === 0 && $pending === 0 ? 'empty' : 'ready',
            'active_members' => $active,
            'pending_assignments' => $pending,
            'minimum_staffing' => $minimum,
            'staffing_gap' => max(0, $minimum - $active),
            'drill_down' => 'membership',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function attendanceWidget(User $actor, ServiceTeam $team): array
    {
        if (! $this->allows($actor, 'teams.attendance.read')) {
            return $this->unauthorizedWidget('attendance');
        }

        $lastCaptured = TeamOccurrence::query()
            ->where('service_team_id', $team->id)
            ->where('status', TeamOccurrence::STATUS_COMPLETED)
            ->orderByDesc('occurrence_date')
            ->value('occurrence_date');

        $upcoming = TeamOccurrence::query()
            ->where('service_team_id', $team->id)
            ->where('occurrence_date', '>=', now()->toDateString())
            ->whereIn('status', [TeamOccurrence::STATUS_SCHEDULED, TeamOccurrence::STATUS_COMPLETED])
            ->count();

        $uncaptured = TeamOccurrence::query()
            ->where('service_team_id', $team->id)
            ->where('occurrence_date', '<', now()->toDateString())
            ->where('status', TeamOccurrence::STATUS_SCHEDULED)
            ->count();

        $staleDays = (int) config('team_dashboard.stale_thresholds.attendance_days', 14);
        $isStale = $lastCaptured === null
            || Carbon::parse($lastCaptured)->lt(now()->subDays($staleDays));

        $state = 'ready';
        if ($upcoming === 0 && $uncaptured === 0 && $lastCaptured === null) {
            $state = 'empty';
        } elseif ($uncaptured === 0 && $isStale) {
            $state = 'stale';
        }

        return [
            'state' => $state,
            'last_captured_on' => $lastCaptured,
            'upcoming_occurrences' => $upcoming,
            'uncaptured_past_occurrences' => $uncaptured,
            'drill_down' => 'attendance',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rostersWidget(User $actor, ServiceTeam $team): array
    {
        if (! $this->allows($actor, 'teams.rosters.read')) {
            return $this->unauthorizedWidget('rosters');
        }

        $windowEnd = now()->addDays((int) config('team_dashboard.upcoming_window_days', 14))->toDateString();

        $published = TeamRoster::query()
            ->where('service_team_id', $team->id)
            ->where('status', TeamRoster::STATUS_PUBLISHED)
            ->count();

        $upcomingSlots = TeamRosterSlot::query()
            ->whereHas('roster', fn (Builder $q) => $q->where('service_team_id', $team->id)->where('status', TeamRoster::STATUS_PUBLISHED))
            ->whereBetween('shift_date', [now()->toDateString(), $windowEnd])
            ->count();

        $unresolved = TeamRosterSlot::query()
            ->whereHas('roster', fn (Builder $q) => $q->where('service_team_id', $team->id)->where('status', TeamRoster::STATUS_PUBLISHED))
            ->whereBetween('shift_date', [now()->toDateString(), $windowEnd])
            ->whereIn('status', [TeamRosterSlot::STATUS_REJECTED, TeamRosterSlot::STATUS_REPLACEMENT_REQUESTED])
            ->count();

        return [
            'state' => $published === 0 ? 'empty' : 'ready',
            'published_rosters' => $published,
            'upcoming_slots' => $upcomingSlots,
            'unresolved_slots' => $unresolved,
            'drill_down' => 'rosters',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function assignmentsWidget(User $actor, ServiceTeam $team): array
    {
        if (! $this->allows($actor, 'teams.assignments.read')) {
            return $this->unauthorizedWidget('assignments');
        }

        $pending = ServiceTeamAssignment::query()
            ->where('service_team_id', $team->id)
            ->where('status', ServiceTeamAssignment::STATUS_PENDING)
            ->count();

        $scheduled = ServiceTeamAssignment::query()
            ->where('service_team_id', $team->id)
            ->where('status', ServiceTeamAssignment::STATUS_SCHEDULED)
            ->count();

        return [
            'state' => $pending === 0 && $scheduled === 0 ? 'empty' : 'ready',
            'pending_approvals' => $pending,
            'scheduled_assignments' => $scheduled,
            'drill_down' => 'assignments',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reportsWidget(User $actor, ServiceTeam $team): array
    {
        if (! $this->allows($actor, 'teams.reports.read')) {
            return $this->unauthorizedWidget('reports');
        }

        $drafts = TeamReport::query()->where('service_team_id', $team->id)->where('status', TeamReport::STATUS_DRAFT)->count();
        $returned = TeamReport::query()->where('service_team_id', $team->id)->where('status', TeamReport::STATUS_RETURNED)->count();
        $submitted = TeamReport::query()->where('service_team_id', $team->id)->where('status', TeamReport::STATUS_SUBMITTED)->count();

        $lastSubmitted = TeamReport::query()
            ->where('service_team_id', $team->id)
            ->whereNotNull('submitted_at')
            ->orderByDesc('submitted_at')
            ->value('submitted_at');

        $staleDays = (int) config('team_dashboard.stale_thresholds.reports_days', 7);
        $isStale = $lastSubmitted === null
            || Carbon::parse($lastSubmitted)->lt(now()->subDays($staleDays));

        $state = 'ready';
        if ($drafts === 0 && $returned === 0 && $submitted === 0) {
            $state = 'empty';
        } elseif ($isStale && $submitted === 0 && $drafts === 0 && $returned === 0) {
            $state = 'stale';
        }

        return [
            'state' => $state,
            'draft_reports' => $drafts,
            'returned_reports' => $returned,
            'submitted_reports' => $submitted,
            'last_submitted_at' => $lastSubmitted?->toIso8601String(),
            'drill_down' => 'reports',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tasksWidget(User $actor, ServiceTeam $team): array
    {
        if (! $this->allows($actor, 'followups.read')) {
            return $this->unauthorizedWidget('tasks');
        }

        $openStatuses = [
            FollowUp::STATUS_ASSIGNED,
            FollowUp::STATUS_IN_PROGRESS,
            FollowUp::STATUS_ESCALATED,
        ];

        $open = FollowUp::query()
            ->where('assignee_id', $actor->id)
            ->where('branch_id', $team->branch_id)
            ->whereIn('status', $openStatuses)
            ->count();

        $overdue = FollowUp::query()
            ->where('assignee_id', $actor->id)
            ->where('branch_id', $team->branch_id)
            ->whereIn('status', $openStatuses)
            ->whereDate('due_date', '<', now()->toDateString())
            ->count();

        return [
            'state' => $open === 0 ? 'empty' : 'ready',
            'open_tasks' => $open,
            'overdue_tasks' => $overdue,
            'drill_down' => 'tasks',
        ];
    }

    /**
     * @param  array<int, int>  $memberIds
     * @return array<string, mixed>
     */
    private function trainingWidget(User $actor, ServiceTeam $team, array $memberIds): array
    {
        if (! $this->allows($actor, 'volunteers.read')) {
            return $this->unauthorizedWidget('training');
        }

        $pendingVerification = $memberIds === [] ? 0 : VolunteerProfileChange::query()
            ->whereHas('profile', fn (Builder $q) => $q->whereIn('member_id', $memberIds))
            ->where('verification_status', VolunteerProfileChange::STATUS_PENDING)
            ->count();

        $activeEnrollments = 0;
        if ($memberIds !== []) {
            $activeEnrollments = OnboardingEnrollment::query()
                ->where('subject_type', Member::class)
                ->whereIn('subject_id', $memberIds)
                ->where('status', OnboardingEnrollment::STATUS_ACTIVE)
                ->count();
        }

        return [
            'state' => $pendingVerification === 0 && $activeEnrollments === 0 ? 'empty' : 'ready',
            'pending_verifications' => $pendingVerification,
            'active_training_enrollments' => $activeEnrollments,
            'drill_down' => 'training',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function eventsWidget(User $actor, ServiceTeam $team): array
    {
        if (! $this->allows($actor, 'events.read')) {
            return $this->unauthorizedWidget('events');
        }

        $upcoming = ChurchEvent::query()
            ->where('branch_id', $team->branch_id)
            ->where('status', ChurchEvent::STATUS_PUBLISHED)
            ->whereDate('event_date', '>=', now()->toDateString())
            ->count();

        return [
            'state' => $upcoming === 0 ? 'empty' : 'ready',
            'upcoming_events' => $upcoming,
            'drill_down' => 'events',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function notificationsWidget(User $actor, ServiceTeam $team): array
    {
        $unread = MemberNotification::query()
            ->where('user_id', $actor->id)
            ->whereNull('read_at')
            ->where(function (Builder $query) use ($team): void {
                $query->whereJsonContains('metadata->service_team_id', $team->id)
                    ->orWhereIn('type', [
                        'team_report.submitted',
                        'team_report.reviewed',
                        'team_roster.published',
                        'service_team.config_updated',
                    ]);
            })
            ->count();

        return [
            'state' => $unread === 0 ? 'empty' : 'ready',
            'unread_notifications' => $unread,
            'drill_down' => 'notifications',
        ];
    }

    /**
     * @param  array<int, int>  $memberIds
     * @return array<string, mixed>
     */
    private function indicatorsWidget(User $actor, ServiceTeam $team, array $memberIds): array
    {
        $membership = $this->membershipWidget($actor, $team);
        $attendance = $this->attendanceWidget($actor, $team);
        $reports = $this->reportsWidget($actor, $team);
        $issues = $this->issuesWidget($actor, $team);

        $minimum = (int) ($membership['minimum_staffing'] ?? 0);
        $active = (int) ($membership['active_members'] ?? 0);
        $staffingPercent = $minimum > 0 ? min(100, round(($active / $minimum) * 100, 1)) : null;

        $attendanceRate = null;
        if ($this->allows($actor, 'teams.attendance.read') && $memberIds !== []) {
            $records = TeamAttendanceRecord::query()
                ->whereHas('occurrence', fn (Builder $q) => $q->where('service_team_id', $team->id))
                ->get(['status']);

            if ($records->isNotEmpty()) {
                $present = $records->whereIn('status', [TeamAttendanceRecord::STATUS_PRESENT, TeamAttendanceRecord::STATUS_LATE])->count();
                $attendanceRate = round(($present / $records->count()) * 100, 1);
            }
        }

        return [
            'state' => 'ready',
            'staffing_percent' => $staffingPercent,
            'attendance_rate' => $attendanceRate,
            'pending_reports' => (int) ($reports['draft_reports'] ?? 0) + (int) ($reports['returned_reports'] ?? 0),
            'open_issues' => (int) ($issues['open_issues'] ?? 0),
            'uncaptured_occurrences' => (int) ($attendance['uncaptured_past_occurrences'] ?? 0),
            'drill_down' => 'indicators',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function issuesWidget(User $actor, ServiceTeam $team): array
    {
        if (! $this->allows($actor, 'incidents.read')) {
            return $this->unauthorizedWidget('issues');
        }

        $openStatuses = [
            OperationalIncident::STATUS_OPEN,
            OperationalIncident::STATUS_INVESTIGATING,
            OperationalIncident::STATUS_ESCALATED,
            OperationalIncident::STATUS_PENDING_REVIEW,
            OperationalIncident::STATUS_RETURNED,
        ];

        $open = OperationalIncident::query()
            ->where('branch_id', $team->branch_id)
            ->whereIn('status', $openStatuses)
            ->where(function (Builder $query) use ($team, $actor): void {
                $query->where('assigned_team', $team->category)
                    ->orWhere('owner_id', $actor->id);
            })
            ->count();

        return [
            'state' => $open === 0 ? 'empty' : 'ready',
            'open_issues' => $open,
            'drill_down' => 'issues',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function drillMembership(User $actor, ServiceTeam $team, array $filters): array
    {
        if (! $this->allows($actor, 'teams.assignments.read')) {
            return [];
        }

        $status = $filters['status'] ?? ServiceTeamAssignment::STATUS_ACTIVE;

        return ServiceTeamAssignment::query()
            ->with(['member:id,first_name,last_name,membership_id'])
            ->where('service_team_id', $team->id)
            ->when($status !== 'all', fn (Builder $q) => $q->where('status', $status))
            ->orderBy('effective_from')
            ->limit($this->limit('membership'))
            ->get()
            ->map(fn (ServiceTeamAssignment $assignment) => [
                'id' => $assignment->id,
                'member_id' => $assignment->member_id,
                'member_name' => $assignment->member?->fullName(),
                'status' => $assignment->status,
                'team_role' => $assignment->team_role,
                'shift_label' => $assignment->shift_label,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function drillAttendance(User $actor, ServiceTeam $team, array $filters): array
    {
        if (! $this->allows($actor, 'teams.attendance.read')) {
            return [];
        }

        $scope = $filters['scope'] ?? 'upcoming';

        $query = TeamOccurrence::query()
            ->where('service_team_id', $team->id)
            ->orderBy('occurrence_date');

        if ($scope === 'uncaptured') {
            $query->where('occurrence_date', '<', now()->toDateString())
                ->where('status', TeamOccurrence::STATUS_SCHEDULED);
        } elseif ($scope === 'recent') {
            $query->where('status', TeamOccurrence::STATUS_COMPLETED)
                ->orderByDesc('occurrence_date');
        } else {
            $query->where('occurrence_date', '>=', now()->toDateString());
        }

        return $query->limit($this->limit('attendance'))
            ->get(['id', 'title', 'occurrence_date', 'occurrence_type', 'status'])
            ->map(fn (TeamOccurrence $occurrence) => [
                'id' => $occurrence->id,
                'title' => $occurrence->title,
                'occurrence_date' => $occurrence->occurrence_date?->toDateString(),
                'occurrence_type' => $occurrence->occurrence_type,
                'status' => $occurrence->status,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function drillRosters(User $actor, ServiceTeam $team, array $filters): array
    {
        if (! $this->allows($actor, 'teams.rosters.read')) {
            return [];
        }

        $scope = $filters['scope'] ?? 'upcoming';

        if ($scope === 'unresolved') {
            return TeamRosterSlot::query()
                ->with(['member:id,first_name,last_name', 'roster:id,title'])
                ->whereHas('roster', fn (Builder $q) => $q->where('service_team_id', $team->id)->where('status', TeamRoster::STATUS_PUBLISHED))
                ->whereIn('status', [TeamRosterSlot::STATUS_REJECTED, TeamRosterSlot::STATUS_REPLACEMENT_REQUESTED])
                ->orderBy('shift_date')
                ->limit($this->limit('rosters'))
                ->get()
                ->map(fn (TeamRosterSlot $slot) => [
                    'id' => $slot->id,
                    'roster_title' => $slot->roster?->title,
                    'member_name' => $slot->member?->fullName(),
                    'duty_label' => $slot->duty_label,
                    'shift_date' => $slot->shift_date?->toDateString(),
                    'status' => $slot->status,
                ])
                ->values()
                ->all();
        }

        return TeamRosterSlot::query()
            ->with(['member:id,first_name,last_name', 'roster:id,title'])
            ->whereHas('roster', fn (Builder $q) => $q->where('service_team_id', $team->id)->where('status', TeamRoster::STATUS_PUBLISHED))
            ->where('shift_date', '>=', now()->toDateString())
            ->orderBy('shift_date')
            ->limit($this->limit('rosters'))
            ->get()
            ->map(fn (TeamRosterSlot $slot) => [
                'id' => $slot->id,
                'roster_title' => $slot->roster?->title,
                'member_name' => $slot->member?->fullName(),
                'duty_label' => $slot->duty_label,
                'shift_date' => $slot->shift_date?->toDateString(),
                'status' => $slot->status,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function drillAssignments(User $actor, ServiceTeam $team, array $filters): array
    {
        if (! $this->allows($actor, 'teams.assignments.read')) {
            return [];
        }

        $status = $filters['status'] ?? ServiceTeamAssignment::STATUS_PENDING;

        return ServiceTeamAssignment::query()
            ->with(['member:id,first_name,last_name,membership_id'])
            ->where('service_team_id', $team->id)
            ->where('status', $status)
            ->orderByDesc('created_at')
            ->limit($this->limit('assignments'))
            ->get()
            ->map(fn (ServiceTeamAssignment $assignment) => [
                'id' => $assignment->id,
                'member_name' => $assignment->member?->fullName(),
                'status' => $assignment->status,
                'team_role' => $assignment->team_role,
                'effective_from' => $assignment->effective_from?->toDateString(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function drillReports(User $actor, ServiceTeam $team, array $filters): array
    {
        if (! $this->allows($actor, 'teams.reports.read')) {
            return [];
        }

        $status = $filters['status'] ?? TeamReport::STATUS_DRAFT;

        return TeamReport::query()
            ->where('service_team_id', $team->id)
            ->where('status', $status)
            ->orderByDesc('reporting_period_end')
            ->limit($this->limit('reports'))
            ->get(['id', 'status', 'reporting_period_start', 'reporting_period_end', 'submitted_at'])
            ->map(fn (TeamReport $report) => [
                'id' => $report->id,
                'status' => $report->status,
                'reporting_period_start' => $report->reporting_period_start?->toDateString(),
                'reporting_period_end' => $report->reporting_period_end?->toDateString(),
                'submitted_at' => $report->submitted_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function drillTasks(User $actor, ServiceTeam $team, array $filters): array
    {
        if (! $this->allows($actor, 'followups.read')) {
            return [];
        }

        $scope = $filters['scope'] ?? 'open';

        $query = FollowUp::query()
            ->where('assignee_id', $actor->id)
            ->where('branch_id', $team->branch_id)
            ->orderBy('due_date');

        if ($scope === 'overdue') {
            $query->whereDate('due_date', '<', now()->toDateString())
                ->whereIn('status', [FollowUp::STATUS_ASSIGNED, FollowUp::STATUS_IN_PROGRESS, FollowUp::STATUS_ESCALATED]);
        } else {
            $query->whereIn('status', [FollowUp::STATUS_ASSIGNED, FollowUp::STATUS_IN_PROGRESS, FollowUp::STATUS_ESCALATED]);
        }

        return $query->limit($this->limit('tasks'))
            ->get(['id', 'reason', 'status', 'priority', 'due_date'])
            ->map(fn (FollowUp $task) => [
                'id' => $task->id,
                'reason' => $task->reason,
                'status' => $task->status,
                'priority' => $task->priority,
                'due_date' => $task->due_date?->toDateString(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function drillTraining(User $actor, ServiceTeam $team, array $filters): array
    {
        if (! $this->allows($actor, 'volunteers.read')) {
            return [];
        }

        $memberIds = $this->teamMemberIds($team);
        if ($memberIds === []) {
            return [];
        }

        $scope = $filters['scope'] ?? 'pending_verification';

        if ($scope === 'enrollments') {
            return OnboardingEnrollment::query()
                ->with('journey:id,name')
                ->where('subject_type', Member::class)
                ->whereIn('subject_id', $memberIds)
                ->where('status', OnboardingEnrollment::STATUS_ACTIVE)
                ->limit($this->limit('training'))
                ->get()
                ->map(fn (OnboardingEnrollment $enrollment) => [
                    'id' => $enrollment->id,
                    'journey_name' => $enrollment->journey?->name,
                    'member_id' => $enrollment->subject_id,
                    'status' => $enrollment->status,
                    'enrolled_at' => $enrollment->enrolled_at?->toIso8601String(),
                ])
                ->values()
                ->all();
        }

        return VolunteerProfileChange::query()
            ->with(['profile.member:id,first_name,last_name'])
            ->whereHas('profile', fn (Builder $q) => $q->whereIn('member_id', $memberIds))
            ->where('verification_status', VolunteerProfileChange::STATUS_PENDING)
            ->orderByDesc('created_at')
            ->limit($this->limit('training'))
            ->get()
            ->map(fn (VolunteerProfileChange $change) => [
                'id' => $change->id,
                'member_name' => $change->profile?->member?->fullName(),
                'field_name' => $change->field,
                'verification_status' => $change->verification_status,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function drillEvents(User $actor, ServiceTeam $team, array $filters): array
    {
        if (! $this->allows($actor, 'events.read')) {
            return [];
        }

        return ChurchEvent::query()
            ->where('branch_id', $team->branch_id)
            ->where('status', ChurchEvent::STATUS_PUBLISHED)
            ->whereDate('event_date', '>=', now()->toDateString())
            ->orderBy('event_date')
            ->limit($this->limit('events'))
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
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function drillNotifications(User $actor, ServiceTeam $team, array $filters): array
    {
        return MemberNotification::query()
            ->where('user_id', $actor->id)
            ->when(($filters['unread_only'] ?? '1') === '1', fn (Builder $q) => $q->whereNull('read_at'))
            ->where(function (Builder $query) use ($team): void {
                $query->whereJsonContains('metadata->service_team_id', $team->id)
                    ->orWhereIn('type', [
                        'team_report.submitted',
                        'team_report.reviewed',
                        'team_roster.published',
                        'service_team.config_updated',
                    ]);
            })
            ->orderByDesc('created_at')
            ->limit($this->limit('notifications'))
            ->get(['id', 'type', 'message', 'read_at', 'created_at'])
            ->map(fn (MemberNotification $notification) => [
                'id' => $notification->id,
                'type' => $notification->type,
                'message' => $notification->message,
                'read_at' => $notification->read_at?->toIso8601String(),
                'created_at' => $notification->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function drillIssues(User $actor, ServiceTeam $team, array $filters): array
    {
        if (! $this->allows($actor, 'incidents.read')) {
            return [];
        }

        $openStatuses = [
            OperationalIncident::STATUS_OPEN,
            OperationalIncident::STATUS_INVESTIGATING,
            OperationalIncident::STATUS_ESCALATED,
            OperationalIncident::STATUS_PENDING_REVIEW,
            OperationalIncident::STATUS_RETURNED,
        ];

        return OperationalIncident::query()
            ->where('branch_id', $team->branch_id)
            ->whereIn('status', $openStatuses)
            ->where(function (Builder $query) use ($team, $actor): void {
                $query->where('assigned_team', $team->category)
                    ->orWhere('owner_id', $actor->id);
            })
            ->orderByDesc('occurred_at')
            ->limit($this->limit('issues'))
            ->get(['id', 'reference', 'classification', 'priority', 'status', 'occurred_at'])
            ->map(fn (OperationalIncident $incident) => [
                'id' => $incident->id,
                'reference' => $incident->reference,
                'classification' => $incident->classification,
                'priority' => $incident->priority,
                'status' => $incident->status,
                'occurred_at' => $incident->occurred_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function drillIndicators(User $actor, ServiceTeam $team, array $filters): array
    {
        $widget = $this->indicatorsWidget($actor, $team, $this->teamMemberIds($team));
        $target = $filters['metric'] ?? null;

        if ($target === 'attendance') {
            return $this->drillAttendance($actor, $team, ['scope' => 'uncaptured']);
        }

        if ($target === 'reports') {
            return $this->drillReports($actor, $team, ['status' => TeamReport::STATUS_DRAFT]);
        }

        if ($target === 'issues') {
            return $this->drillIssues($actor, $team, $filters);
        }

        return [[
            'metric' => 'summary',
            'staffing_percent' => $widget['staffing_percent'],
            'attendance_rate' => $widget['attendance_rate'],
            'pending_reports' => $widget['pending_reports'],
            'open_issues' => $widget['open_issues'],
        ]];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function nextActions(User $actor, string $widget): array
    {
        $actions = config("team_dashboard.next_actions.{$widget}", []);

        return array_values(array_filter($actions, fn (array $action) => $this->allows($actor, $action['permission'] ?? '')));
    }

    /**
     * @return array<int, int>
     */
    private function teamMemberIds(ServiceTeam $team): array
    {
        return ServiceTeamAssignment::query()
            ->where('service_team_id', $team->id)
            ->whereIn('status', config('team_assignments.active_statuses', []))
            ->pluck('member_id')
            ->unique()
            ->values()
            ->all();
    }

  /**
     * @return array<string, mixed>
     */
    private function unauthorizedWidget(string $widget): array
    {
        return [
            'state' => 'unauthorized',
            'drill_down' => $widget,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function availabilityWidget(User $actor, ServiceTeam $team): array
    {
        if (! $this->allows($actor, 'teams.rosters.read')) {
            return $this->unauthorizedWidget('availability');
        }

        $windowEnd = now()->addDays((int) config('team_dashboard.upcoming_window_days', 14))->toDateString();

        $slots = TeamRosterSlot::query()
            ->whereHas('roster', fn (Builder $q) => $q->where('service_team_id', $team->id)->where('status', TeamRoster::STATUS_PUBLISHED))
            ->whereBetween('shift_date', [now()->toDateString(), $windowEnd])
            ->get(['member_response', 'responded_at']);

        $accepted = $slots->where('member_response', 'accepted')->count();
        $pending = $slots->filter(fn (TeamRosterSlot $slot) => $slot->responded_at === null)->count();
        $declined = $slots->whereIn('member_response', ['declined', 'rejected'])->count();

        $memberIds = $this->teamMemberIds($team);
        $profilesWithAvailability = $memberIds === [] ? 0 : VolunteerProfile::query()
            ->whereIn('member_id', $memberIds)
            ->whereNotNull('availability')
            ->count();

        return [
            'state' => $slots->isEmpty() && $profilesWithAvailability === 0 ? 'empty' : 'ready',
            'accepted_slots' => $accepted,
            'pending_responses' => $pending,
            'declined_slots' => $declined,
            'profiles_with_availability' => $profilesWithAvailability,
            'drill_down' => 'availability',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function servicesWidget(User $actor, ServiceTeam $team): array
    {
        if (! $this->allows($actor, 'events.read')) {
            return $this->unauthorizedWidget('services');
        }

        $windowEnd = now()->addDays((int) config('team_dashboard.upcoming_window_days', 14))->toDateString();

        $upcoming = ChurchService::query()
            ->where('branch_id', $team->branch_id)
            ->where('status', ChurchService::STATUS_PUBLISHED)
            ->whereBetween('service_date', [now()->toDateString(), $windowEnd])
            ->orderBy('service_date')
            ->limit(5)
            ->get(['id', 'title', 'service_date', 'start_time', 'venue']);

        return [
            'state' => $upcoming->isEmpty() ? 'empty' : 'ready',
            'upcoming_services' => $upcoming->count(),
            'next_service_date' => $upcoming->first()?->service_date?->toDateString(),
            'drill_down' => 'services',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function followUpsWidget(User $actor, ServiceTeam $team): array
    {
        $tasks = $this->tasksWidget($actor, $team);
        if (($tasks['state'] ?? '') === 'unauthorized') {
            return $this->unauthorizedWidget('follow_ups');
        }

        return [
            ...$tasks,
            'drill_down' => 'follow_ups',
            'label' => 'Follow-ups assigned to you',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function newMembersWidget(User $actor, ServiceTeam $team): array
    {
        if (! $this->allows($actor, 'teams.assignments.read')) {
            return $this->unauthorizedWidget('new_members');
        }

        $since = now()->subDays((int) config('team_dashboard.new_member_window_days', 30))->toDateString();

        $recent = ServiceTeamAssignment::query()
            ->with('member:id,first_name,last_name,preferred_name,effective_from')
            ->where('service_team_id', $team->id)
            ->whereDate('effective_from', '>=', $since)
            ->whereIn('status', [ServiceTeamAssignment::STATUS_ACTIVE, ServiceTeamAssignment::STATUS_PENDING, ServiceTeamAssignment::STATUS_SCHEDULED])
            ->orderByDesc('effective_from')
            ->limit(8)
            ->get();

        return [
            'state' => $recent->isEmpty() ? 'empty' : 'ready',
            'recent_assignments' => $recent->count(),
            'drill_down' => 'new_members',
        ];
    }

    /**
     * @param  array<string, mixed>  $widgets
     * @return array<int, array<string, mixed>>
     */
    private function priorityActions(User $actor, ServiceTeam $team, array $widgets): array
    {
        $labels = config('team_dashboard.urgency_labels', []);
        $actions = [];

        if (($widgets['tasks']['overdue_tasks'] ?? 0) > 0) {
            $actions[] = [
                'type' => 'overdue_follow_up',
                'urgency_level' => 'critical',
                'urgency_label' => $labels['critical'] ?? 'Critical — act today',
                'title' => 'Overdue follow-up',
                'detail' => $widgets['tasks']['overdue_tasks'] . ' follow-up(s) past due date',
                'path' => '/follow-ups',
                'widget' => 'follow_ups',
            ];
        }

        if (($widgets['attendance']['uncaptured_past_occurrences'] ?? 0) > 0) {
            $actions[] = [
                'type' => 'uncaptured_attendance',
                'urgency_level' => 'high',
                'urgency_label' => $labels['high'] ?? 'High priority',
                'title' => 'Uncaptured attendance',
                'detail' => $widgets['attendance']['uncaptured_past_occurrences'] . ' past occurrence(s) need capture',
                'path' => '/team-attendance',
                'widget' => 'attendance',
            ];
        }

        if (($widgets['rosters']['unresolved_slots'] ?? 0) > 0) {
            $actions[] = [
                'type' => 'roster_response',
                'urgency_level' => 'high',
                'urgency_label' => $labels['high'] ?? 'High priority',
                'title' => 'Roster response needed',
                'detail' => $widgets['rosters']['unresolved_slots'] . ' slot(s) rejected or need replacement',
                'path' => '/team-rosters',
                'widget' => 'rosters',
            ];
        }

        if (($widgets['availability']['pending_responses'] ?? 0) > 0) {
            $actions[] = [
                'type' => 'roster_response',
                'urgency_level' => 'normal',
                'urgency_label' => $labels['normal'] ?? 'Needs attention',
                'title' => 'Awaiting member roster response',
                'detail' => $widgets['availability']['pending_responses'] . ' member(s) have not responded',
                'path' => '/team-rosters',
                'widget' => 'availability',
            ];
        }

        if (($widgets['reports']['returned_reports'] ?? 0) > 0) {
            $actions[] = [
                'type' => 'report_returned',
                'urgency_level' => 'high',
                'urgency_label' => $labels['high'] ?? 'High priority',
                'title' => 'Returned report',
                'detail' => $widgets['reports']['returned_reports'] . ' report(s) need revision',
                'path' => '/team-reports',
                'widget' => 'reports',
            ];
        }

        if (($widgets['reports']['draft_reports'] ?? 0) > 0) {
            $actions[] = [
                'type' => 'report_draft',
                'urgency_level' => 'normal',
                'urgency_label' => $labels['normal'] ?? 'Needs attention',
                'title' => 'Draft report due',
                'detail' => $widgets['reports']['draft_reports'] . ' draft report(s) to complete',
                'path' => '/team-reports',
                'widget' => 'reports',
            ];
        }

        if (($widgets['assignments']['pending_approvals'] ?? 0) > 0) {
            $actions[] = [
                'type' => 'pending_assignment',
                'urgency_level' => 'normal',
                'urgency_label' => $labels['normal'] ?? 'Needs attention',
                'title' => 'Pending assignment',
                'detail' => $widgets['assignments']['pending_approvals'] . ' assignment(s) awaiting action',
                'path' => '/service-teams',
                'widget' => 'assignments',
            ];
        }

        if (($widgets['issues']['open_issues'] ?? 0) > 0) {
            $actions[] = [
                'type' => 'open_incident',
                'urgency_level' => 'high',
                'urgency_label' => $labels['high'] ?? 'High priority',
                'title' => 'Open incident',
                'detail' => $widgets['issues']['open_issues'] . ' incident(s) need response',
                'path' => '/incidents',
                'widget' => 'issues',
            ];
        }

        if (($widgets['new_members']['recent_assignments'] ?? 0) > 0) {
            $actions[] = [
                'type' => 'new_member_welcome',
                'urgency_level' => 'normal',
                'urgency_label' => $labels['normal'] ?? 'Needs attention',
                'title' => 'Welcome new team members',
                'detail' => $widgets['new_members']['recent_assignments'] . ' recent assignment(s) on your team',
                'path' => '/follow-ups',
                'widget' => 'new_members',
            ];
        }

        usort($actions, fn (array $a, array $b) => match (true) {
            $a['urgency_level'] === 'critical' && $b['urgency_level'] !== 'critical' => -1,
            $b['urgency_level'] === 'critical' && $a['urgency_level'] !== 'critical' => 1,
            $a['urgency_level'] === 'high' && $b['urgency_level'] === 'normal' => -1,
            $b['urgency_level'] === 'high' && $a['urgency_level'] === 'normal' => 1,
            default => 0,
        });

        return $actions;
    }

    private function computeVersion(ServiceTeam $team, User $actor): string
    {
        $openFollowUps = FollowUp::query()
            ->where('assignee_id', $actor->id)
            ->where('branch_id', $team->branch_id)
            ->whereIn('status', [FollowUp::STATUS_ASSIGNED, FollowUp::STATUS_IN_PROGRESS, FollowUp::STATUS_ESCALATED])
            ->count();

        $parts = [
            (string) $team->updated_at?->timestamp,
            (string) ServiceTeamAssignment::query()->where('service_team_id', $team->id)->max('updated_at'),
            (string) TeamReport::query()->where('service_team_id', $team->id)->max('updated_at'),
            (string) FollowUp::query()->where('assignee_id', $actor->id)->where('branch_id', $team->branch_id)->max('updated_at'),
            (string) TeamRoster::query()->where('service_team_id', $team->id)->max('updated_at'),
            (string) TeamOccurrence::query()->where('service_team_id', $team->id)->max('updated_at'),
            'followups:' . $openFollowUps,
        ];

        return substr(hash('sha256', implode('|', $parts)), 0, 16);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function drillAvailability(User $actor, ServiceTeam $team, array $filters): array
    {
        if (! $this->allows($actor, 'teams.rosters.read')) {
            return [];
        }

        $windowEnd = now()->addDays((int) config('team_dashboard.upcoming_window_days', 14))->toDateString();

        return TeamRosterSlot::query()
            ->with(['member:id,first_name,last_name', 'roster:id,title'])
            ->whereHas('roster', fn (Builder $q) => $q->where('service_team_id', $team->id)->where('status', TeamRoster::STATUS_PUBLISHED))
            ->whereBetween('shift_date', [now()->toDateString(), $windowEnd])
            ->orderBy('shift_date')
            ->limit($this->limit('rosters'))
            ->get()
            ->map(fn (TeamRosterSlot $slot) => [
                'id' => $slot->id,
                'member_name' => $slot->member?->fullName(),
                'duty_label' => $slot->duty_label,
                'shift_date' => $slot->shift_date?->toDateString(),
                'member_response' => $slot->member_response,
                'responded_at' => $slot->responded_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function drillServices(User $actor, ServiceTeam $team, array $filters): array
    {
        if (! $this->allows($actor, 'events.read')) {
            return [];
        }

        $windowEnd = now()->addDays((int) config('team_dashboard.upcoming_window_days', 14))->toDateString();

        return ChurchService::query()
            ->where('branch_id', $team->branch_id)
            ->where('status', ChurchService::STATUS_PUBLISHED)
            ->whereBetween('service_date', [now()->toDateString(), $windowEnd])
            ->orderBy('service_date')
            ->limit($this->limit('events'))
            ->get(['id', 'title', 'service_date', 'start_time', 'venue'])
            ->map(fn (ChurchService $service) => [
                'id' => $service->id,
                'title' => $service->title,
                'service_date' => $service->service_date?->toDateString(),
                'start_time' => $service->start_time,
                'venue' => $service->venue,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function drillNewMembers(User $actor, ServiceTeam $team, array $filters): array
    {
        if (! $this->allows($actor, 'teams.assignments.read')) {
            return [];
        }

        $since = now()->subDays((int) config('team_dashboard.new_member_window_days', 30))->toDateString();

        return ServiceTeamAssignment::query()
            ->with('member:id,first_name,last_name,membership_id')
            ->where('service_team_id', $team->id)
            ->whereDate('effective_from', '>=', $since)
            ->orderByDesc('effective_from')
            ->limit($this->limit('membership'))
            ->get()
            ->map(fn (ServiceTeamAssignment $assignment) => [
                'id' => $assignment->id,
                'member_name' => $assignment->member?->fullName(),
                'team_role' => $assignment->team_role,
                'status' => $assignment->status,
                'effective_from' => $assignment->effective_from?->toDateString(),
            ])
            ->values()
            ->all();
    }

    private function limit(string $widget): int
    {
        $limits = config('team_dashboard.drill_down_limits', []);

        return (int) ($limits[$widget] ?? $limits['default'] ?? 25);
    }

    private function allows(User $actor, string $action): bool
    {
        return $action !== '' && $this->authorization->allows($actor, $action);
    }

    private function isTeamLeader(User $actor, ServiceTeam $team): bool
    {
        foreach ($team->leaders ?? [] as $leader) {
            if ((int) ($leader['user_id'] ?? 0) === (int) $actor->id) {
                return true;
            }
        }

        return false;
    }

    private function assertTeamAccessible(User $actor, ServiceTeam $team): void
    {
        if ($team->status !== ServiceTeam::STATUS_ACTIVE) {
            throw ValidationException::withMessages(['team' => ['Dashboard is only available for active teams.']]);
        }

        if ($this->isTeamLeader($actor, $team)) {
            $this->assertTeamInScope($actor, $team);

            return;
        }

        if (! $this->authorization->allows($actor, 'teams.read')) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }

        $this->assertTeamInScope($actor, $team);
    }

    private function assertCan(User $actor, string $action): void
    {
        if (! $this->authorization->allows($actor, $action)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function assertTeamInScope(User $actor, ServiceTeam $team): void
    {
        if ($actor->isChurchWide()) {
            return;
        }

        try {
            BranchScope::for($actor)->assertIncludes((int) $team->branch_id);
        } catch (BranchScopeException) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function applyBranchScope(Builder $query, User $actor): void
    {
        if ($actor->isChurchWide()) {
            return;
        }

        try {
            $scope = BranchScope::for($actor);
            $query->whereIn('branch_id', $scope->subtreeIds((int) $scope->branchId()));
        } catch (BranchScopeException) {
            $query->whereRaw('1 = 0');
        }
    }
}
