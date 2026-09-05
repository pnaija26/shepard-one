<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\ChurchGroup;
use App\Models\ChurchGroupMeeting;
use App\Models\ChurchGroupMeetingAttendance;
use App\Models\ChurchGroupMembership;
use App\Models\FollowUp;
use App\Models\Member;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Story 6.2: group meetings, participation records, and follow-up triggers.
 */
class GroupMeetingService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
        private FollowUpService $followUps,
    ) {
    }

    /**
     * @return Collection<int, ChurchGroupMeeting>
     */
    public function listMeetings(User $actor, ChurchGroup $group): Collection
    {
        $this->assertCan($actor, 'groups.meetings.read');
        $this->assertGroupInScope($actor, $group);

        return ChurchGroupMeeting::query()
            ->withCount('attendance')
            ->where('church_group_id', $group->id)
            ->orderByDesc('scheduled_at')
            ->limit(100)
            ->get();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function scheduleMeeting(User $actor, ChurchGroup $group, array $payload): ChurchGroupMeeting
    {
        $this->assertCanManageMeetings($actor, $group);
        $this->assertGroupActive($group);

        $validated = validator($payload, [
            'title' => ['required', 'string', 'max:160'],
            'meeting_type' => ['nullable', 'string', 'in:' . implode(',', config('group_meetings.meeting_types', []))],
            'scheduled_at' => ['required', 'date'],
            'location' => ['nullable', 'string', 'max:160'],
        ])->validate();

        $meeting = ChurchGroupMeeting::create([
            'church_group_id' => $group->id,
            'branch_id' => $group->branch_id,
            'title' => $validated['title'],
            'meeting_type' => $validated['meeting_type'] ?? 'meeting',
            'scheduled_at' => $validated['scheduled_at'],
            'status' => ChurchGroupMeeting::STATUS_SCHEDULED,
            'location' => $validated['location'] ?? null,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        $this->audit($actor, 'group_meeting.scheduled', $group, $meeting);

        return $meeting;
    }

    public function showMeeting(User $actor, ChurchGroupMeeting $meeting): ChurchGroupMeeting
    {
        $this->assertCan($actor, 'groups.meetings.read');
        $this->assertMeetingInScope($actor, $meeting);

        return $meeting->load([
            'group:id,name,branch_id',
            'attendance.member:id,first_name,last_name,membership_id',
            'attendance.visitor:id,first_name,last_name',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function recordActivity(User $actor, ChurchGroupMeeting $meeting, array $payload): ChurchGroupMeeting
    {
        $this->assertCanManageMeetings($actor, $meeting->group);
        $this->assertMeetingInScope($actor, $meeting);

        if ($meeting->status === ChurchGroupMeeting::STATUS_CANCELLED) {
            throw ValidationException::withMessages(['status' => ['Cancelled meetings cannot be recorded.']]);
        }

        $validated = validator($payload, [
            'notes' => ['nullable', 'string', 'max:5000'],
            'sensitive_notes' => ['nullable', 'string', 'max:5000'],
            'prayer_needs' => ['nullable', 'array'],
            'prayer_needs.*.subject' => ['required_with:prayer_needs', 'string', 'max:160'],
            'prayer_needs.*.detail' => ['nullable', 'string', 'max:2000'],
            'prayer_needs.*.classification' => ['required_with:prayer_needs', 'string', 'in:' . implode(',', config('group_meetings.confidentiality_levels', []))],
            'actions' => ['nullable', 'array'],
            'actions.*.title' => ['required_with:actions', 'string', 'max:200'],
            'actions.*.assignee_id' => ['nullable', 'integer', 'exists:users,id'],
            'actions.*.due_date' => ['nullable', 'date'],
            'actions.*.status' => ['nullable', 'string', 'in:' . implode(',', config('group_meetings.action_statuses', []))],
            'report_fields' => ['nullable', 'array'],
            'attendance' => ['required', 'array', 'min:1'],
            'attendance.*.member_id' => ['nullable', 'integer', 'exists:members,id'],
            'attendance.*.visitor_id' => ['nullable', 'integer', 'exists:visitors,id'],
            'attendance.*.person_name' => ['nullable', 'string', 'max:160'],
            'attendance.*.status' => ['required', 'string', 'in:' . implode(',', config('group_meetings.attendance_statuses', []))],
            'attendance.*.notes' => ['nullable', 'string', 'max:500'],
        ])->validate();

        if (! empty($validated['sensitive_notes']) && ! $this->authorization->allows($actor, 'groups.sensitive.read')) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }

        return DB::transaction(function () use ($actor, $meeting, $validated): ChurchGroupMeeting {
            $meeting->attendance()->delete();

            foreach ($validated['attendance'] as $entry) {
                if (empty($entry['member_id']) && empty($entry['visitor_id']) && empty($entry['person_name'])) {
                    throw ValidationException::withMessages(['attendance' => ['Each attendance row needs a member, visitor, or person name.']]);
                }

                ChurchGroupMeetingAttendance::create([
                    'church_group_meeting_id' => $meeting->id,
                    'member_id' => $entry['member_id'] ?? null,
                    'visitor_id' => $entry['visitor_id'] ?? null,
                    'person_name' => $entry['person_name'] ?? null,
                    'status' => $entry['status'],
                    'notes' => $entry['notes'] ?? null,
                ]);
            }

            $meeting->update([
                'notes' => $validated['notes'] ?? null,
                'sensitive_notes' => $validated['sensitive_notes'] ?? null,
                'prayer_needs' => $validated['prayer_needs'] ?? [],
                'actions' => $this->normalizeActions($validated['actions'] ?? []),
                'report_fields' => $validated['report_fields'] ?? [],
                'status' => ChurchGroupMeeting::STATUS_COMPLETED,
                'completed_at' => now(),
                'report_submitted_at' => now(),
                'submitted_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->audit($actor, 'group_meeting.recorded', $meeting->group, $meeting);

            return $meeting->fresh(['attendance.member:id,first_name,last_name', 'attendance.visitor:id,first_name,last_name']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function correctAttendance(User $actor, ChurchGroupMeetingAttendance $record, array $payload): ChurchGroupMeetingAttendance
    {
        $meeting = $record->meeting ?? ChurchGroupMeeting::query()->findOrFail($record->church_group_meeting_id);
        $group = $meeting->group ?? ChurchGroup::query()->findOrFail($meeting->church_group_id);

        $this->assertCanManageMeetings($actor, $group);
        $this->assertMeetingInScope($actor, $meeting);

        $validated = validator($payload, [
            'status' => ['required', 'string', 'in:' . implode(',', config('group_meetings.attendance_statuses', []))],
            'correction_reason' => ['required', 'string', 'max:500'],
        ])->validate();

        if ($record->status === $validated['status']) {
            throw ValidationException::withMessages(['status' => ['Attendance status is unchanged.']]);
        }

        $record->update([
            'corrected_from_status' => $record->corrected_from_status ?? $record->status,
            'status' => $validated['status'],
            'correction_reason' => $validated['correction_reason'],
            'corrected_by' => $actor->id,
            'corrected_at' => now(),
        ]);

        $this->audit($actor, 'group_meeting.attendance_corrected', $group, $meeting, [
            'attendance_id' => $record->id,
            'status' => $record->status,
        ]);

        return $record->fresh(['member:id,first_name,last_name', 'visitor:id,first_name,last_name']);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function evaluateFollowUps(User $actor, ChurchGroupMeeting $meeting, array $payload): array
    {
        $group = $meeting->group ?? ChurchGroup::query()->findOrFail($meeting->church_group_id);
        $this->assertCanManageMeetings($actor, $group);
        $this->assertMeetingInScope($actor, $meeting);

        $validated = validator($payload, [
            'assignee_id' => ['required', 'integer', 'exists:users,id'],
        ])->validate();

        $created = [];
        $meeting->load('attendance.member', 'attendance.visitor');

        foreach ($meeting->attendance as $record) {
            if ($record->member_id !== null && $this->hasConsecutiveAbsences($group, $record->member_id)) {
                $created[] = $this->createGroupFollowUp(
                    $actor,
                    $meeting,
                    Member::class,
                    $record->member_id,
                    (int) $validated['assignee_id'],
                    'Consecutive group meeting absences detected.',
                    'consecutive_absence',
                    in_array('pastoral', collect($meeting->prayer_needs ?? [])->pluck('classification')->all(), true),
                );
            }

            if ($record->visitor_id !== null) {
                $created[] = $this->createGroupFollowUp(
                    $actor,
                    $meeting,
                    Visitor::class,
                    $record->visitor_id,
                    (int) $validated['assignee_id'],
                    'Visitor attended group meeting and needs follow-up.',
                    'visitor_present',
                );
            }
        }

        foreach ($meeting->prayer_needs ?? [] as $need) {
            if (($need['classification'] ?? 'standard') === 'standard') {
                continue;
            }

            $memberId = $need['member_id'] ?? null;
            if ($memberId === null) {
                continue;
            }

            $created[] = $this->createGroupFollowUp(
                $actor,
                $meeting,
                Member::class,
                (int) $memberId,
                (int) $validated['assignee_id'],
                'Member need recorded during group meeting: ' . ($need['subject'] ?? 'Follow-up required'),
                'member_need',
                true,
            );
        }

        foreach ($this->overdueActions($group) as $action) {
            $memberId = $action['member_id'] ?? null;
            if ($memberId === null) {
                continue;
            }

            $created[] = $this->createGroupFollowUp(
                $actor,
                $meeting,
                Member::class,
                (int) $memberId,
                (int) $validated['assignee_id'],
                'Overdue group action: ' . ($action['title'] ?? 'Action item'),
                'overdue_action',
            );
        }

        return [
            'created' => count(array_filter($created)),
            'follow_ups' => array_values(array_filter($created)),
        ];
    }

  /**
     * @return array<string, mixed>
     */
    public function dashboardMetrics(User $actor, ChurchGroup $group): array
    {
        $this->assertCan($actor, 'groups.meetings.read');
        $this->assertGroupInScope($actor, $group);

        $meetings = ChurchGroupMeeting::query()
            ->where('church_group_id', $group->id)
            ->where('status', ChurchGroupMeeting::STATUS_COMPLETED)
            ->with('attendance')
            ->get();

        $attendanceRows = $meetings->flatMap(fn (ChurchGroupMeeting $meeting) => $meeting->attendance);
        $present = $attendanceRows->whereIn('status', [
            ChurchGroupMeetingAttendance::STATUS_PRESENT,
            ChurchGroupMeetingAttendance::STATUS_LATE,
        ])->count();

        $openFollowUps = FollowUp::query()
            ->where('branch_id', $group->branch_id)
            ->where('source_type', 'group_meeting')
            ->whereIn('status', config('follow_ups.open_statuses', []))
            ->whereIn('source_reference_id', $meetings->pluck('id'))
            ->count();

        $pendingActions = 0;
        $completedActions = 0;
        foreach ($meetings as $meeting) {
            foreach ($meeting->actions ?? [] as $action) {
                if (($action['status'] ?? 'pending') === 'completed') {
                    $completedActions++;
                } else {
                    $pendingActions++;
                }
            }
        }

        return [
            'completed_meetings' => $meetings->count(),
            'attendance_records' => $attendanceRows->count(),
            'attendance_rate' => $attendanceRows->count() > 0
                ? round(($present / $attendanceRows->count()) * 100, 1)
                : null,
            'corrected_attendance_records' => $attendanceRows->whereNotNull('corrected_at')->count(),
            'open_follow_ups' => $openFollowUps,
            'pending_actions' => $pendingActions,
            'completed_actions' => $completedActions,
        ];
    }

    public function formatMeeting(ChurchGroupMeeting $meeting, User $actor): array
    {
        $canReadSensitive = $this->authorization->allows($actor, 'groups.sensitive.read');

        return [
            'id' => $meeting->id,
            'church_group_id' => $meeting->church_group_id,
            'title' => $meeting->title,
            'meeting_type' => $meeting->meeting_type,
            'scheduled_at' => $meeting->scheduled_at?->toIso8601String(),
            'completed_at' => $meeting->completed_at?->toIso8601String(),
            'status' => $meeting->status,
            'location' => $meeting->location,
            'notes' => $meeting->notes,
            'sensitive_notes' => $canReadSensitive ? $meeting->sensitive_notes : null,
            'sensitive_notes_restricted' => ! $canReadSensitive && $meeting->sensitive_notes !== null,
            'prayer_needs' => $this->visiblePrayerNeeds($meeting->prayer_needs ?? [], $canReadSensitive),
            'actions' => $meeting->actions ?? [],
            'report_fields' => $meeting->report_fields ?? [],
            'attendance' => $meeting->relationLoaded('attendance')
                ? $meeting->attendance->map(fn (ChurchGroupMeetingAttendance $record) => [
                    'id' => $record->id,
                    'member_id' => $record->member_id,
                    'visitor_id' => $record->visitor_id,
                    'person_name' => $record->person_name ?? $record->member?->fullName() ?? $record->visitor?->fullName(),
                    'status' => $record->status,
                    'notes' => $record->notes,
                    'corrected_at' => $record->corrected_at?->toIso8601String(),
                ])->values()->all()
                : [],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $needs
     * @return array<int, array<string, mixed>>
     */
    private function visiblePrayerNeeds(array $needs, bool $canReadSensitive): array
    {
        return array_values(array_map(function (array $need) use ($canReadSensitive) {
            $classification = $need['classification'] ?? 'standard';
            if (! $canReadSensitive && in_array($classification, ['restricted', 'pastoral'], true)) {
                return [
                    'subject' => '[Restricted prayer need]',
                    'classification' => $classification,
                    'restricted' => true,
                ];
            }

            return $need;
        }, $needs));
    }

    /**
     * @param  array<int, array<string, mixed>>  $actions
     * @return array<int, array<string, mixed>>
     */
    private function normalizeActions(array $actions): array
    {
        return array_values(array_map(fn (array $action) => [
            'title' => $action['title'],
            'assignee_id' => $action['assignee_id'] ?? null,
            'due_date' => $action['due_date'] ?? null,
            'status' => $action['status'] ?? 'pending',
            'member_id' => $action['member_id'] ?? null,
        ], $actions));
    }

    private function hasConsecutiveAbsences(ChurchGroup $group, int $memberId): bool
    {
        $threshold = (int) config('group_meetings.consecutive_absence_threshold', 2);

        $recent = ChurchGroupMeetingAttendance::query()
            ->whereHas('meeting', fn (Builder $q) => $q
                ->where('church_group_id', $group->id)
                ->where('status', ChurchGroupMeeting::STATUS_COMPLETED))
            ->where('member_id', $memberId)
            ->orderByDesc('id')
            ->limit($threshold)
            ->pluck('status');

        return $recent->count() === $threshold
            && $recent->every(fn (string $status) => $status === ChurchGroupMeetingAttendance::STATUS_ABSENT);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function overdueActions(ChurchGroup $group): array
    {
        $actions = [];

        $meetings = ChurchGroupMeeting::query()
            ->where('church_group_id', $group->id)
            ->where('status', ChurchGroupMeeting::STATUS_COMPLETED)
            ->orderByDesc('scheduled_at')
            ->get(['id', 'actions']);

        foreach ($meetings as $meeting) {
            foreach ($meeting->actions ?? [] as $action) {
                if (($action['status'] ?? 'pending') !== 'pending') {
                    continue;
                }

                if (empty($action['due_date']) || now()->toDateString() > $action['due_date']) {
                    $actions[] = $action;
                }
            }
        }

        return $actions;
    }

    private function createGroupFollowUp(
        User $actor,
        ChurchGroupMeeting $meeting,
        string $personType,
        int $personId,
        int $assigneeId,
        string $reason,
        string $trigger,
        bool $restricted = false,
    ): ?FollowUp {
        if (FollowUp::query()
            ->where('source_reference_type', ChurchGroupMeeting::class)
            ->where('source_reference_id', $meeting->id)
            ->where('person_type', $personType)
            ->where('person_id', $personId)
            ->where('reason', $reason)
            ->exists()) {
            return null;
        }

        return $this->followUps->createFollowUp($actor, [
            'person_type' => $personType,
            'person_id' => $personId,
            'branch_id' => $meeting->branch_id,
            'reason' => $reason,
            'assignee_id' => $assigneeId,
            'due_date' => now()->addDays((int) config('group_meetings.default_follow_up_due_days', 3))->toDateString(),
            'contact_method' => 'phone',
            'priority' => $trigger === 'consecutive_absence' ? 'high' : 'normal',
            'source_type' => 'group_meeting',
            'source_reference_type' => ChurchGroupMeeting::class,
            'source_reference_id' => $meeting->id,
            'is_restricted' => $restricted,
        ]);
    }

    private function assertGroupActive(ChurchGroup $group): void
    {
        if ($group->status !== ChurchGroup::STATUS_ACTIVE) {
            throw ValidationException::withMessages(['group' => ['Meetings can only be scheduled for active groups.']]);
        }
    }

    private function assertCanManageMeetings(User $actor, ChurchGroup $group): void
    {
        if ($this->authorization->allows($actor, 'groups.meetings.manage')) {
            return;
        }

        if ($this->isGroupLeader($actor, $group) && $this->authorization->allows($actor, 'groups.meetings.read')) {
            return;
        }

        throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
    }

    private function isGroupLeader(User $actor, ChurchGroup $group): bool
    {
        foreach ($group->leaders ?? [] as $leader) {
            if ((int) ($leader['user_id'] ?? 0) === (int) $actor->id) {
                return true;
            }
        }

        return false;
    }

    private function assertCan(User $actor, string $action): void
    {
        if (! $this->authorization->allows($actor, $action)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function assertGroupInScope(User $actor, ChurchGroup $group): void
    {
        if ($actor->isChurchWide()) {
            return;
        }

        try {
            BranchScope::for($actor)->assertIncludes((int) $group->branch_id);
        } catch (BranchScopeException) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function assertMeetingInScope(User $actor, ChurchGroupMeeting $meeting): void
    {
        $group = $meeting->group ?? ChurchGroup::query()->findOrFail($meeting->church_group_id);
        $this->assertGroupInScope($actor, $group);
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function audit(User $actor, string $action, ChurchGroup $group, ?ChurchGroupMeeting $meeting = null, ?array $metadata = null): void
    {
        $this->audit->record(
            actor: $actor,
            action: $action,
            category: AuditEvent::CATEGORY_BUSINESS,
            module: 'groups',
            branchId: $group->branch_id,
            subjectType: ChurchGroupMeeting::class,
            subjectId: $meeting?->id ?? $group->id,
            before: null,
            after: array_filter([
                'church_group_id' => $group->id,
                'meeting_id' => $meeting?->id,
                'metadata' => $metadata,
            ]),
        );
    }
}
