<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Member;
use App\Models\ServiceTeam;
use App\Models\ServiceTeamAssignment;
use App\Models\ServiceTeamAssignmentEvent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Story 5.2: assign members to service teams, shifts, and duties.
 */
class ServiceTeamAssignmentService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
    ) {
    }

    /**
     * @return Collection<int, ServiceTeamAssignment>
     */
    public function listAssignments(User $actor, ServiceTeam $team): Collection
    {
        $this->assertCan($actor, 'teams.assignments.read');
        $this->assertTeamInScope($actor, $team);

        return ServiceTeamAssignment::query()
            ->with(['member:id,first_name,last_name,membership_id'])
            ->where('service_team_id', $team->id)
            ->whereIn('status', config('team_assignments.active_statuses', []))
            ->orderBy('effective_from')
            ->limit(500)
            ->get();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function assignMember(User $actor, ServiceTeam $team, array $payload): ServiceTeamAssignment
    {
        $this->assertCan($actor, 'teams.assignments.manage');
        $this->assertTeamInScope($actor, $team);
        $this->assertTeamAssignable($team);

        $validated = $this->validateAssignmentPayload($payload);
        $member = Member::query()->findOrFail($validated['member_id']);

        $validated = $this->assertConflictsOrOverride($actor, $team, $member, $validated);

        return $this->persistAssignment($actor, $team, $member, $validated);
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return array{created: int, assignments: array<int, array<string, mixed>>}
     */
    public function bulkAssign(User $actor, ServiceTeam $team, array $entries): array
    {
        $this->assertCan($actor, 'teams.assignments.manage');
        $this->assertTeamInScope($actor, $team);
        $this->assertTeamAssignable($team);

        $created = [];
        foreach ($entries as $index => $entry) {
            if (! is_array($entry)) {
                throw ValidationException::withMessages(["entries.{$index}" => ['Each entry must be an object.']]);
            }

            $created[] = $this->formatAssignment(
                $this->assignMember($actor, $team, $entry)
            );
        }

        return [
            'created' => count($created),
            'assignments' => $created,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function transferAssignment(User $actor, ServiceTeamAssignment $assignment, array $payload): ServiceTeamAssignment
    {
        $this->assertCan($actor, 'teams.assignments.manage');
        $this->assertAssignmentInScope($actor, $assignment);

        if (! in_array($assignment->status, [ServiceTeamAssignment::STATUS_ACTIVE, ServiceTeamAssignment::STATUS_SCHEDULED], true)) {
            throw ValidationException::withMessages(['assignment' => ['Only active assignments can be transferred.']]);
        }

        $targetTeam = ServiceTeam::query()->findOrFail($payload['service_team_id']);
        $this->assertTeamInScope($actor, $targetTeam);
        $this->assertTeamAssignable($targetTeam);

        $validated = $this->validateAssignmentPayload(array_merge($payload, [
            'member_id' => $assignment->member_id,
        ]));

        return DB::transaction(function () use ($actor, $assignment, $targetTeam, $validated): ServiceTeamAssignment {
            $member = Member::query()->findOrFail($assignment->member_id);
            $validated = $this->assertConflictsOrOverride($actor, $targetTeam, $member, $validated, $assignment->id);

            $assignment->update([
                'status' => ServiceTeamAssignment::STATUS_TRANSFERRED,
                'effective_to' => $validated['effective_from'],
                'removed_at' => now(),
            ]);

            $this->recordEvent($actor, $assignment, 'transferred', 'Assignment transferred to another team.', [
                'target_team_id' => $targetTeam->id,
            ]);

            $newAssignment = $this->persistAssignment($actor, $targetTeam, $member, $validated, transferringFrom: $assignment);

            return $newAssignment;
        });
    }

    public function removeAssignment(User $actor, ServiceTeamAssignment $assignment, ?string $reason = null): ServiceTeamAssignment
    {
        $this->assertCan($actor, 'teams.assignments.manage');
        $this->assertAssignmentInScope($actor, $assignment);

        if ($assignment->status === ServiceTeamAssignment::STATUS_REMOVED) {
            return $assignment;
        }

        return DB::transaction(function () use ($actor, $assignment, $reason): ServiceTeamAssignment {
            $assignment->update([
                'status' => ServiceTeamAssignment::STATUS_REMOVED,
                'effective_to' => now()->toDateString(),
                'removed_at' => now(),
            ]);

            $this->recordEvent($actor, $assignment, 'removed', $reason ?? 'Assignment removed.');
            $this->audit($actor, 'team_assignment.removed', $assignment, ['status' => $assignment->getOriginal('status')]);

            return $assignment->fresh(['member:id,first_name,last_name', 'team:id,name']);
        });
    }

    public function approveAssignment(User $actor, ServiceTeamAssignment $assignment): ServiceTeamAssignment
    {
        $this->assertCan($actor, 'teams.assignments.manage');
        $this->assertAssignmentInScope($actor, $assignment);

        if ($assignment->status !== ServiceTeamAssignment::STATUS_PENDING) {
            throw ValidationException::withMessages(['status' => ['Only pending assignments can be approved.']]);
        }

        return DB::transaction(function () use ($actor, $assignment): ServiceTeamAssignment {
            $status = $assignment->effective_from->isFuture()
                ? ServiceTeamAssignment::STATUS_SCHEDULED
                : ServiceTeamAssignment::STATUS_ACTIVE;

            $assignment->update([
                'status' => $status,
                'approved_by' => $actor->id,
                'approved_at' => now(),
            ]);

            $this->recordEvent($actor, $assignment, 'approved', 'Assignment approved.');
            $this->audit($actor, 'team_assignment.approved', $assignment);

            return $assignment->fresh(['member:id,first_name,last_name', 'team:id,name']);
        });
    }

    public function formatAssignment(ServiceTeamAssignment $assignment): array
    {
        return [
            'id' => $assignment->id,
            'service_team_id' => $assignment->service_team_id,
            'member_id' => $assignment->member_id,
            'team_role' => $assignment->team_role,
            'sub_team' => $assignment->sub_team,
            'shift_label' => $assignment->shift_label,
            'responsibilities' => $assignment->responsibilities ?? [],
            'status' => $assignment->status,
            'effective_from' => $assignment->effective_from?->toDateString(),
            'effective_to' => $assignment->effective_to?->toDateString(),
            'team_config_version' => $assignment->team_config_version,
            'override_applied' => $assignment->override_applied,
            'member' => $assignment->relationLoaded('member') && $assignment->member
                ? [
                    'id' => $assignment->member->id,
                    'full_name' => $assignment->member->fullName(),
                    'membership_id' => $assignment->member->membership_id,
                ]
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function persistAssignment(
        User $actor,
        ServiceTeam $team,
        Member $member,
        array $validated,
        ?ServiceTeamAssignment $transferringFrom = null,
    ): ServiceTeamAssignment {
        return DB::transaction(function () use ($actor, $team, $member, $validated, $transferringFrom): ServiceTeamAssignment {
            $requiresApproval = (bool) ($team->approval_hierarchy['requires_approval'] ?? false);
            $effectiveFrom = Carbon::parse($validated['effective_from']);

            $status = ServiceTeamAssignment::STATUS_ACTIVE;
            if ($requiresApproval && ! ($validated['override'] ?? false)) {
                $status = ServiceTeamAssignment::STATUS_PENDING;
            } elseif ($effectiveFrom->isFuture()) {
                $status = ServiceTeamAssignment::STATUS_SCHEDULED;
            }

            $assignment = ServiceTeamAssignment::create([
                'service_team_id' => $team->id,
                'member_id' => $member->id,
                'team_role' => $validated['team_role'],
                'sub_team' => $validated['sub_team'] ?? null,
                'shift_label' => $validated['shift_label'] ?? null,
                'responsibilities' => $validated['responsibilities'] ?? [],
                'status' => $status,
                'effective_from' => $effectiveFrom->toDateString(),
                'effective_to' => $validated['effective_to'] ?? null,
                'team_config_version' => $team->current_config_version,
                'override_applied' => (bool) ($validated['override'] ?? false),
                'override_reason' => $validated['override_reason'] ?? null,
                'assigned_by' => $actor->id,
                'approved_by' => $requiresApproval ? null : $actor->id,
                'approved_at' => $requiresApproval ? null : now(),
            ]);

            $eventType = $transferringFrom ? 'transferred_in' : 'created';
            $this->recordEvent($actor, $assignment, $eventType, $validated['notes'] ?? null, [
                'override' => $assignment->override_applied,
            ]);

            if ($assignment->override_applied) {
                $this->recordEvent($actor, $assignment, 'override', $assignment->override_reason, [
                    'reason_codes' => $validated['override_conflicts'] ?? [],
                ]);
                $this->audit($actor, 'team_assignment.override', $assignment);
            }

            $this->audit($actor, 'team_assignment.created', $assignment);

            return $assignment->fresh(['member:id,first_name,last_name,membership_id', 'team:id,name']);
        });
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return string[]
     */
    public function previewConflicts(
        ServiceTeam $team,
        Member $member,
        array $validated,
        ?int $ignoreAssignmentId = null,
    ): array {
        return $this->detectConflicts($team, $member, $validated, $ignoreAssignmentId);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function assertConflictsOrOverride(
        User $actor,
        ServiceTeam $team,
        Member $member,
        array $validated,
        ?int $ignoreAssignmentId = null,
    ): array {
        $conflicts = $this->detectConflicts($team, $member, $validated, $ignoreAssignmentId);

        if ($conflicts === []) {
            return $validated;
        }

        if ($validated['override'] ?? false) {
            if (! $this->authorization->allows($actor, 'teams.assignments.override')) {
                throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
            }

            if (empty($validated['override_reason'])) {
                throw ValidationException::withMessages(['override_reason' => ['A reason is required to override assignment conflicts.']]);
            }

            $validated['override_conflicts'] = $conflicts;

            return $validated;
        }

        $first = $conflicts[0];

        throw new ServiceTeamAssignmentException(
            $this->conflictMessage($first),
            $first,
            422,
            true,
        );
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return string[]
     */
    private function detectConflicts(ServiceTeam $team, Member $member, array $validated, ?int $ignoreAssignmentId = null): array
    {
        $conflicts = [];

        if (in_array($member->lifecycle_status, config('team_assignments.blocked_lifecycle_statuses', []), true)
            || ! in_array($member->lifecycle_status, config('team_assignments.eligible_lifecycle_statuses', []), true)) {
            $conflicts[] = 'ineligible_member';
        }

        if ((int) $member->branch_id !== (int) $team->branch_id) {
            $conflicts[] = 'branch_mismatch';
        }

        $activeTeamCount = ServiceTeamAssignment::query()
            ->where('member_id', $member->id)
            ->where('service_team_id', '!=', $team->id)
            ->whereIn('status', config('team_assignments.active_statuses', []))
            ->when($ignoreAssignmentId, fn (Builder $q) => $q->where('id', '!=', $ignoreAssignmentId))
            ->distinct('service_team_id')
            ->count('service_team_id');

        if ($activeTeamCount >= (int) config('team_assignments.max_active_teams_per_member', 3)) {
            $conflicts[] = 'max_teams_exceeded';
        }

        $duplicateQuery = ServiceTeamAssignment::query()
            ->where('service_team_id', $team->id)
            ->where('member_id', $member->id)
            ->whereIn('status', config('team_assignments.active_statuses', []))
            ->where('shift_label', $validated['shift_label'] ?? null);

        if ($ignoreAssignmentId !== null) {
            $duplicateQuery->where('id', '!=', $ignoreAssignmentId);
        }

        if ($duplicateQuery->exists()) {
            $conflicts[] = 'duplicate_assignment';
        }

        if (! empty($validated['shift_label'])) {
            $shiftConflict = ServiceTeamAssignment::query()
                ->where('member_id', $member->id)
                ->where('shift_label', $validated['shift_label'])
                ->where('effective_from', $validated['effective_from'])
                ->whereIn('status', config('team_assignments.active_statuses', []))
                ->when($ignoreAssignmentId, fn (Builder $q) => $q->where('id', '!=', $ignoreAssignmentId))
                ->exists();

            if ($shiftConflict) {
                $conflicts[] = 'shift_conflict';
            }
        }

        $maxCapacity = (int) ($team->minimum_staffing['maximum_per_session'] ?? 0);
        if ($maxCapacity > 0) {
            $activeCount = ServiceTeamAssignment::query()
                ->where('service_team_id', $team->id)
                ->whereIn('status', config('team_assignments.active_statuses', []))
                ->when($ignoreAssignmentId, fn (Builder $q) => $q->where('id', '!=', $ignoreAssignmentId))
                ->count();

            if ($activeCount >= $maxCapacity) {
                $conflicts[] = 'team_capacity';
            }
        }

        $requiredSkills = $team->required_skills ?? [];
        if ($requiredSkills !== []) {
            $memberSkills = $member->skills ?? [];
            $missing = array_diff($requiredSkills, $memberSkills);
            if ($missing !== []) {
                $conflicts[] = 'missing_skills';
            }
        }

        return $conflicts;
    }

    private function conflictMessage(string $reason): string
    {
        return match ($reason) {
            'ineligible_member' => 'Member is not eligible for team assignment.',
            'branch_mismatch' => 'Member is outside the team branch scope.',
            'max_teams_exceeded' => 'Member has reached the maximum number of active team assignments.',
            'duplicate_assignment' => 'Member is already assigned to this team shift.',
            'shift_conflict' => 'Member has a conflicting duty on the same shift.',
            'team_capacity' => 'Team staffing capacity has been reached.',
            'missing_skills' => 'Member does not meet required team skills.',
            default => 'Assignment cannot be completed.',
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateAssignmentPayload(array $payload): array
    {
        return validator($payload, [
            'member_id' => ['required', 'integer', 'exists:members,id'],
            'team_role' => ['required', 'string', 'in:' . implode(',', config('team_assignments.roles', []))],
            'sub_team' => ['nullable', 'string', 'max:120'],
            'shift_label' => ['nullable', 'string', 'max:120'],
            'responsibilities' => ['nullable', 'array'],
            'responsibilities.*' => ['string', 'max:120'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'override' => ['nullable', 'boolean'],
            'override_reason' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:500'],
            'service_team_id' => ['nullable', 'integer', 'exists:service_teams,id'],
        ])->validate();
    }

    private function recordEvent(
        User $actor,
        ServiceTeamAssignment $assignment,
        string $eventType,
        ?string $reason = null,
        array $metadata = [],
    ): void {
        ServiceTeamAssignmentEvent::create([
            'service_team_assignment_id' => $assignment->id,
            'event_type' => $eventType,
            'reason' => $reason,
            'metadata' => $metadata === [] ? null : $metadata,
            'actor_id' => $actor->id,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $before
     */
    private function audit(User $actor, string $action, ServiceTeamAssignment $assignment, ?array $before = null): void
    {
        $this->audit->record(
            actor: $actor,
            action: $action,
            category: AuditEvent::CATEGORY_BUSINESS,
            module: 'teams',
            branchId: $assignment->team?->branch_id,
            subjectType: ServiceTeamAssignment::class,
            subjectId: $assignment->id,
            before: $before,
            after: [
                'service_team_id' => $assignment->service_team_id,
                'member_id' => $assignment->member_id,
                'status' => $assignment->status,
                'team_config_version' => $assignment->team_config_version,
            ],
        );
    }

    private function assertTeamAssignable(ServiceTeam $team): void
    {
        if ($team->status !== ServiceTeam::STATUS_ACTIVE) {
            throw ValidationException::withMessages(['team' => ['Assignments are only allowed on active teams.']]);
        }
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

    private function assertAssignmentInScope(User $actor, ServiceTeamAssignment $assignment): void
    {
        $team = $assignment->team ?? ServiceTeam::query()->find($assignment->service_team_id);
        if ($team !== null) {
            $this->assertTeamInScope($actor, $team);
        }
    }
}
