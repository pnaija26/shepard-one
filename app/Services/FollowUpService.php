<?php

namespace App\Services;

use App\Models\AttendanceException;
use App\Models\AuditEvent;
use App\Models\FollowUp;
use App\Models\FollowUpActivity;
use App\Models\FollowUpEscalation;
use App\Models\Member;
use App\Models\MemberNotification;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Story 3.4: assigned follow-up work with outcomes and escalation.
 */
class FollowUpService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
    ) {
    }

    /**
     * @return Collection<int, FollowUp>
     */
    public function listFollowUps(User $actor, array $filters = []): Collection
    {
        $this->assertCan($actor, 'followups.read');

        $query = FollowUp::query()
            ->with(['assignee:id,name,email', 'branch:id,name'])
            ->orderBy('due_date');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['assignee_id'])) {
            $query->where('assignee_id', $filters['assignee_id']);
        }

        $this->applyBranchScope($query, $actor);

        return $query->limit(200)->get();
    }

    public function showFollowUp(User $actor, FollowUp $followUp): FollowUp
    {
        $this->assertCanView($actor, $followUp);

        return $followUp->load([
            'assignee:id,name,email',
            'branch:id,name',
            'activities.actor:id,name',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createFollowUp(User $actor, array $payload): FollowUp
    {
        $this->assertCan($actor, 'followups.manage');

        $validated = $this->validateCreatePayload($payload);
        $person = $this->resolvePerson($validated['person_type'], (int) $validated['person_id']);
        $this->assertPersonInScope($actor, $person);

        return DB::transaction(function () use ($actor, $validated, $person): FollowUp {
            $followUp = FollowUp::create([
                'person_type' => $validated['person_type'],
                'person_id' => $validated['person_id'],
                'branch_id' => $validated['branch_id'],
                'reason' => $validated['reason'],
                'assignee_id' => $validated['assignee_id'],
                'due_date' => $validated['due_date'],
                'contact_method' => $validated['contact_method'] ?? 'phone',
                'priority' => $validated['priority'] ?? 'normal',
                'source_type' => $validated['source_type'] ?? 'manual',
                'source_reference_type' => $validated['source_reference_type'] ?? null,
                'source_reference_id' => $validated['source_reference_id'] ?? null,
                'status' => FollowUp::STATUS_ASSIGNED,
                'is_restricted' => (bool) ($validated['is_restricted'] ?? false),
                'created_by' => $actor->id,
            ]);

            $this->audit->record(
                actor: $actor,
                action: 'followup.created',
                category: AuditEvent::CATEGORY_BUSINESS,
                module: 'followups',
                branchId: $followUp->branch_id,
                subjectType: $validated['person_type'],
                subjectId: (int) $validated['person_id'],
                after: ['follow_up_id' => $followUp->id, 'assignee_id' => $followUp->assignee_id],
            );

            $this->notifyAssignee($followUp);

            return $followUp->fresh(['assignee:id,name,email', 'branch:id,name']);
        });
    }

    public function createFromAttendanceException(User $actor, AttendanceException $exception, int $assigneeId): ?FollowUp
    {
        if (FollowUp::query()
            ->where('source_reference_type', AttendanceException::class)
            ->where('source_reference_id', $exception->id)
            ->exists()) {
            return null;
        }

        return $this->createFollowUp($actor, [
            'person_type' => $exception->subject_type,
            'person_id' => $exception->subject_id,
            'branch_id' => $exception->branch_id,
            'reason' => $exception->summary,
            'assignee_id' => $assigneeId,
            'due_date' => now()->addDays((int) config('follow_ups.default_due_days', 3))->toDateString(),
            'contact_method' => 'phone',
            'priority' => in_array($exception->rule_type, ['consecutive_absence', 'repeated_team_absence'], true) ? 'high' : 'normal',
            'source_type' => 'attendance_exception',
            'source_reference_type' => AttendanceException::class,
            'source_reference_id' => $exception->id,
            'is_restricted' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function recordActivity(User $actor, FollowUp $followUp, array $payload): FollowUpActivity
    {
        $this->assertCanWork($actor, $followUp);

        $validated = validator($payload, [
            'activity_type' => ['required', 'string', 'in:contact_attempt,outcome,note'],
            'outcome' => ['nullable', 'string', 'in:' . implode(',', config('follow_ups.outcomes', []))],
            'notes' => ['nullable', 'string', 'max:5000'],
            'next_action' => ['nullable', 'string', 'in:' . implode(',', config('follow_ups.next_actions', []))],
            'next_due_date' => ['nullable', 'date'],
        ])->validate();

        return DB::transaction(function () use ($actor, $followUp, $validated): FollowUpActivity {
            $activity = FollowUpActivity::create([
                'follow_up_id' => $followUp->id,
                'activity_type' => $validated['activity_type'],
                'outcome' => $validated['outcome'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'next_action' => $validated['next_action'] ?? null,
                'next_due_date' => $validated['next_due_date'] ?? null,
                'actor_id' => $actor->id,
                'created_at' => now(),
            ]);

            $this->applyActivityTransition($followUp, $validated);

            $this->audit->record(
                actor: $actor,
                action: 'followup.activity.recorded',
                category: AuditEvent::CATEGORY_BUSINESS,
                module: 'followups',
                branchId: $followUp->branch_id,
                subjectType: $followUp->person_type,
                subjectId: $followUp->person_id,
                after: [
                    'follow_up_id' => $followUp->id,
                    'activity_id' => $activity->id,
                    'outcome' => $activity->outcome,
                    'next_action' => $activity->next_action,
                ],
            );

            return $activity->load('actor:id,name');
        });
    }

    /**
     * @return array{processed: int, escalated: int, skipped: int}
     */
    public function processEscalations(User $actor, ?int $branchId = null): array
    {
        $this->assertCan($actor, 'followups.escalate');

        $counts = ['processed' => 0, 'escalated' => 0, 'skipped' => 0];

        $query = FollowUp::query()
            ->whereIn('status', config('follow_ups.open_statuses', []))
            ->orderBy('id');

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        $this->applyBranchScope($query, $actor);

        foreach ($query->cursor() as $followUp) {
            $counts['processed']++;
            $trigger = $this->resolveEscalationTrigger($followUp);

            if ($trigger === null) {
                $counts['skipped']++;

                continue;
            }

            if (FollowUpEscalation::query()->where('follow_up_id', $followUp->id)->where('trigger_type', $trigger)->exists()) {
                $counts['skipped']++;

                continue;
            }

            $target = $this->resolveEscalationTarget($followUp);
            if ($target === null) {
                $counts['skipped']++;

                continue;
            }

            $this->escalateFollowUp($actor, $followUp, $target, $trigger);
            $counts['escalated']++;
        }

        return $counts;
    }

    public function resolveDefaultAssignee(int $branchId): ?User
    {
        return User::query()
            ->where('branch_id', $branchId)
            ->whereHas('assignedRoles.permissions', fn (Builder $query) => $query->where('action', 'followups.manage'))
            ->first();
    }

    public function formatFollowUp(FollowUp $followUp, bool $includeRestricted = false): array
    {
        $person = $followUp->person;
        $personName = null;
        if ($person instanceof Member || $person instanceof Visitor) {
            $personName = trim(($person->first_name ?? '') . ' ' . ($person->last_name ?? ''));
        }

        return [
            'id' => $followUp->id,
            'person_type' => $followUp->person_type,
            'person_id' => $followUp->person_id,
            'person_name' => $personName,
            'branch_id' => $followUp->branch_id,
            'reason' => ($followUp->is_restricted && ! $includeRestricted) ? 'Restricted follow-up' : $followUp->reason,
            'assignee_id' => $followUp->assignee_id,
            'due_date' => $followUp->due_date?->toDateString(),
            'contact_method' => $followUp->contact_method,
            'priority' => $followUp->priority,
            'source_type' => $followUp->source_type,
            'source_reference_type' => $followUp->source_reference_type,
            'source_reference_id' => $followUp->source_reference_id,
            'status' => $followUp->status,
            'is_restricted' => $followUp->is_restricted,
            'closed_at' => $followUp->closed_at?->toIso8601String(),
            'assignee' => $followUp->relationLoaded('assignee') ? $followUp->assignee : null,
            'branch' => $followUp->relationLoaded('branch') ? $followUp->branch : null,
            'activities' => $followUp->relationLoaded('activities')
                ? $followUp->activities->map(fn (FollowUpActivity $activity) => [
                    'id' => $activity->id,
                    'activity_type' => $activity->activity_type,
                    'outcome' => $activity->outcome,
                    'notes' => $activity->notes,
                    'next_action' => $activity->next_action,
                    'next_due_date' => $activity->next_due_date?->toDateString(),
                    'created_at' => $activity->created_at?->toIso8601String(),
                    'actor' => $activity->relationLoaded('actor') ? $activity->actor : null,
                ])->values()->all()
                : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function applyActivityTransition(FollowUp $followUp, array $validated): void
    {
        $updates = ['status' => FollowUp::STATUS_IN_PROGRESS];
        $outcome = $validated['outcome'] ?? null;
        $nextAction = $validated['next_action'] ?? null;

        if ($outcome === 'successful') {
            $updates['status'] = FollowUp::STATUS_SUCCESSFUL;
        } elseif ($outcome === 'unsuccessful') {
            $updates['status'] = FollowUp::STATUS_UNSUCCESSFUL;
        } elseif ($outcome === 'declined') {
            $updates['status'] = FollowUp::STATUS_DECLINED;
        }

        if ($nextAction === 'close') {
            $updates['status'] = FollowUp::STATUS_CLOSED;
            $updates['closed_at'] = now();
        } elseif ($nextAction === 'reschedule' && ! empty($validated['next_due_date'])) {
            $updates['due_date'] = $validated['next_due_date'];
            $updates['status'] = FollowUp::STATUS_ASSIGNED;
        } elseif ($nextAction === 'continue') {
            $updates['status'] = FollowUp::STATUS_IN_PROGRESS;
        }

        $followUp->update($updates);
    }

    private function escalateFollowUp(User $actor, FollowUp $followUp, User $target, string $trigger): void
    {
        DB::transaction(function () use ($actor, $followUp, $target, $trigger): void {
            $fromAssigneeId = $followUp->assignee_id;

            FollowUpEscalation::create([
                'follow_up_id' => $followUp->id,
                'trigger_type' => $trigger,
                'from_assignee_id' => $fromAssigneeId,
                'to_assignee_id' => $target->id,
                'branch_id' => $followUp->branch_id,
                'escalated_by' => $actor->id,
                'created_at' => now(),
            ]);

            $followUp->update([
                'assignee_id' => $target->id,
                'status' => FollowUp::STATUS_ESCALATED,
                'priority' => $followUp->priority === 'normal' ? 'high' : $followUp->priority,
            ]);

            $this->audit->record(
                actor: $actor,
                action: 'followup.escalated',
                category: AuditEvent::CATEGORY_BUSINESS,
                module: 'followups',
                branchId: $followUp->branch_id,
                subjectType: $followUp->person_type,
                subjectId: $followUp->person_id,
                before: ['assignee_id' => $fromAssigneeId],
                after: ['assignee_id' => $target->id, 'trigger' => $trigger],
                metadata: ['follow_up_id' => $followUp->id],
            );

            $this->notifyAssignee($followUp->fresh());
        });
    }

    private function resolveEscalationTrigger(FollowUp $followUp): ?string
    {
        if ($followUp->due_date !== null && $followUp->due_date->isPast() && ! in_array($followUp->status, [FollowUp::STATUS_CLOSED, FollowUp::STATUS_SUCCESSFUL], true)) {
            return 'overdue';
        }

        if ($followUp->status === FollowUp::STATUS_UNSUCCESSFUL) {
            return 'unsuccessful';
        }

        if ($followUp->status === FollowUp::STATUS_DECLINED) {
            return 'declined';
        }

        if (in_array($followUp->priority, config('follow_ups.escalation.high_risk_priorities', []), true)
            && $followUp->due_date !== null
            && $followUp->due_date->isPast()) {
            return 'high_risk';
        }

        return null;
    }

    private function resolveEscalationTarget(FollowUp $followUp): ?User
    {
        $roleName = config('follow_ups.escalation.role_name', 'follow_up_lead');
        $role = Role::query()->where('name', $roleName)->first();

        if ($role !== null) {
            $assignment = RoleAssignment::query()
                ->where('role_id', $role->id)
                ->whereHas('user', fn (Builder $q) => $q->where('branch_id', $followUp->branch_id))
                ->with('user')
                ->first();

            if ($assignment?->user !== null && $assignment->user->id !== $followUp->assignee_id) {
                return $assignment->user;
            }
        }

        return User::query()
            ->where('branch_id', $followUp->branch_id)
            ->where('id', '!=', $followUp->assignee_id)
            ->whereHas('assignedRoles.permissions', fn (Builder $query) => $query->where('action', 'followups.escalate'))
            ->first();
    }

    private function notifyAssignee(FollowUp $followUp): void
    {
        $assignee = $followUp->assignee ?? User::query()->find($followUp->assignee_id);
        if ($assignee === null) {
            return;
        }

        $message = $followUp->is_restricted
            ? 'You have been assigned a new follow-up task.'
            : 'New follow-up assigned: ' . mb_substr($followUp->reason, 0, 120);

        $member = Member::query()->where('user_id', $assignee->id)->first();
        if ($member !== null) {
            MemberNotification::create([
                'member_id' => $member->id,
                'user_id' => $assignee->id,
                'type' => 'followup.assigned',
                'message' => $message,
                'metadata' => [
                    'follow_up_id' => $followUp->id,
                    'priority' => $followUp->priority,
                    'due_date' => $followUp->due_date?->toDateString(),
                ],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateCreatePayload(array $payload): array
    {
        return validator($payload, [
            'person_type' => ['required', 'string', 'in:' . Member::class . ',' . Visitor::class],
            'person_id' => ['required', 'integer', 'min:1'],
            'branch_id' => ['required', 'integer', 'exists:organizations,id'],
            'reason' => ['required', 'string', 'max:2000'],
            'assignee_id' => ['required', 'integer', 'exists:users,id'],
            'due_date' => ['required', 'date'],
            'contact_method' => ['nullable', 'string', 'in:' . implode(',', config('follow_ups.contact_methods', []))],
            'priority' => ['nullable', 'string', 'in:' . implode(',', config('follow_ups.priorities', []))],
            'source_type' => ['nullable', 'string', 'in:' . implode(',', config('follow_ups.source_types', []))],
            'source_reference_type' => ['nullable', 'string'],
            'source_reference_id' => ['nullable', 'integer'],
            'is_restricted' => ['nullable', 'boolean'],
        ])->validate();
    }

    private function resolvePerson(string $type, int $id): Model
    {
        $person = match ($type) {
            Member::class => Member::query()->find($id),
            Visitor::class => Visitor::query()->find($id),
            default => null,
        };

        if ($person === null) {
            throw ValidationException::withMessages(['person_id' => ['Person not found.']]);
        }

        return $person;
    }

    private function assertCan(User $actor, string $action): void
    {
        if (! $this->authorization->allows($actor, $action)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function assertCanView(User $actor, FollowUp $followUp): void
    {
        if ($this->authorization->allows($actor, 'followups.read')) {
            $this->assertFollowUpInScope($actor, $followUp);

            return;
        }

        if ($followUp->assignee_id === $actor->id && $this->authorization->allows($actor, 'followups.work')) {
            return;
        }

        throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
    }

    private function assertCanWork(User $actor, FollowUp $followUp): void
    {
        if ($this->authorization->allows($actor, 'followups.manage')) {
            $this->assertFollowUpInScope($actor, $followUp);

            return;
        }

        if ($followUp->assignee_id === $actor->id && $this->authorization->allows($actor, 'followups.work')) {
            $this->assertFollowUpInScope($actor, $followUp);

            return;
        }

        throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
    }

    private function assertPersonInScope(User $actor, Model $person): void
    {
        $branchId = $person->branch_id ?? null;
        if ($branchId === null || $actor->isChurchWide()) {
            return;
        }

        try {
            BranchScope::for($actor)->assertIncludes((int) $branchId);
        } catch (BranchScopeException) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function assertFollowUpInScope(User $actor, FollowUp $followUp): void
    {
        if ($actor->isChurchWide()) {
            return;
        }

        try {
            BranchScope::for($actor)->assertIncludes((int) $followUp->branch_id);
        } catch (BranchScopeException) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    /** @param  Builder<FollowUp>  $query */
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
