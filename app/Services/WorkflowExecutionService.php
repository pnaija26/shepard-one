<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Member;
use App\Models\MemberNotification;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Models\WorkflowInstanceEvent;
use App\Models\WorkflowSchedulerAction;
use App\Models\WorkflowVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Story 9.3: start, advance, and escalate workflow instances.
 */
class WorkflowExecutionService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
    ) {
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, WorkflowInstance>
     */
    public function listInstances(User $actor, array $filters = []): Collection
    {
        if (! $this->authorization->allows($actor, 'workflows.read')
            && ! $this->authorization->allows($actor, 'workflows.participate')
            && ! $this->authorization->allows($actor, 'workflows.manage')) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }

        $query = WorkflowInstance::query()
            ->with(['workflow:id,name', 'assignee:id,name', 'version:id,version'])
            ->orderByDesc('id');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['workflow_id'])) {
            $query->where('workflow_id', $filters['workflow_id']);
        }

        if (! empty($filters['mine']) || (
            ! $this->authorization->allows($actor, 'workflows.manage')
            && ! $this->authorization->allows($actor, 'workflows.read')
        )) {
            $query->where('assignee_id', $actor->id);
        } elseif (! empty($filters['assignee_id'])) {
            $query->where('assignee_id', $filters['assignee_id']);
        }

        $this->applyBranchScope($query, $actor);

        return $query->limit(200)->get();
    }

    public function showInstance(User $actor, WorkflowInstance $instance): WorkflowInstance
    {
        $this->assertCanViewInstance($actor, $instance);

        return $instance->load([
            'workflow:id,name,status,current_version',
            'version',
            'assignee:id,name,email',
            'events.actor:id,name',
        ]);
    }

    /**
     * Start an instance from a published workflow (manual or event trigger).
     *
     * @param  array<string, mixed>  $payload
     */
    public function start(User $actor, Workflow $workflow, array $payload): WorkflowInstance
    {
        if (! $this->authorization->allows($actor, 'workflows.start')
            && ! $this->authorization->allows($actor, 'workflows.manage')) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }

        $this->assertWorkflowInScope($actor, $workflow);

        if ($workflow->status !== Workflow::STATUS_PUBLISHED || $workflow->current_version < 1) {
            throw new WorkflowException(
                'Only published workflows can start instances.',
                'not_published',
                422,
            );
        }

        $validated = validator($payload, [
            'idempotency_key' => ['nullable', 'string', 'max:128'],
            'trigger_type' => ['nullable', 'string', 'in:manual,event'],
            'trigger_event' => ['nullable', 'string', 'max:120'],
            'branch_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'assignee_id' => ['nullable', 'integer', 'exists:users,id'],
            'source_type' => ['nullable', 'string', 'max:255'],
            'source_id' => ['nullable', 'integer'],
            'context' => ['required', 'array'],
        ])->validate();

        $version = WorkflowVersion::query()
            ->where('workflow_id', $workflow->id)
            ->where('version', $workflow->current_version)
            ->where('status', WorkflowVersion::STATUS_PUBLISHED)
            ->first();

        if ($version === null) {
            throw new WorkflowException('Published workflow version is missing.', 'missing_version', 422);
        }

        $definition = $version->definition ?? [];
        $triggerType = $validated['trigger_type'] ?? (string) ($definition['trigger']['type'] ?? 'manual');
        $triggerEvent = $validated['trigger_event'] ?? ($definition['trigger']['event'] ?? null);

        if ($triggerType === 'event') {
            $expected = $definition['trigger']['event'] ?? null;
            if ($expected === null || $triggerEvent !== $expected) {
                throw new WorkflowException(
                    'Trigger event does not match the published workflow.',
                    'trigger_mismatch',
                    422,
                    ['expected' => $expected, 'received' => $triggerEvent],
                );
            }
        }

        $context = $validated['context'];
        $conditionCheck = $this->evaluateConditions($definition['conditions'] ?? [], $context);
        if (! $conditionCheck['passed']) {
            throw new WorkflowException(
                'Context does not satisfy workflow conditions.',
                'incomplete_context',
                422,
                [
                    'conditions' => $conditionCheck['results'],
                    // Never echo full context payloads in failure details beyond field results.
                ],
            );
        }

        $branchId = (int) ($validated['branch_id'] ?? $workflow->branch_id ?? $actor->branch_id ?? 0);
        if ($branchId < 1) {
            throw new WorkflowException(
                'A branch scope is required to start this workflow.',
                'missing_branch',
                422,
            );
        }

        if (! $this->isInBranchScope($actor, $branchId)) {
            throw new WorkflowException(
                'Prohibited branch scope for workflow start.',
                'prohibited_scope',
                403,
            );
        }

        $idempotencyKey = $validated['idempotency_key']
            ?? $this->defaultIdempotencyKey($workflow, $validated, $triggerType, $triggerEvent);

        if ($idempotencyKey !== null) {
            $existing = WorkflowInstance::query()
                ->where('workflow_id', $workflow->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                return $existing->load(['workflow:id,name', 'assignee:id,name', 'events.actor:id,name']);
            }
        }

        $startState = collect($definition['states'] ?? [])->firstWhere('type', 'start');
        if (! is_array($startState)) {
            throw new WorkflowException('Published workflow has no start state.', 'invalid_definition', 422);
        }

        // Advance immediately from start along the submit/default edge to the first actionable state.
        $firstTransition = collect($definition['transitions'] ?? [])
            ->first(fn ($t) => ($t['from'] ?? null) === $startState['key']);

        if (! is_array($firstTransition)) {
            throw new WorkflowException('Published workflow has no transition from start.', 'invalid_definition', 422);
        }

        $currentState = (string) $firstTransition['to'];
        $assignment = $this->assignmentForState($definition, $currentState);
        if ($assignment === null) {
            throw new WorkflowException(
                'First actionable state has no assignment actor.',
                'missing_actor',
                422,
                ['state' => $currentState],
            );
        }

        $assignee = null;
        if (! empty($validated['assignee_id'])) {
            $assignee = User::query()->findOrFail((int) $validated['assignee_id']);
        } else {
            $assignee = $this->resolveAssignee($actor, $branchId, (string) $assignment['permission']);
        }

        if ($assignee === null) {
            throw new WorkflowException(
                'No eligible assignee found for the first workflow step.',
                'missing_assignee',
                422,
            );
        }

        $dueAt = $this->dueAtForState($definition, $currentState);

        try {
            return DB::transaction(function () use (
                $actor,
                $workflow,
                $version,
                $validated,
                $context,
                $branchId,
                $triggerType,
                $triggerEvent,
                $idempotencyKey,
                $startState,
                $currentState,
                $assignment,
                $assignee,
                $dueAt,
            ): WorkflowInstance {
                $instance = WorkflowInstance::create([
                    'reference' => $this->generateReference(),
                    'idempotency_key' => $idempotencyKey,
                    'workflow_id' => $workflow->id,
                    'workflow_version_id' => $version->id,
                    'workflow_version' => $version->version,
                    'branch_id' => $branchId,
                    'trigger_type' => $triggerType,
                    'trigger_event' => $triggerEvent,
                    'source_type' => $validated['source_type'] ?? null,
                    'source_id' => $validated['source_id'] ?? null,
                    'assignee_id' => $assignee->id,
                    'required_permission' => $assignment['permission'],
                    'status' => WorkflowInstance::STATUS_PENDING,
                    'current_state' => $currentState,
                    'context' => $this->redactContext($context),
                    'due_at' => $dueAt,
                    'started_at' => now(),
                    'created_by' => $actor->id,
                ]);

                $this->recordEvent($actor, $instance, WorkflowInstanceEvent::TYPE_STARTED, null, $startState['key'], $currentState, 'Instance started.', [
                    'workflow_version' => $version->version,
                    'trigger_type' => $triggerType,
                ]);

                $this->audit($actor, 'workflow_instance.started', $instance, [
                    'reference' => $instance->reference,
                    'workflow_version' => $version->version,
                    'current_state' => $currentState,
                ]);

                $this->notifyUser(
                    $assignee,
                    'workflow.step.assigned',
                    "Workflow {$instance->reference} was assigned to you.",
                    $instance,
                );

                return $instance->fresh(['workflow:id,name', 'assignee:id,name', 'events.actor:id,name']);
            });
        } catch (\Illuminate\Database\QueryException $exception) {
            // Concurrent idempotent start — return the winner.
            if ($idempotencyKey !== null) {
                $existing = WorkflowInstance::query()
                    ->where('workflow_id', $workflow->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($existing !== null) {
                    return $existing->load(['workflow:id,name', 'assignee:id,name', 'events.actor:id,name']);
                }
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function act(User $actor, WorkflowInstance $instance, array $payload): WorkflowInstance
    {
        $this->assertCanAct($actor, $instance);

        $validated = validator($payload, [
            'decision' => ['required', 'string', 'in:' . implode(',', config('workflows.participant_decisions', []))],
            'comment' => ['nullable', 'string', 'max:5000'],
            'assignee_id' => ['nullable', 'integer', 'exists:users,id'],
        ])->validate();

        if ($instance->isTerminal()) {
            throw new WorkflowException('Terminal workflow instances cannot be advanced.', 'invalid_status', 422);
        }

        $decision = $validated['decision'];
        $version = $instance->version ?? WorkflowVersion::query()->findOrFail($instance->workflow_version_id);
        $definition = $version->definition ?? [];

        if ($decision === 'reassign') {
            return $this->reassign($actor, $instance, $validated, $definition);
        }

        $toState = $this->resolveTransitionTarget($definition, (string) $instance->current_state, $decision);
        if ($toState === null) {
            throw new WorkflowException(
                "Decision {$decision} is not valid from state {$instance->current_state}.",
                'invalid_transition',
                422,
                ['from' => $instance->current_state, 'decision' => $decision],
            );
        }

        return DB::transaction(function () use ($actor, $instance, $definition, $decision, $validated, $toState): WorkflowInstance {
            $from = (string) $instance->current_state;
            $isEnd = $this->isEndState($definition, $toState);
            $nextAssignment = $isEnd ? null : $this->assignmentForState($definition, $toState);

            $updates = [
                'current_state' => $toState,
                'status' => $isEnd
                    ? WorkflowInstance::STATUS_COMPLETED
                    : WorkflowInstance::STATUS_IN_PROGRESS,
            ];

            if ($isEnd) {
                $updates['completed_at'] = now();
                $updates['assignee_id'] = null;
                $updates['required_permission'] = null;
                $updates['due_at'] = null;
            } else {
                $updates['required_permission'] = $nextAssignment['permission'] ?? $instance->required_permission;
                $updates['due_at'] = $this->dueAtForState($definition, $toState);
                // Keep assignee unless reassigned; for reject/return may need new actor — keep current if still eligible.
            }

            $instance->update($updates);

            $eventType = match ($decision) {
                'approve' => WorkflowInstanceEvent::TYPE_APPROVED,
                'reject' => WorkflowInstanceEvent::TYPE_REJECTED,
                'return' => WorkflowInstanceEvent::TYPE_RETURNED,
                'complete' => WorkflowInstanceEvent::TYPE_COMPLETED,
                default => WorkflowInstanceEvent::TYPE_TRANSITIONED,
            };

            $this->recordEvent(
                $actor,
                $instance,
                $eventType,
                $decision,
                $from,
                $toState,
                $validated['comment'] ?? null,
            );

            $this->audit($actor, 'workflow_instance.transitioned', $instance, [
                'decision' => $decision,
                'from_state' => $from,
                'to_state' => $toState,
                'reference' => $instance->reference,
            ]);

            if (! $isEnd && $instance->assignee_id) {
                $assignee = User::query()->find($instance->assignee_id);
                if ($assignee !== null) {
                    $this->notifyUser(
                        $assignee,
                        'workflow.step.updated',
                        "Workflow {$instance->reference} advanced to {$toState}.",
                        $instance,
                    );
                }
            }

            return $instance->fresh(['workflow:id,name', 'assignee:id,name', 'events.actor:id,name']);
        });
    }

    /**
     * Process overdue steps: reminders then escalations, once per window.
     *
     * @return array{processed: int, reminded: int, escalated: int, skipped: int}
     */
    public function processDeadlines(User $actor, ?int $branchId = null): array
    {
        if (! $this->authorization->allows($actor, 'workflows.process_deadlines')
            && ! $this->authorization->allows($actor, 'workflows.manage')) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }

        $counts = ['processed' => 0, 'reminded' => 0, 'escalated' => 0, 'skipped' => 0];

        $query = WorkflowInstance::query()
            ->with(['version'])
            ->whereIn('status', config('workflows.instance_open_statuses', []))
            ->whereNotNull('due_at')
            ->where('due_at', '<', now()->toDateTimeString())
            ->orderBy('id');

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        $this->applyBranchScope($query, $actor);

        foreach ($query->cursor() as $instance) {
            $counts['processed']++;
            $definition = $instance->version?->definition ?? [];
            $escalation = is_array($definition['escalation'] ?? null) ? $definition['escalation'] : [];
            $maxLoops = max(1, (int) ($escalation['max_loops'] ?? config('workflows.max_loop_limit', 10)));
            $afterHours = max(0, (int) ($escalation['after_hours'] ?? 0));
            $hoursOverdue = max(0, (int) floor((now()->getTimestamp() - $instance->due_at->getTimestamp()) / 3600));

            if ($hoursOverdue >= $afterHours && (int) $instance->escalation_count < $maxLoops) {
                // One escalation per due-at window; further escalations require a new due_at (e.g. after reassignment).
                $windowKey = 'escalation:' . $instance->due_at->getTimestamp();
                if ($this->claimSchedulerWindow($instance, WorkflowSchedulerAction::TYPE_ESCALATION, $windowKey, $actor)) {
                    $this->escalateInstance($actor, $instance, $definition, $escalation);
                    $counts['escalated']++;

                    continue;
                }

                $counts['skipped']++;

                continue;
            }

            $cooldown = (int) config('workflows.scheduler.reminder_cooldown_hours', 24);
            $windowKey = 'reminder:' . $instance->due_at->timestamp . ':' . now()->subHours($cooldown)->format('YmdH');
            // Simpler stable window: one reminder per due_at + cooldown bucket
            $bucket = (int) floor(now()->getTimestamp() / max(1, $cooldown * 3600));
            $windowKey = 'reminder:' . $instance->due_at->getTimestamp() . ':b' . $bucket;

            if ($this->claimSchedulerWindow($instance, WorkflowSchedulerAction::TYPE_REMINDER, $windowKey, $actor)) {
                $this->remindInstance($actor, $instance);
                $counts['reminded']++;
            } else {
                $counts['skipped']++;
            }
        }

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    public function formatInstance(WorkflowInstance $instance): array
    {
        return [
            'id' => $instance->id,
            'reference' => $instance->reference,
            'workflow_id' => $instance->workflow_id,
            'workflow' => $instance->relationLoaded('workflow') ? $instance->workflow : null,
            'workflow_version_id' => $instance->workflow_version_id,
            'workflow_version' => $instance->workflow_version,
            'branch_id' => $instance->branch_id,
            'trigger_type' => $instance->trigger_type,
            'trigger_event' => $instance->trigger_event,
            'source_type' => $instance->source_type,
            'source_id' => $instance->source_id,
            'assignee_id' => $instance->assignee_id,
            'assignee' => $instance->relationLoaded('assignee') ? $instance->assignee : null,
            'required_permission' => $instance->required_permission,
            'status' => $instance->status,
            'current_state' => $instance->current_state,
            'context' => $instance->context,
            'due_at' => $instance->due_at?->toIso8601String(),
            'started_at' => $instance->started_at?->toIso8601String(),
            'completed_at' => $instance->completed_at?->toIso8601String(),
            'escalation_count' => $instance->escalation_count,
            'last_reminder_at' => $instance->last_reminder_at?->toIso8601String(),
            'last_escalated_at' => $instance->last_escalated_at?->toIso8601String(),
            'failure_code' => $instance->failure_code,
            'failure_message' => $instance->failure_message,
            'events' => $instance->relationLoaded('events')
                ? $instance->events->map(fn (WorkflowInstanceEvent $event) => [
                    'id' => $event->id,
                    'event_type' => $event->event_type,
                    'decision' => $event->decision,
                    'from_state' => $event->from_state,
                    'to_state' => $event->to_state,
                    'comment' => $event->comment,
                    'actor' => $event->relationLoaded('actor') ? $event->actor : null,
                    'recorded_at' => $event->recorded_at?->toIso8601String(),
                    'metadata' => $event->metadata,
                ])->values()->all()
                : [],
            'created_at' => $instance->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $definition
     */
    private function reassign(User $actor, WorkflowInstance $instance, array $validated, array $definition): WorkflowInstance
    {
        if (empty($validated['assignee_id'])) {
            throw ValidationException::withMessages([
                'assignee_id' => ['Reassignment requires assignee_id.'],
            ]);
        }

        $assignee = User::query()->findOrFail((int) $validated['assignee_id']);
        $permission = (string) ($instance->required_permission ?? '');
        if ($permission !== '' && ! $this->authorization->allows($assignee, $permission)
            && ! $this->authorization->allows($assignee, 'workflows.participate')) {
            throw ValidationException::withMessages([
                'assignee_id' => ['Assignee lacks the required workflow permission.'],
            ]);
        }

        if (! $this->isInBranchScope($assignee, (int) $instance->branch_id) && ! $assignee->isChurchWide()) {
            throw ValidationException::withMessages([
                'assignee_id' => ['Assignee is outside the instance branch scope.'],
            ]);
        }

        return DB::transaction(function () use ($actor, $instance, $assignee, $validated): WorkflowInstance {
            $from = $instance->assignee_id;
            $instance->update(['assignee_id' => $assignee->id]);

            $this->recordEvent(
                $actor,
                $instance,
                WorkflowInstanceEvent::TYPE_REASSIGNED,
                'reassign',
                $instance->current_state,
                $instance->current_state,
                $validated['comment'] ?? 'Reassigned.',
                null,
                $from,
                $assignee->id,
            );

            $this->audit($actor, 'workflow_instance.reassigned', $instance, [
                'from_assignee_id' => $from,
                'to_assignee_id' => $assignee->id,
            ]);

            $this->notifyUser(
                $assignee,
                'workflow.step.assigned',
                "Workflow {$instance->reference} was assigned to you.",
                $instance,
            );

            return $instance->fresh(['workflow:id,name', 'assignee:id,name', 'events.actor:id,name']);
        });
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $escalation
     */
    private function escalateInstance(User $actor, WorkflowInstance $instance, array $definition, array $escalation): void
    {
        $permission = (string) ($escalation['to_permission'] ?? $instance->required_permission ?? 'workflows.participate');
        $target = $this->resolveAssignee($actor, (int) $instance->branch_id, $permission, $instance->assignee_id);

        DB::transaction(function () use ($actor, $instance, $target, $permission): void {
            $from = $instance->assignee_id;
            $instance->update([
                'assignee_id' => $target?->id ?? $instance->assignee_id,
                'required_permission' => $permission,
                'escalation_count' => (int) $instance->escalation_count + 1,
                'last_escalated_at' => now(),
                'status' => WorkflowInstance::STATUS_PENDING,
            ]);

            $this->recordEvent(
                $actor,
                $instance,
                WorkflowInstanceEvent::TYPE_ESCALATED,
                null,
                $instance->current_state,
                $instance->current_state,
                'Escalated after deadline.',
                ['escalation_count' => $instance->escalation_count],
                $from,
                $target?->id,
            );

            $this->audit($actor, 'workflow_instance.escalated', $instance, [
                'escalation_count' => $instance->escalation_count,
                'to_assignee_id' => $target?->id,
            ]);

            if ($target !== null) {
                $this->notifyUser(
                    $target,
                    'workflow.step.escalated',
                    "Workflow {$instance->reference} was escalated to you.",
                    $instance,
                );
            }
        });
    }

    private function remindInstance(User $actor, WorkflowInstance $instance): void
    {
        DB::transaction(function () use ($actor, $instance): void {
            $instance->update(['last_reminder_at' => now()]);

            $this->recordEvent(
                $actor,
                $instance,
                WorkflowInstanceEvent::TYPE_REMINDED,
                null,
                $instance->current_state,
                $instance->current_state,
                'Deadline reminder sent.',
            );

            $this->audit($actor, 'workflow_instance.reminded', $instance, [
                'reference' => $instance->reference,
                'due_at' => $instance->due_at?->toIso8601String(),
            ]);

            if ($instance->assignee_id) {
                $assignee = User::query()->find($instance->assignee_id);
                if ($assignee !== null) {
                    $this->notifyUser(
                        $assignee,
                        'workflow.step.reminder',
                        "Workflow {$instance->reference} is past due.",
                        $instance,
                    );
                }
            }
        });
    }

    private function claimSchedulerWindow(
        WorkflowInstance $instance,
        string $actionType,
        string $windowKey,
        User $actor,
    ): bool {
        try {
            WorkflowSchedulerAction::create([
                'workflow_instance_id' => $instance->id,
                'action_type' => $actionType,
                'window_key' => $windowKey,
                'actor_id' => $actor->id,
                'executed_at' => now(),
                'metadata' => ['due_at' => $instance->due_at?->toIso8601String()],
            ]);

            return true;
        } catch (\Illuminate\Database\QueryException) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function resolveTransitionTarget(array $definition, string $from, string $decision): ?string
    {
        $transitions = collect($definition['transitions'] ?? [])
            ->filter(fn ($t) => ($t['from'] ?? null) === $from);

        $match = $transitions->first(function ($t) use ($decision) {
            $on = $t['on'] ?? $t['action'] ?? null;

            return $on === $decision;
        });

        if (is_array($match)) {
            return (string) $match['to'];
        }

        if ($decision === 'reject' && ! empty($definition['rejection']['target_state'])) {
            return (string) $definition['rejection']['target_state'];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function isEndState(array $definition, string $state): bool
    {
        if (in_array($state, $definition['end_states'] ?? [], true)) {
            return true;
        }

        $node = collect($definition['states'] ?? [])->firstWhere('key', $state);

        return is_array($node) && ($node['type'] ?? null) === 'end';
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>|null
     */
    private function assignmentForState(array $definition, string $state): ?array
    {
        $assignment = collect($definition['assignments'] ?? [])
            ->first(fn ($a) => ($a['state'] ?? null) === $state);

        return is_array($assignment) ? $assignment : null;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function dueAtForState(array $definition, string $state): \Carbon\CarbonInterface
    {
        $deadline = collect($definition['deadlines'] ?? [])
            ->first(fn ($d) => ($d['state'] ?? null) === $state);

        $hours = (int) ($deadline['hours'] ?? config('workflows.scheduler.default_deadline_hours', 24));

        return now()->addHours(max(1, $hours));
    }

    private function resolveAssignee(User $actor, int $branchId, string $permission, ?int $excludeUserId = null): ?User
    {
        $candidates = User::query()
            ->when($excludeUserId, fn ($q) => $q->where('id', '!=', $excludeUserId))
            ->where(function (Builder $q) use ($branchId): void {
                $q->whereNull('branch_id')->orWhere('branch_id', $branchId);
            })
            ->orderBy('id')
            ->limit(50)
            ->get();

        foreach ($candidates as $candidate) {
            if ($this->authorization->allows($candidate, $permission)
                || $this->authorization->allows($candidate, 'workflows.participate')) {
                return $candidate;
            }
        }

        // Fall back to the starting actor if they are eligible.
        if ($this->authorization->allows($actor, $permission)
            || $this->authorization->allows($actor, 'workflows.participate')) {
            return $actor;
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $conditions
     * @param  array<string, mixed>  $sample
     * @return array{passed: bool, results: array<int, array<string, mixed>>}
     */
    private function evaluateConditions(array $conditions, array $sample): array
    {
        $results = [];
        $passed = true;

        foreach ($conditions as $condition) {
            $field = (string) ($condition['field'] ?? '');
            $operator = (string) ($condition['operator'] ?? 'eq');
            $expected = $condition['value'] ?? null;
            $actual = data_get($sample, $field);
            $ok = match ($operator) {
                'eq' => $actual == $expected,
                'neq' => $actual != $expected,
                'gt' => is_numeric($actual) && is_numeric($expected) && $actual > $expected,
                'gte' => is_numeric($actual) && is_numeric($expected) && $actual >= $expected,
                'lt' => is_numeric($actual) && is_numeric($expected) && $actual < $expected,
                'lte' => is_numeric($actual) && is_numeric($expected) && $actual <= $expected,
                'in' => is_array($expected) && in_array($actual, $expected, true),
                'exists' => $actual !== null && $actual !== '',
                default => false,
            };
            $results[] = [
                'field' => $field,
                'operator' => $operator,
                'expected' => $expected,
                'passed' => $ok,
                // Omit raw actual values that might be sensitive from API error details when failed.
                'actual' => $ok ? $actual : null,
            ];
            if (! $ok) {
                $passed = false;
            }
        }

        return ['passed' => $conditions === [] ? true : $passed, 'results' => $results];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function redactContext(array $context): array
    {
        $redacted = $context;
        foreach (['password', 'secret', 'token', 'ssn', 'national_id'] as $key) {
            if (array_key_exists($key, $redacted)) {
                $redacted[$key] = '[redacted]';
            }
        }

        return $redacted;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function defaultIdempotencyKey(
        Workflow $workflow,
        array $validated,
        string $triggerType,
        ?string $triggerEvent,
    ): ?string {
        if (! empty($validated['source_type']) && ! empty($validated['source_id'])) {
            return 'src:' . $validated['source_type'] . ':' . $validated['source_id'];
        }

        if ($triggerType === 'event' && $triggerEvent) {
            // Without source, event starts still need an explicit key from caller.
            return null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function recordEvent(
        User $actor,
        WorkflowInstance $instance,
        string $type,
        ?string $decision,
        ?string $from,
        ?string $to,
        ?string $comment = null,
        ?array $metadata = null,
        ?int $fromAssignee = null,
        ?int $toAssignee = null,
    ): WorkflowInstanceEvent {
        return WorkflowInstanceEvent::create([
            'workflow_instance_id' => $instance->id,
            'event_type' => $type,
            'decision' => $decision,
            'from_state' => $from,
            'to_state' => $to,
            'comment' => $comment,
            'actor_id' => $actor->id,
            'from_assignee_id' => $fromAssignee,
            'to_assignee_id' => $toAssignee,
            'metadata' => $metadata,
            'recorded_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function audit(User $actor, string $action, WorkflowInstance $instance, array $after = []): void
    {
        $this->audit->record(
            actor: $actor,
            action: $action,
            category: AuditEvent::CATEGORY_BUSINESS,
            module: 'workflows',
            branchId: $instance->branch_id,
            subjectType: WorkflowInstance::class,
            subjectId: $instance->id,
            after: $after,
        );
    }

    private function notifyUser(User $user, string $type, string $message, WorkflowInstance $instance): void
    {
        $member = Member::query()->where('user_id', $user->id)->first();
        if ($member === null) {
            return;
        }

        MemberNotification::create([
            'member_id' => $member->id,
            'user_id' => $user->id,
            'type' => $type,
            'message' => $message,
            'metadata' => [
                'workflow_instance_id' => $instance->id,
                'reference' => $instance->reference,
                'current_state' => $instance->current_state,
                // Intentionally omit context payload.
            ],
        ]);
    }

    private function generateReference(): string
    {
        do {
            $reference = 'WFI-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
        } while (WorkflowInstance::query()->where('reference', $reference)->exists());

        return $reference;
    }

    private function assertCanViewInstance(User $actor, WorkflowInstance $instance): void
    {
        if ($this->authorization->allows($actor, 'workflows.manage')
            || $this->authorization->allows($actor, 'workflows.read')) {
            if ($instance->branch_id && ! $this->isInBranchScope($actor, (int) $instance->branch_id)) {
                throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
            }

            return;
        }

        if ($this->authorization->allows($actor, 'workflows.participate')
            && (int) $instance->assignee_id === (int) $actor->id) {
            return;
        }

        throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
    }

    private function assertCanAct(User $actor, WorkflowInstance $instance): void
    {
        if (! $this->isInBranchScope($actor, (int) $instance->branch_id)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }

        if ($this->authorization->allows($actor, 'workflows.manage')) {
            return;
        }

        if (! $this->authorization->allows($actor, 'workflows.participate')) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }

        if ((int) $instance->assignee_id !== (int) $actor->id) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }

        $required = (string) ($instance->required_permission ?? '');
        if ($required !== '' && ! $this->authorization->allows($actor, $required)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function assertWorkflowInScope(User $actor, Workflow $workflow): void
    {
        if ($workflow->branch_id === null) {
            return;
        }

        if (! $this->isInBranchScope($actor, (int) $workflow->branch_id)) {
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
