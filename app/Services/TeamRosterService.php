<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Member;
use App\Models\MemberNotification;
use App\Models\ServiceTeam;
use App\Models\ServiceTeamAssignment;
use App\Models\TeamRoster;
use App\Models\TeamRosterEvent;
use App\Models\TeamRosterSlot;
use App\Models\User;
use App\Models\VolunteerProfile;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Story 5.4: publish team rosters with conflict checks and member responses.
 */
class TeamRosterService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
    ) {
    }

    /**
     * @return Collection<int, TeamRoster>
     */
    public function listRosters(User $actor, ServiceTeam $team): Collection
    {
        $this->assertCan($actor, 'teams.rosters.read');
        $this->assertTeamInScope($actor, $team);

        return TeamRoster::query()
            ->withCount('slots')
            ->where('service_team_id', $team->id)
            ->orderByDesc('period_start')
            ->limit(100)
            ->get();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createRoster(User $actor, ServiceTeam $team, array $payload): TeamRoster
    {
        $this->assertCan($actor, 'teams.rosters.manage');
        $this->assertTeamInScope($actor, $team);

        if ($team->status !== ServiceTeam::STATUS_ACTIVE) {
            throw ValidationException::withMessages(['team' => ['Rosters can only be created for active teams.']]);
        }

        $validated = $this->validateRosterPayload($payload);

        return DB::transaction(function () use ($actor, $team, $validated): TeamRoster {
            $roster = TeamRoster::create([
                'service_team_id' => $team->id,
                'branch_id' => $team->branch_id,
                'roster_type' => $validated['roster_type'],
                'title' => $validated['title'],
                'status' => TeamRoster::STATUS_DRAFT,
                'gathering_key' => $validated['gathering_key'] ?? null,
                'gathering_id' => $validated['gathering_id'] ?? null,
                'period_start' => $validated['period_start'],
                'period_end' => $validated['period_end'],
                'staffing_requirements' => $validated['staffing_requirements'] ?? $this->defaultStaffingRequirements($team),
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->recordEvent($actor, $roster, null, 'created', 'Roster created.');
            $this->audit($actor, 'team_roster.created', $roster);

            return $roster->fresh(['slots.member:id,first_name,last_name']);
        });
    }

    public function showRoster(User $actor, TeamRoster $roster): TeamRoster
    {
        $this->assertCan($actor, 'teams.rosters.read');
        $this->assertRosterInScope($actor, $roster);

        return $roster->load([
            'team:id,name,branch_id',
            'slots.member:id,first_name,last_name,membership_id',
            'slots.substituteMember:id,first_name,last_name',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function addSlot(User $actor, TeamRoster $roster, array $payload): TeamRosterSlot
    {
        $this->assertCan($actor, 'teams.rosters.manage');
        $this->assertRosterInScope($actor, $roster);

        if ($roster->status !== TeamRoster::STATUS_DRAFT) {
            throw ValidationException::withMessages(['roster' => ['Slots can only be added to draft rosters.']]);
        }

        $validated = validator($payload, [
            'member_id' => ['required', 'integer', 'exists:members,id'],
            'duty_label' => ['required', 'string', 'max:120'],
            'shift_label' => ['nullable', 'string', 'max:120'],
            'shift_date' => ['required', 'date'],
            'shift_start' => ['nullable', 'date_format:H:i'],
            'shift_end' => ['nullable', 'date_format:H:i'],
            'service_team_assignment_id' => ['nullable', 'integer', 'exists:service_team_assignments,id'],
        ])->validate();

        $member = Member::query()->findOrFail($validated['member_id']);
        $conflicts = $this->detectSlotConflicts($roster, $member, $validated);

        return DB::transaction(function () use ($actor, $roster, $member, $validated, $conflicts): TeamRosterSlot {
            $slot = TeamRosterSlot::create([
                'team_roster_id' => $roster->id,
                'member_id' => $member->id,
                'service_team_assignment_id' => $validated['service_team_assignment_id'] ?? null,
                'duty_label' => $validated['duty_label'],
                'shift_label' => $validated['shift_label'] ?? null,
                'shift_date' => $validated['shift_date'],
                'shift_start' => $validated['shift_start'] ?? null,
                'shift_end' => $validated['shift_end'] ?? null,
                'status' => TeamRosterSlot::STATUS_DRAFT,
                'conflict_flags' => $conflicts === [] ? null : $conflicts,
                'created_by' => $actor->id,
            ]);

            $this->recordEvent($actor, $roster, $slot, 'slot_added', null, ['conflicts' => $conflicts]);

            return $slot->fresh(['member:id,first_name,last_name']);
        });
    }

    /**
     * @return array{valid: bool, conflicts: array<int, array<string, mixed>>, staffing: array<int, array<string, mixed>>}
     */
    public function validateRoster(User $actor, TeamRoster $roster): array
    {
        $this->assertCan($actor, 'teams.rosters.read');
        $this->assertRosterInScope($actor, $roster);

        $summary = $this->buildValidationSummary($roster);
        $roster->update(['conflict_summary' => $summary]);

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function publishRoster(User $actor, TeamRoster $roster, array $payload = []): TeamRoster
    {
        $this->assertCan($actor, 'teams.rosters.manage');
        $this->assertRosterInScope($actor, $roster);

        if ($roster->status !== TeamRoster::STATUS_DRAFT) {
            throw ValidationException::withMessages(['roster' => ['Only draft rosters can be published.']]);
        }

        $validated = validator($payload, [
            'override' => ['nullable', 'boolean'],
            'override_reason' => ['nullable', 'string', 'max:500'],
        ])->validate();

        $summary = $this->buildValidationSummary($roster->load('slots.member'));
        $hasConflicts = ($summary['conflicts'] ?? []) !== [] || ($summary['staffing'] ?? []) !== [];

        if ($hasConflicts && ! ($validated['override'] ?? false)) {
            throw new TeamRosterException(
                'Roster cannot be published until conflicts are resolved or explicitly approved.',
                'roster_conflicts',
                422,
                true,
                array_merge($summary['conflicts'], $summary['staffing']),
            );
        }

        if ($hasConflicts && ($validated['override'] ?? false)) {
            if (! $this->authorization->allows($actor, 'teams.rosters.override')) {
                throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
            }

            if (empty($validated['override_reason'])) {
                throw ValidationException::withMessages(['override_reason' => ['A reason is required to publish with conflicts.']]);
            }
        }

        if ($roster->slots()->count() === 0) {
            throw ValidationException::withMessages(['roster' => ['At least one duty assignment is required before publication.']]);
        }

        return DB::transaction(function () use ($actor, $roster, $validated, $summary, $hasConflicts): TeamRoster {
            $roster->update([
                'status' => TeamRoster::STATUS_PUBLISHED,
                'conflict_summary' => $summary,
                'override_applied' => $hasConflicts,
                'override_reason' => $hasConflicts ? ($validated['override_reason'] ?? null) : null,
                'published_at' => now(),
                'published_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $roster->slots()->update(['status' => TeamRosterSlot::STATUS_PUBLISHED]);

            $this->recordEvent($actor, $roster, null, 'published', $validated['override_reason'] ?? null, [
                'override' => $hasConflicts,
            ]);

            $this->notifyPublishedRoster($roster->fresh(['slots.member', 'team']));
            $this->audit($actor, $hasConflicts ? 'team_roster.published_with_override' : 'team_roster.published', $roster);

            return $roster->fresh(['slots.member:id,first_name,last_name', 'team:id,name']);
        });
    }

    /**
     * @return Collection<int, TeamRosterSlot>
     */
    public function listMySlots(User $actor): Collection
    {
        $member = $this->resolveMember($actor);

        return TeamRosterSlot::query()
            ->with(['roster.team:id,name', 'member:id,first_name,last_name'])
            ->where('member_id', $member->id)
            ->whereIn('status', [
                TeamRosterSlot::STATUS_PUBLISHED,
                TeamRosterSlot::STATUS_ACCEPTED,
                TeamRosterSlot::STATUS_REJECTED,
                TeamRosterSlot::STATUS_REPLACEMENT_REQUESTED,
            ])
            ->orderBy('shift_date')
            ->limit(100)
            ->get();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function respondToSlot(User $actor, TeamRosterSlot $slot, array $payload): TeamRosterSlot
    {
        $member = $this->resolveMember($actor);

        if ((int) $slot->member_id !== (int) $member->id) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }

        if ($slot->status !== TeamRosterSlot::STATUS_PUBLISHED) {
            throw ValidationException::withMessages(['slot' => ['Only published assignments can be responded to.']]);
        }

        $validated = validator($payload, [
            'response' => ['required', 'string', 'in:' . implode(',', config('team_rosters.member_responses', []))],
            'reason' => ['nullable', 'string', 'max:500'],
        ])->validate();

        if (in_array($validated['response'], ['rejected', 'replacement_requested'], true) && empty($validated['reason'])) {
            throw ValidationException::withMessages(['reason' => ['A reason is required for this response.']]);
        }

        return DB::transaction(function () use ($actor, $slot, $validated): TeamRosterSlot {
            $status = match ($validated['response']) {
                'accepted' => TeamRosterSlot::STATUS_ACCEPTED,
                'rejected' => TeamRosterSlot::STATUS_REJECTED,
                'replacement_requested' => TeamRosterSlot::STATUS_REPLACEMENT_REQUESTED,
            };

            $slot->update([
                'status' => $status,
                'member_response' => $validated['response'],
                'response_reason' => $validated['reason'] ?? null,
                'responded_at' => now(),
            ]);

            $roster = $slot->roster ?? TeamRoster::query()->find($slot->team_roster_id);
            $this->recordEvent($actor, $roster, $slot, 'member_response', $validated['reason'] ?? null, [
                'response' => $validated['response'],
            ]);
            $this->notifyLeadersOfResponse($roster, $slot);
            $this->audit($actor, 'team_roster_slot.responded', $roster, ['slot_id' => $slot->id, 'response' => $validated['response']]);

            return $slot->fresh(['roster.team:id,name', 'member:id,first_name,last_name']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function substituteSlot(User $actor, TeamRosterSlot $slot, array $payload): TeamRosterSlot
    {
        $this->assertCan($actor, 'teams.rosters.manage');

        $roster = $slot->roster ?? TeamRoster::query()->findOrFail($slot->team_roster_id);
        $this->assertRosterInScope($actor, $roster);

        if (! in_array($slot->status, [
            TeamRosterSlot::STATUS_PUBLISHED,
            TeamRosterSlot::STATUS_REJECTED,
            TeamRosterSlot::STATUS_REPLACEMENT_REQUESTED,
        ], true)) {
            throw ValidationException::withMessages(['slot' => ['This assignment cannot be substituted.']]);
        }

        $validated = validator($payload, [
            'substitute_member_id' => ['required', 'integer', 'exists:members,id'],
            'reason' => ['nullable', 'string', 'max:500'],
        ])->validate();

        $substitute = Member::query()->findOrFail($validated['substitute_member_id']);
        $slotData = [
            'duty_label' => $slot->duty_label,
            'shift_label' => $slot->shift_label,
            'shift_date' => $slot->shift_date->toDateString(),
            'shift_start' => $slot->shift_start,
            'shift_end' => $slot->shift_end,
        ];

        $conflicts = $this->detectSlotConflicts($roster, $substitute, $slotData, $slot->id);

        return DB::transaction(function () use ($actor, $roster, $slot, $substitute, $validated, $conflicts): TeamRosterSlot {
            $slot->update(['status' => TeamRosterSlot::STATUS_SUBSTITUTED]);

            $replacement = TeamRosterSlot::create([
                'team_roster_id' => $roster->id,
                'member_id' => $substitute->id,
                'duty_label' => $slot->duty_label,
                'shift_label' => $slot->shift_label,
                'shift_date' => $slot->shift_date,
                'shift_start' => $slot->shift_start,
                'shift_end' => $slot->shift_end,
                'status' => TeamRosterSlot::STATUS_PUBLISHED,
                'replaced_slot_id' => $slot->id,
                'conflict_flags' => $conflicts === [] ? null : $conflicts,
                'created_by' => $actor->id,
            ]);

            $this->recordEvent($actor, $roster, $slot, 'substituted', $validated['reason'] ?? null, [
                'replacement_slot_id' => $replacement->id,
                'substitute_member_id' => $substitute->id,
            ]);

            $this->notifySubstitute($roster, $slot, $replacement);
            $this->audit($actor, 'team_roster_slot.substituted', $roster, [
                'original_slot_id' => $slot->id,
                'replacement_slot_id' => $replacement->id,
            ]);

            return $replacement->fresh(['member:id,first_name,last_name', 'replacedSlot']);
        });
    }

    public function formatRoster(TeamRoster $roster): array
    {
        return [
            'id' => $roster->id,
            'service_team_id' => $roster->service_team_id,
            'roster_type' => $roster->roster_type,
            'title' => $roster->title,
            'status' => $roster->status,
            'gathering_key' => $roster->gathering_key,
            'gathering_id' => $roster->gathering_id,
            'period_start' => $roster->period_start?->toDateString(),
            'period_end' => $roster->period_end?->toDateString(),
            'staffing_requirements' => $roster->staffing_requirements ?? [],
            'conflict_summary' => $roster->conflict_summary,
            'override_applied' => $roster->override_applied,
            'slots_count' => $roster->slots_count ?? $roster->slots?->count(),
            'slots' => $roster->relationLoaded('slots')
                ? $roster->slots->map(fn (TeamRosterSlot $slot) => $this->formatSlot($slot))->values()->all()
                : [],
            'published_at' => $roster->published_at?->toIso8601String(),
        ];
    }

    public function formatSlot(TeamRosterSlot $slot): array
    {
        return [
            'id' => $slot->id,
            'team_roster_id' => $slot->team_roster_id,
            'member_id' => $slot->member_id,
            'duty_label' => $slot->duty_label,
            'shift_label' => $slot->shift_label,
            'shift_date' => $slot->shift_date?->toDateString(),
            'shift_start' => $slot->shift_start,
            'shift_end' => $slot->shift_end,
            'status' => $slot->status,
            'member_response' => $slot->member_response,
            'response_reason' => $slot->response_reason,
            'responded_at' => $slot->responded_at?->toIso8601String(),
            'conflict_flags' => $slot->conflict_flags ?? [],
            'replaced_slot_id' => $slot->replaced_slot_id,
            'member' => $slot->relationLoaded('member') && $slot->member
                ? [
                    'id' => $slot->member->id,
                    'full_name' => $slot->member->fullName(),
                ]
                : null,
            'roster' => $slot->relationLoaded('roster') && $slot->roster
                ? [
                    'id' => $slot->roster->id,
                    'title' => $slot->roster->title,
                    'team_name' => $slot->roster->team?->name,
                ]
                : null,
        ];
    }

    /**
     * @return array{valid: bool, conflicts: array<int, array<string, mixed>>, staffing: array<int, array<string, mixed>>}
     */
    private function buildValidationSummary(TeamRoster $roster): array
    {
        $conflicts = [];
        $slots = $roster->relationLoaded('slots') ? $roster->slots : $roster->slots()->with('member')->get();

        foreach ($slots as $slot) {
            $member = $slot->member;
            if ($member === null) {
                continue;
            }

            $flags = $this->detectSlotConflicts($roster, $member, [
                'duty_label' => $slot->duty_label,
                'shift_label' => $slot->shift_label,
                'shift_date' => $slot->shift_date->toDateString(),
                'shift_start' => $slot->shift_start,
                'shift_end' => $slot->shift_end,
            ], $slot->id);

            if ($flags !== []) {
                $conflicts[] = [
                    'slot_id' => $slot->id,
                    'member_id' => $slot->member_id,
                    'member_name' => $member->fullName(),
                    'reasons' => $flags,
                ];

                $slot->update(['conflict_flags' => $flags]);
            }
        }

        $staffing = $this->detectStaffingShortfalls($roster, $slots);

        return [
            'valid' => $conflicts === [] && $staffing === [],
            'conflicts' => $conflicts,
            'staffing' => $staffing,
        ];
    }

    /**
     * @param  array<string, mixed>  $slotData
     * @return string[]
     */
    private function detectSlotConflicts(TeamRoster $roster, Member $member, array $slotData, ?int $ignoreSlotId = null): array
    {
        $conflicts = [];
        $team = $roster->team ?? ServiceTeam::query()->find($roster->service_team_id);

        if (! in_array($member->lifecycle_status, config('team_assignments.eligible_lifecycle_statuses', []), true)) {
            $conflicts[] = 'ineligible_member';
        }

        if ($team !== null && (int) $member->branch_id !== (int) $team->branch_id) {
            $conflicts[] = 'branch_mismatch';
        }

        $requiredSkills = $team?->required_skills ?? [];
        if ($requiredSkills !== []) {
            $memberSkills = $member->skills ?? [];
            if (array_diff($requiredSkills, $memberSkills) !== []) {
                $conflicts[] = 'missing_skills';
            }
        }

        $shiftDate = Carbon::parse($slotData['shift_date']);
        $profile = VolunteerProfile::query()->where('member_id', $member->id)->first();
        foreach ($profile?->availability['unavailable_periods'] ?? [] as $period) {
            $from = ! empty($period['from']) ? Carbon::parse($period['from']) : null;
            $to = ! empty($period['to']) ? Carbon::parse($period['to']) : null;
            if ($from !== null && $to !== null && $shiftDate->betweenIncluded($from, $to)) {
                $conflicts[] = 'unavailable';
                break;
            }
        }

        $duplicateInRoster = TeamRosterSlot::query()
            ->where('team_roster_id', $roster->id)
            ->where('member_id', $member->id)
            ->where('shift_date', $slotData['shift_date'])
            ->where('shift_label', $slotData['shift_label'] ?? null)
            ->when($ignoreSlotId, fn (Builder $q) => $q->where('id', '!=', $ignoreSlotId))
            ->exists();

        if ($duplicateInRoster) {
            $conflicts[] = 'duplicate_shift';
        }

        $duplicatePublished = TeamRosterSlot::query()
            ->where('member_id', $member->id)
            ->where('shift_date', $slotData['shift_date'])
            ->where('shift_label', $slotData['shift_label'] ?? null)
            ->whereHas('roster', fn (Builder $q) => $q
                ->where('status', TeamRoster::STATUS_PUBLISHED)
                ->where('id', '!=', $roster->id))
            ->when($ignoreSlotId, fn (Builder $q) => $q->where('id', '!=', $ignoreSlotId))
            ->exists();

        if ($duplicatePublished) {
            $conflicts[] = 'duplicate_roster_assignment';
        }

        return array_values(array_unique($conflicts));
    }

    /**
     * @param  Collection<int, TeamRosterSlot>  $slots
     * @return array<int, array<string, mixed>>
     */
    private function detectStaffingShortfalls(TeamRoster $roster, Collection $slots): array
    {
        $requirements = $roster->staffing_requirements['duties'] ?? [];
        $shortfalls = [];

        foreach ($requirements as $requirement) {
            $duty = $requirement['duty_label'] ?? null;
            $required = (int) ($requirement['required_count'] ?? 0);
            if ($duty === null || $required <= 0) {
                continue;
            }

            $assigned = $slots->where('duty_label', $duty)->count();
            if ($assigned < $required) {
                $shortfalls[] = [
                    'duty_label' => $duty,
                    'required_count' => $required,
                    'assigned_count' => $assigned,
                    'reason' => 'staffing_shortfall',
                ];
            }
        }

        return $shortfalls;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultStaffingRequirements(ServiceTeam $team): array
    {
        $duties = [];
        foreach ($team->schedules ?? [] as $schedule) {
            $duties[] = [
                'duty_label' => $schedule['label'] ?? 'general',
                'required_count' => (int) ($schedule['required_volunteers'] ?? 1),
            ];
        }

        return ['duties' => $duties];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateRosterPayload(array $payload): array
    {
        return validator($payload, [
            'roster_type' => ['required', 'string', 'in:' . implode(',', config('team_rosters.types', []))],
            'title' => ['required', 'string', 'max:160'],
            'gathering_key' => ['nullable', 'string', 'in:' . implode(',', config('team_rosters.gathering_keys', []))],
            'gathering_id' => ['nullable', 'integer'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'staffing_requirements' => ['nullable', 'array'],
        ])->validate();
    }

    private function recordEvent(
        User $actor,
        TeamRoster $roster,
        ?TeamRosterSlot $slot,
        string $eventType,
        ?string $reason = null,
        array $metadata = [],
    ): void {
        TeamRosterEvent::create([
            'team_roster_id' => $roster->id,
            'team_roster_slot_id' => $slot?->id,
            'event_type' => $eventType,
            'reason' => $reason,
            'metadata' => $metadata === [] ? null : $metadata,
            'actor_id' => $actor->id,
            'created_at' => now(),
        ]);
    }

    private function notifyPublishedRoster(TeamRoster $roster): void
    {
        foreach ($roster->slots as $slot) {
            $member = $slot->member;
            if ($member?->user_id === null) {
                continue;
            }

            MemberNotification::create([
                'member_id' => $member->id,
                'user_id' => $member->user_id,
                'type' => 'team_roster.published',
                'message' => 'You have been rostered for ' . $slot->duty_label . ' on ' . $slot->shift_date->toDateString() . '.',
                'metadata' => [
                    'team_roster_id' => $roster->id,
                    'team_roster_slot_id' => $slot->id,
                    'service_team_id' => $roster->service_team_id,
                ],
            ]);
        }
    }

    private function notifyLeadersOfResponse(TeamRoster $roster, TeamRosterSlot $slot): void
    {
        $team = $roster->team ?? ServiceTeam::query()->find($roster->service_team_id);
        if ($team === null) {
            return;
        }

        foreach ($team->leaders ?? [] as $leader) {
            $userId = $leader['user_id'] ?? null;
            if ($userId === null) {
                continue;
            }

            $leaderMember = Member::query()->where('user_id', $userId)->first();
            if ($leaderMember === null) {
                continue;
            }

            MemberNotification::create([
                'member_id' => $leaderMember->id,
                'user_id' => $userId,
                'type' => 'team_roster.member_response',
                'message' => ($slot->member?->fullName() ?? 'A member') . ' responded "' . $slot->member_response . '" to a roster assignment.',
                'metadata' => [
                    'team_roster_id' => $roster->id,
                    'team_roster_slot_id' => $slot->id,
                    'response' => $slot->member_response,
                ],
            ]);
        }
    }

    private function notifySubstitute(TeamRoster $roster, TeamRosterSlot $original, TeamRosterSlot $replacement): void
    {
        foreach ([$original->member, $replacement->member] as $member) {
            if ($member?->user_id === null) {
                continue;
            }

            MemberNotification::create([
                'member_id' => $member->id,
                'user_id' => $member->user_id,
                'type' => 'team_roster.substitute_assigned',
                'message' => 'A roster substitution was recorded for ' . $roster->title . '.',
                'metadata' => [
                    'team_roster_id' => $roster->id,
                    'original_slot_id' => $original->id,
                    'replacement_slot_id' => $replacement->id,
                ],
            ]);
        }
    }

    private function resolveMember(User $user): Member
    {
        $member = Member::query()->where('user_id', $user->id)->first();
        if ($member === null) {
            throw ValidationException::withMessages(['member' => ['No member profile is linked to your account.']]);
        }

        return $member;
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function audit(User $actor, string $action, TeamRoster $roster, ?array $metadata = null): void
    {
        $this->audit->record(
            actor: $actor,
            action: $action,
            category: AuditEvent::CATEGORY_BUSINESS,
            module: 'teams',
            branchId: $roster->branch_id,
            subjectType: TeamRoster::class,
            subjectId: $roster->id,
            before: null,
            after: array_filter([
                'service_team_id' => $roster->service_team_id,
                'status' => $roster->status,
                'metadata' => $metadata,
            ]),
        );
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

    private function assertRosterInScope(User $actor, TeamRoster $roster): void
    {
        if ($actor->isChurchWide()) {
            return;
        }

        try {
            BranchScope::for($actor)->assertIncludes((int) $roster->branch_id);
        } catch (BranchScopeException) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }
}
