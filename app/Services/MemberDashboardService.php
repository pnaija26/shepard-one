<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\ChurchEvent;
use App\Models\ChurchEventRegistration;
use App\Models\ChurchGroupMembership;
use App\Models\ChurchService;
use App\Models\HouseholdMembership;
use App\Models\Member;
use App\Models\MemberNotification;
use App\Models\NewsletterDelivery;
use App\Models\OnboardingEnrollment;
use App\Models\Organization;
use App\Models\PrayerRequest;
use App\Models\ServiceTeamAssignment;
use App\Models\TeamRosterSlot;
use App\Models\User;
use App\Models\WelfareRequest;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Story 12.2: member web and mobile dashboard aggregation.
 */
class MemberDashboardService
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
        $member = $this->resolveMember($actor);
        $device = (string) ($filters['device'] ?? 'web');

        $sections = collect(config('member_dashboard.sections', []))
            ->map(fn (array $meta, string $key) => $this->buildSection($key, $meta, $actor, $member, $device))
            ->sortBy('priority')
            ->values()
            ->all();

        return [
            'generated_at' => now()->toIso8601String(),
            'member_linked' => $member !== null,
            'device' => $device,
            'session_policy' => config('member_dashboard.session_policy'),
            'sections' => $sections,
            'quick_actions' => $this->quickActions($actor, $member),
            'recovery' => config('member_dashboard.recovery_actions'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSection(string $key, array $meta, User $actor, ?Member $member, string $device): array
    {
        $section = [
            'key' => $key,
            'label' => $meta['label'] ?? ucfirst($key),
            'priority' => (int) ($meta['priority'] ?? 999),
            'available' => true,
            'state' => 'empty',
            'summary' => [],
            'highlights' => [],
            'actions' => [],
        ];

        return match ($key) {
            'profile' => $this->profileSection($section, $actor, $member),
            'family' => $this->familySection($section, $actor, $member),
            'schedule' => $this->scheduleSection($section, $actor, $member),
            'assignments' => $this->assignmentsSection($section, $actor, $member),
            'groups' => $this->groupsSection($section, $actor, $member),
            'teams' => $this->teamsSection($section, $actor, $member),
            'attendance' => $this->attendanceSection($section, $actor, $member),
            'giving' => $this->givingSection($section, $actor, $member),
            'welfare' => $this->welfareSection($section, $actor, $member),
            'messages' => $this->messagesSection($section, $actor),
            'newsletters' => $this->newslettersSection($section, $actor, $member),
            'prayer' => $this->prayerSection($section, $actor, $member),
            'care' => $this->careSection($section, $actor, $member),
            default => $section,
        };
    }

    /**
     * @param  array<string, mixed>  $section
   * @return array<string, mixed>
     */
    private function profileSection(array $section, User $actor, ?Member $member): array
    {
        if ($member === null) {
            return $this->unavailable($section, 'Link a member profile to see your church details.', [
                ['label' => 'My profile', 'path' => '/my-profile'],
            ]);
        }

        $branch = $member->branch ?? Organization::query()->find($member->branch_id);
        $pastor = $branch?->primary_contact['name'] ?? null;

        $pendingChanges = $member->changeRequests()
            ->where('status', 'pending')
            ->count();

        $onboarding = OnboardingEnrollment::query()
            ->where('subject_type', Member::class)
            ->where('subject_id', $member->id)
            ->where('status', OnboardingEnrollment::STATUS_ACTIVE)
            ->count();

        $section['state'] = 'ready';
        $section['summary'] = [
            'display_name' => trim($member->preferred_name ?: ($member->first_name . ' ' . $member->last_name)),
            'membership_status' => $member->membership_status,
            'lifecycle_status' => $member->lifecycle_status,
            'branch' => $branch?->name,
            'pastor' => $pastor,
            'pending_profile_changes' => $pendingChanges,
            'active_onboarding_journeys' => $onboarding,
        ];
        $section['actions'] = [
            ['label' => 'Edit profile', 'path' => '/my-profile'],
            ['label' => 'Membership card', 'path' => '/membership-card'],
            ['label' => 'Directory privacy', 'path' => '/directory-privacy'],
        ];

        return $section;
    }

    /**
     * @param  array<string, mixed>  $section
   * @return array<string, mixed>
     */
    private function familySection(array $section, User $actor, ?Member $member): array
    {
        if ($member === null) {
            return $this->unavailable($section, 'Family information requires a linked member profile.');
        }

        $membership = HouseholdMembership::activeForMember($member->id);
        if ($membership === null) {
            $section['state'] = 'empty';
            $section['summary'] = ['household' => null, 'members' => 0];
            $section['actions'] = [['label' => 'View directory', 'path' => '/directory']];

            return $section;
        }

        $household = $membership->household()->with(['memberships' => fn ($q) => $q->whereNull('ended_at')->with('member:id,first_name,last_name,preferred_name')])->first();
        $members = $household?->memberships ?? collect();

        $section['state'] = 'ready';
        $section['summary'] = [
            'household_name' => $household?->name,
            'relationship' => $membership->relationship_type,
            'member_count' => $members->count(),
        ];
        $section['highlights'] = $members->take(5)->map(fn (HouseholdMembership $row) => [
            'name' => trim(($row->member?->preferred_name ?: $row->member?->first_name) . ' ' . ($row->member?->last_name ?? '')),
            'relationship' => $row->relationship_type,
        ])->values()->all();
        $section['actions'] = [['label' => 'View directory', 'path' => '/directory']];

        return $section;
    }

    /**
     * @param  array<string, mixed>  $section
   * @return array<string, mixed>
     */
    private function scheduleSection(array $section, User $actor, ?Member $member): array
    {
        if ($member === null) {
            return $this->unavailable($section, 'Schedule requires a linked member profile.');
        }

        $windowEnd = now()->addDays((int) config('member_dashboard.upcoming_window_days', 14))->toDateString();
        $branchId = (int) $member->branch_id;

        $services = ChurchService::query()
            ->where('branch_id', $branchId)
            ->where('status', ChurchService::STATUS_PUBLISHED)
            ->whereBetween('service_date', [now()->toDateString(), $windowEnd])
            ->orderBy('service_date')
            ->limit(5)
            ->get(['id', 'title', 'service_date', 'start_time', 'venue']);

        $events = ChurchEvent::query()
            ->where('branch_id', $branchId)
            ->where('status', ChurchEvent::STATUS_PUBLISHED)
            ->where('event_date', '>=', now()->toDateString())
            ->where('event_date', '<=', $windowEnd)
            ->orderBy('event_date')
            ->limit(5)
            ->get(['id', 'title', 'event_date', 'start_time', 'venue']);

        $registrations = ChurchEventRegistration::query()
            ->where('person_type', Member::class)
            ->where('person_id', $member->id)
            ->where('status', ChurchEventRegistration::STATUS_CONFIRMED)
            ->whereHas('event', fn ($q) => $q->where('event_date', '>=', now()->toDateString()))
            ->with('event:id,title,event_date,start_time')
            ->limit(5)
            ->get();

        $highlights = $services->map(fn (ChurchService $s) => [
            'type' => 'service',
            'title' => $s->title,
            'date' => $s->service_date?->toDateString(),
            'time' => $s->start_time,
            'venue' => $s->venue,
        ])->merge($events->map(fn (ChurchEvent $e) => [
            'type' => 'event',
            'title' => $e->title,
            'date' => $e->event_date?->toDateString(),
            'time' => $e->start_time,
            'venue' => $e->venue,
        ]))->sortBy('date')->take(6)->values()->all();

        $section['state'] = count($highlights) === 0 ? 'empty' : 'ready';
        $section['summary'] = [
            'upcoming_services' => $services->count(),
            'upcoming_events' => $events->count(),
            'my_registrations' => $registrations->count(),
        ];
        $section['highlights'] = $highlights;
        $section['actions'] = [
            ['label' => 'Church services', 'path' => '/services'],
            ['label' => 'Events', 'path' => '/events'],
            ['label' => 'My roster', 'path' => '/my-roster-assignments'],
        ];

        return $section;
    }

    /**
     * @param  array<string, mixed>  $section
   * @return array<string, mixed>
     */
    private function assignmentsSection(array $section, User $actor, ?Member $member): array
    {
        if ($member === null) {
            return $this->unavailable($section, 'Assignments require a linked member profile.');
        }

        $pendingRoster = TeamRosterSlot::query()
            ->where('member_id', $member->id)
            ->whereNull('responded_at')
            ->where('shift_date', '>=', now()->toDateString())
            ->count();

        $upcomingRoster = TeamRosterSlot::query()
            ->where('member_id', $member->id)
            ->where('member_response', 'accepted')
            ->where('shift_date', '>=', now()->toDateString())
            ->with(['roster:id,period_start,service_team_id', 'roster.team:id,name'])
            ->orderBy('shift_date')
            ->limit(5)
            ->get();

        $activeTeams = ServiceTeamAssignment::query()
            ->where('member_id', $member->id)
            ->whereIn('status', config('team_assignments.active_statuses', ['active', 'scheduled']))
            ->with('team:id,name')
            ->limit(5)
            ->get();

        $section['state'] = ($pendingRoster + $upcomingRoster->count() + $activeTeams->count()) === 0 ? 'empty' : 'ready';
        $section['summary'] = [
            'pending_roster_responses' => $pendingRoster,
            'accepted_upcoming_slots' => $upcomingRoster->count(),
            'active_team_assignments' => $activeTeams->count(),
        ];
        $section['highlights'] = $upcomingRoster->map(fn (TeamRosterSlot $slot) => [
            'team' => $slot->roster?->team?->name,
            'date' => $slot->shift_date?->toDateString() ?? $slot->roster?->period_start?->toDateString(),
            'duty' => $slot->duty_label,
            'response' => $slot->member_response,
        ])->values()->all();
        $section['actions'] = [
            ['label' => 'Roster assignments', 'path' => '/my-roster-assignments'],
            ['label' => 'Volunteer profile', 'path' => '/my-volunteer-profile'],
        ];

        return $section;
    }

    /**
     * @param  array<string, mixed>  $section
   * @return array<string, mixed>
     */
    private function groupsSection(array $section, User $actor, ?Member $member): array
    {
        if ($member === null) {
            return $this->unavailable($section, 'Groups require a linked member profile.');
        }

        $groups = ChurchGroupMembership::query()
            ->where('member_id', $member->id)
            ->where('status', ChurchGroupMembership::STATUS_ACTIVE)
            ->with('group:id,name,group_type')
            ->limit(8)
            ->get();

        $section['state'] = $groups->isEmpty() ? 'empty' : 'ready';
        $section['summary'] = ['active_groups' => $groups->count()];
        $section['highlights'] = $groups->map(fn (ChurchGroupMembership $row) => [
            'name' => $row->group?->name,
            'role' => $row->role,
            'group_type' => $row->group?->group_type,
        ])->values()->all();
        $section['actions'] = [['label' => 'My groups', 'path' => '/groups']];

        return $section;
    }

    /**
     * @param  array<string, mixed>  $section
   * @return array<string, mixed>
     */
    private function teamsSection(array $section, User $actor, ?Member $member): array
    {
        if ($member === null) {
            return $this->unavailable($section, 'Teams require a linked member profile.');
        }

        $assignments = ServiceTeamAssignment::query()
            ->where('member_id', $member->id)
            ->whereIn('status', config('team_assignments.active_statuses', ['active', 'scheduled', 'pending']))
            ->with('team:id,name,category')
            ->orderByDesc('effective_from')
            ->limit(8)
            ->get();

        $section['state'] = $assignments->isEmpty() ? 'empty' : 'ready';
        $section['summary'] = ['teams' => $assignments->count()];
        $section['highlights'] = $assignments->map(fn (ServiceTeamAssignment $row) => [
            'team' => $row->team?->name,
            'role' => $row->team_role,
            'status' => $row->status,
        ])->values()->all();
        $section['actions'] = [['label' => 'Volunteer profile', 'path' => '/my-volunteer-profile']];

        return $section;
    }

    /**
     * @param  array<string, mixed>  $section
   * @return array<string, mixed>
     */
    private function attendanceSection(array $section, User $actor, ?Member $member): array
    {
        if ($member === null) {
            return $this->unavailable($section, 'Attendance history requires a linked member profile.');
        }

        $from = now()->subDays((int) config('member_dashboard.attendance_lookback_days', 90))->toDateString();

        $records = AttendanceRecord::query()
            ->where('subject_type', Member::class)
            ->where('subject_id', $member->id)
            ->where('gathering_date', '>=', $from)
            ->orderByDesc('gathering_date')
            ->limit(6)
            ->get(['gathering_date', 'status', 'service_type', 'sync_status']);

        $present = $records->where('status', 'present')->count();
        $last = $records->first();

        $section['state'] = $records->isEmpty() ? 'empty' : 'ready';
        $section['summary'] = [
            'records_in_period' => $records->count(),
            'present_count' => $present,
            'last_gathering_date' => $last?->gathering_date?->toDateString(),
            'last_status' => $last?->status,
        ];
        $section['highlights'] = $records->map(fn (AttendanceRecord $row) => [
            'date' => $row->gathering_date?->toDateString(),
            'status' => $row->status,
            'service_type' => $row->service_type,
        ])->values()->all();
        $section['actions'] = [['label' => 'Membership card check-in', 'path' => '/membership-card']];

        return $section;
    }

    /**
     * @param  array<string, mixed>  $section
   * @return array<string, mixed>
     */
    private function givingSection(array $section, User $actor, ?Member $member): array
    {
        if (! config('payments.member_giving_enabled', true)) {
            return $this->unavailable($section, 'Giving is not enabled for members on this church.');
        }

        if (! $this->allows($actor, 'payments.giving.self')) {
            return $this->unauthorized($section);
        }

        if ($member === null) {
            return $this->unavailable($section, 'Link a member profile to view giving.');
        }

        $section['state'] = 'ready';
        $section['summary'] = [
            'enabled' => true,
            'path' => '/my-giving',
        ];
        $section['actions'] = [
            ['label' => 'View giving history', 'path' => '/my-giving'],
        ];

        return $section;
    }

    /**
     * @param  array<string, mixed>  $section
   * @return array<string, mixed>
     */
    private function welfareSection(array $section, User $actor, ?Member $member): array
    {
        if (! $this->allows($actor, 'welfare.requests.read.self') && ! $this->allows($actor, 'welfare.requests.submit.self')) {
            return $this->unauthorized($section);
        }

        if ($member === null) {
            return $this->unavailable($section, 'Welfare requests require a linked member profile.');
        }

        $open = WelfareRequest::query()
            ->where(function ($q) use ($member, $actor): void {
                $q->where('requester_member_id', $member->id)
                    ->orWhere('requester_user_id', $actor->id)
                    ->orWhere('beneficiary_member_id', $member->id);
            })
            ->whereNotIn('status', [WelfareRequest::STATUS_CLOSED, WelfareRequest::STATUS_DRAFT])
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get(['id', 'case_number', 'status', 'beneficiary_status_message', 'updated_at']);

        $drafts = WelfareRequest::query()
            ->where('requester_user_id', $actor->id)
            ->where('status', WelfareRequest::STATUS_DRAFT)
            ->count();

        $section['state'] = $open->isEmpty() && $drafts === 0 ? 'empty' : 'ready';
        $section['summary'] = [
            'open_requests' => $open->count(),
            'draft_requests' => $drafts,
        ];
        $section['highlights'] = $open->map(fn (WelfareRequest $row) => [
            'case_number' => $row->case_number,
            'status' => $row->status,
            'message' => $row->beneficiary_status_message,
        ])->values()->all();
        $section['actions'] = [['label' => 'Welfare requests', 'path' => '/welfare']];

        return $section;
    }

    /**
     * @param  array<string, mixed>  $section
   * @return array<string, mixed>
     */
    private function messagesSection(array $section, User $actor): array
    {
        if (! $this->allows($actor, 'notifications.inbox')) {
            return $this->unauthorized($section);
        }

        $unread = MemberNotification::query()
            ->where('user_id', $actor->id)
            ->whereNull('archived_at')
            ->whereNull('read_at')
            ->count();

        $recent = MemberNotification::query()
            ->where('user_id', $actor->id)
            ->whereNull('archived_at')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['id', 'title', 'category', 'read_at', 'created_at']);

        $section['state'] = $recent->isEmpty() ? 'empty' : 'ready';
        $section['summary'] = ['unread_count' => $unread];
        $section['highlights'] = $recent->map(fn (MemberNotification $n) => [
            'id' => $n->id,
            'message' => $n->message,
            'category' => $n->category,
            'read' => $n->read_at !== null,
            'created_at' => $n->created_at?->toIso8601String(),
        ])->values()->all();
        $section['actions'] = [['label' => 'Open inbox', 'path' => '/notifications']];

        return $section;
    }

    /**
     * @param  array<string, mixed>  $section
   * @return array<string, mixed>
     */
    private function newslettersSection(array $section, User $actor, ?Member $member): array
    {
        if ($member === null) {
            return $this->unavailable($section, 'Newsletters require a linked member profile.');
        }

        $deliveries = NewsletterDelivery::query()
            ->where('member_id', $member->id)
            ->where('is_test', false)
            ->whereIn('status', [NewsletterDelivery::STATUS_SENT, NewsletterDelivery::STATUS_DELIVERED])
            ->with('newsletter:id,name,sent_at')
            ->orderByDesc('sent_at')
            ->limit(5)
            ->get();

        $section['state'] = $deliveries->isEmpty() ? 'empty' : 'ready';
        $section['summary'] = ['recent_deliveries' => $deliveries->count()];
        $section['highlights'] = $deliveries->map(fn (NewsletterDelivery $d) => [
            'newsletter' => $d->newsletter?->name,
            'status' => $d->status,
            'sent_at' => $d->sent_at?->toIso8601String(),
        ])->values()->all();

        if ($this->allows($actor, 'newsletters.read')) {
            $section['actions'] = [['label' => 'Newsletters', 'path' => '/newsletters']];
        }

        return $section;
    }

    /**
     * @param  array<string, mixed>  $section
   * @return array<string, mixed>
     */
    private function prayerSection(array $section, User $actor, ?Member $member): array
    {
        if (! $this->allows($actor, 'prayer.requests.read.self') && ! $this->allows($actor, 'prayer.requests.submit.self')) {
            return $this->unauthorized($section);
        }

        if ($member === null) {
            return $this->unavailable($section, 'Prayer requests require a linked member profile.');
        }

        $requests = PrayerRequest::query()
            ->where('requester_member_id', $member->id)
            ->whereNotIn('status', [PrayerRequest::STATUS_WITHDRAWN, PrayerRequest::STATUS_CLOSED])
            ->orderByDesc('submitted_at')
            ->limit(5)
            ->get(['id', 'reference', 'status', 'category', 'submitted_at']);

        $section['state'] = $requests->isEmpty() ? 'empty' : 'ready';
        $section['summary'] = ['open_requests' => $requests->count()];
        $section['highlights'] = $requests->map(fn (PrayerRequest $r) => [
            'reference' => $r->reference,
            'status' => $r->status,
            'category' => $r->category,
        ])->values()->all();
        $section['actions'] = [['label' => 'Prayer requests', 'path' => '/prayer']];

        return $section;
    }

    /**
     * @param  array<string, mixed>  $section
   * @return array<string, mixed>
     */
    private function careSection(array $section, User $actor, ?Member $member): array
    {
        // Members reach pastoral care through prayer/welfare; staff care cases stay behind care permissions.
        if ($this->allows($actor, 'care.cases.read')) {
            $section['state'] = 'ready';
            $section['summary'] = ['staff_care_access' => true];
            $section['actions'] = [['label' => 'Pastoral care cases', 'path' => '/care']];

            return $section;
        }

        $hasPrayer = $this->allows($actor, 'prayer.requests.submit.self');
        $hasWelfare = $this->allows($actor, 'welfare.requests.submit.self');

        if (! $hasPrayer && ! $hasWelfare) {
            return $this->unauthorized($section);
        }

        $section['state'] = 'ready';
        $section['summary'] = [
            'message' => 'Reach your care team through prayer or welfare requests.',
        ];
        $section['actions'] = array_values(array_filter([
            $hasPrayer ? ['label' => 'Submit prayer request', 'path' => '/prayer'] : null,
            $hasWelfare ? ['label' => 'Submit welfare request', 'path' => '/welfare'] : null,
        ]));

        return $section;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function quickActions(User $actor, ?Member $member): array
    {
        return collect(config('member_dashboard.quick_actions', []))
            ->filter(function (array $action) use ($actor, $member): bool {
                if (($action['requires_member'] ?? false) && $member === null) {
                    return false;
                }
                $permission = $action['permission'] ?? null;
                if ($permission && ! $this->allows($actor, $permission)) {
                    return false;
                }
                if (($action['key'] ?? '') === 'giving' && ! config('payments.member_giving_enabled', true)) {
                    return false;
                }

                return true;
            })
            ->sortBy('priority')
            ->map(fn (array $action) => [
                'key' => $action['key'],
                'label' => $action['label'],
                'path' => $action['path'],
            ])
            ->values()
            ->all();
    }

    private function resolveMember(User $actor): ?Member
    {
        return Member::query()->where('user_id', $actor->id)->first();
    }

    /**
     * @param  array<string, mixed>  $section
     * @param  array<int, array<string, string>>  $actions
   * @return array<string, mixed>
     */
    private function unavailable(array $section, string $message, array $actions = []): array
    {
        $section['available'] = false;
        $section['state'] = 'unavailable';
        $section['summary'] = ['message' => $message];
        $section['actions'] = $actions;
        $section['recovery'] = 'link_profile';

        return $section;
    }

    /**
     * @param  array<string, mixed>  $section
   * @return array<string, mixed>
     */
    private function unauthorized(array $section): array
    {
        $section['available'] = false;
        $section['state'] = 'unauthorized';
        $section['summary'] = [];
        $section['highlights'] = [];
        $section['actions'] = [];

        return $section;
    }

    private function allows(User $actor, string $action): bool
    {
        return $action !== '' && $this->authorization->allows($actor, $action);
    }
}
