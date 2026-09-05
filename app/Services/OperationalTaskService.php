<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Member;
use App\Models\MemberNotification;
use App\Models\OperationalTask;
use App\Models\OperationalTaskReminder;
use App\Models\OperationalTaskTransition;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Story 9.1: create, assign, and complete operational tasks.
 */
class OperationalTaskService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
    ) {
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, OperationalTask>
     */
    public function list(User $actor, array $filters = []): Collection
    {
        $this->assertCan($actor, 'tasks.read');

        $query = OperationalTask::query()
            ->with(['assignee:id,name,email', 'creator:id,name', 'branch:id,name'])
            ->orderBy('due_date')
            ->orderBy('id');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['assignee_id'])) {
            $query->where('assignee_id', $filters['assignee_id']);
        }

        if (! empty($filters['department'])) {
            $query->where('department', $filters['department']);
        }

        $this->applyVisibilityScope($query, $actor);
        $this->applyBranchScope($query, $actor);

        return $query->limit(200)->get();
    }

    public function show(User $actor, OperationalTask $task): OperationalTask
    {
        $this->assertCanView($actor, $task);

        return $task->load([
            'assignee:id,name,email',
            'creator:id,name',
            'branch:id,name',
            'transitions.actor:id,name',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(User $actor, array $payload): OperationalTask
    {
        $this->assertCan($actor, 'tasks.manage');

        $validated = $this->validateCreatePayload($payload);
        $branchId = (int) $validated['branch_id'];
        $this->assertBranchWritable($actor, $branchId);

        $assignee = User::query()->findOrFail((int) $validated['assignee_id']);
        $this->assertAssigneeInScope($actor, $assignee, $branchId);

        $attachments = $this->processAttachments($validated['attachments'] ?? []);

        return DB::transaction(function () use ($actor, $validated, $branchId, $assignee, $attachments): OperationalTask {
            $task = OperationalTask::create([
                'reference' => $this->generateReference(),
                'branch_id' => $branchId,
                'department' => $validated['department'],
                'title' => $validated['title'],
                'description' => $validated['description'],
                'priority' => $validated['priority'] ?? 'normal',
                'status' => OperationalTask::STATUS_OPEN,
                'assignee_id' => $assignee->id,
                'created_by' => $actor->id,
                'due_date' => $validated['due_date'],
                'source_type' => $validated['source_type'] ?? null,
                'source_id' => $validated['source_id'] ?? null,
                'attachments' => $attachments,
            ]);

            $this->recordTransition($actor, $task, null, OperationalTask::STATUS_OPEN, 'Task created and assigned.', null, [
                'assignee_id' => $assignee->id,
            ]);

            $this->audit->record(
                actor: $actor,
                action: 'operational_task.created',
                category: AuditEvent::CATEGORY_BUSINESS,
                module: 'tasks',
                branchId: $task->branch_id,
                subjectType: OperationalTask::class,
                subjectId: $task->id,
                after: [
                    'reference' => $task->reference,
                    'assignee_id' => $task->assignee_id,
                    'department' => $task->department,
                    'priority' => $task->priority,
                    'due_date' => $task->due_date?->toDateString(),
                ],
            );

            $this->notifyUser(
                $assignee,
                'task.assigned',
                "Task {$task->reference} was assigned to you.",
                $task,
            );

            return $task->fresh(['assignee:id,name,email', 'creator:id,name', 'branch:id,name']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function changeStatus(User $actor, OperationalTask $task, array $payload): OperationalTask
    {
        $this->assertCanWork($actor, $task);

        $validated = validator($payload, [
            'status' => ['required', 'string', 'in:' . implode(',', config('operational_tasks.statuses', []))],
            'notes' => ['nullable', 'string', 'max:5000'],
            'completion_evidence' => ['nullable', 'array', 'max:' . (int) config('operational_tasks.attachment_constraints.max_items', 5)],
            'completion_evidence.*.filename' => ['required_with:completion_evidence', 'string', 'max:255'],
            'completion_evidence.*.mime_type' => ['required_with:completion_evidence', 'string', 'max:120'],
            'completion_evidence.*.size_bytes' => ['required_with:completion_evidence', 'integer', 'min:1'],
            'completion_evidence.*.content_hash' => ['required_with:completion_evidence', 'string', 'max:128'],
        ])->validate();

        $to = $validated['status'];
        if ($to === OperationalTask::STATUS_OVERDUE) {
            throw new OperationalTaskException(
                'Overdue status is calculated by the overdue processor, not set manually.',
                'invalid_transition',
                422,
            );
        }

        $this->assertTransition($task->status, $to);

        if ($to === OperationalTask::STATUS_COMPLETED) {
            $evidence = $validated['completion_evidence'] ?? [];
            if ($evidence === []) {
                throw ValidationException::withMessages([
                    'completion_evidence' => ['Completion requires at least one evidence attachment.'],
                ]);
            }
        }

        $evidence = $this->processAttachments($validated['completion_evidence'] ?? []);

        return DB::transaction(function () use ($actor, $task, $validated, $to, $evidence): OperationalTask {
            $from = $task->status;
            $updates = ['status' => $to];

            if ($to === OperationalTask::STATUS_IN_PROGRESS && $task->started_at === null) {
                $updates['started_at'] = now();
            }

            if ($to === OperationalTask::STATUS_COMPLETED) {
                $updates['completed_at'] = now();
                $updates['completion_evidence'] = $evidence;
            }

            if ($to === OperationalTask::STATUS_CANCELLED) {
                $updates['cancelled_at'] = now();
            }

            if (in_array($to, [OperationalTask::STATUS_OPEN, OperationalTask::STATUS_IN_PROGRESS, OperationalTask::STATUS_PENDING], true)
                && $from === OperationalTask::STATUS_OVERDUE) {
                $updates['marked_overdue_at'] = null;
            }

            $task->update($updates);

            $this->recordTransition(
                $actor,
                $task,
                $from,
                $to,
                $validated['notes'] ?? null,
                $to === OperationalTask::STATUS_COMPLETED ? $evidence : null,
            );

            $this->audit->record(
                actor: $actor,
                action: 'operational_task.status_changed',
                category: AuditEvent::CATEGORY_BUSINESS,
                module: 'tasks',
                branchId: $task->branch_id,
                subjectType: OperationalTask::class,
                subjectId: $task->id,
                before: ['status' => $from],
                after: [
                    'status' => $to,
                    'reference' => $task->reference,
                    'has_completion_evidence' => $to === OperationalTask::STATUS_COMPLETED && $evidence !== [],
                ],
            );

            if ($to === OperationalTask::STATUS_COMPLETED && $task->created_by) {
                $creator = User::query()->find($task->created_by);
                if ($creator !== null) {
                    $this->notifyUser(
                        $creator,
                        'task.completed',
                        "Task {$task->reference} was marked completed.",
                        $task,
                    );
                }
            }

            return $task->fresh([
                'assignee:id,name,email',
                'creator:id,name',
                'branch:id,name',
                'transitions.actor:id,name',
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function reassign(User $actor, OperationalTask $task, array $payload): OperationalTask
    {
        $this->assertCan($actor, 'tasks.manage');
        $this->assertBranchWritable($actor, (int) $task->branch_id);

        if ($task->isTerminal()) {
            throw new OperationalTaskException('Terminal tasks cannot be reassigned.', 'invalid_status', 422);
        }

        $validated = validator($payload, [
            'assignee_id' => ['required', 'integer', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        $assignee = User::query()->findOrFail((int) $validated['assignee_id']);
        $this->assertAssigneeInScope($actor, $assignee, (int) $task->branch_id);

        return DB::transaction(function () use ($actor, $task, $assignee, $validated): OperationalTask {
            $from = $task->assignee_id;
            $task->update(['assignee_id' => $assignee->id]);

            $this->recordTransition($actor, $task, $task->status, $task->status, $validated['notes'] ?? 'Reassigned.', null, [
                'from_assignee_id' => $from,
                'to_assignee_id' => $assignee->id,
                'event' => 'reassignment',
            ]);

            $this->audit->record(
                actor: $actor,
                action: 'operational_task.reassigned',
                category: AuditEvent::CATEGORY_BUSINESS,
                module: 'tasks',
                branchId: $task->branch_id,
                subjectType: OperationalTask::class,
                subjectId: $task->id,
                after: [
                    'reference' => $task->reference,
                    'from_assignee_id' => $from,
                    'to_assignee_id' => $assignee->id,
                ],
            );

            $this->notifyUser(
                $assignee,
                'task.assigned',
                "Task {$task->reference} was assigned to you.",
                $task,
            );

            return $task->fresh(['assignee:id,name,email', 'creator:id,name', 'branch:id,name', 'transitions.actor:id,name']);
        });
    }

    /**
     * Mark past-due open tasks overdue and send reminders without duplicates.
     *
     * @return array{processed: int, marked_overdue: int, reminded: int, skipped: int}
     */
    public function processOverdue(User $actor, ?int $branchId = null): array
    {
        $this->assertCan($actor, 'tasks.process_overdue');

        $counts = ['processed' => 0, 'marked_overdue' => 0, 'reminded' => 0, 'skipped' => 0];
        $cooldownHours = (int) config('operational_tasks.overdue.reminder_cooldown_hours', 24);

        $query = OperationalTask::query()
            ->whereIn('status', [
                OperationalTask::STATUS_OPEN,
                OperationalTask::STATUS_IN_PROGRESS,
                OperationalTask::STATUS_PENDING,
                OperationalTask::STATUS_OVERDUE,
            ])
            ->whereDate('due_date', '<', now()->toDateString())
            ->orderBy('id');

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        $this->applyBranchScope($query, $actor);

        foreach ($query->cursor() as $task) {
            $counts['processed']++;

            DB::transaction(function () use ($actor, $task, $cooldownHours, &$counts): void {
                if ($task->status !== OperationalTask::STATUS_OVERDUE) {
                    $from = $task->status;
                    $task->update([
                        'status' => OperationalTask::STATUS_OVERDUE,
                        'marked_overdue_at' => $task->marked_overdue_at ?? now(),
                    ]);

                    $this->recordTransition($actor, $task, $from, OperationalTask::STATUS_OVERDUE, 'Marked overdue by processor.', null, [
                        'event' => 'overdue_mark',
                    ]);
                    $counts['marked_overdue']++;
                }

                $recentReminder = $task->last_overdue_reminder_at !== null
                    && $task->last_overdue_reminder_at->gt(now()->subHours($cooldownHours));

                if ($recentReminder) {
                    $counts['skipped']++;

                    return;
                }

                OperationalTaskReminder::create([
                    'operational_task_id' => $task->id,
                    'reminder_type' => OperationalTaskReminder::TYPE_OVERDUE,
                    'sent_at' => now(),
                    'actor_id' => $actor->id,
                    'metadata' => [
                        'due_date' => $task->due_date?->toDateString(),
                    ],
                ]);

                $task->update(['last_overdue_reminder_at' => now()]);

                $assignee = User::query()->find($task->assignee_id);
                if ($assignee !== null) {
                    $this->notifyUser(
                        $assignee,
                        'task.overdue',
                        "Task {$task->reference} is overdue.",
                        $task,
                    );
                }

                $this->audit->record(
                    actor: $actor,
                    action: 'operational_task.overdue_reminder',
                    category: AuditEvent::CATEGORY_BUSINESS,
                    module: 'tasks',
                    branchId: $task->branch_id,
                    subjectType: OperationalTask::class,
                    subjectId: $task->id,
                    after: ['reference' => $task->reference],
                );

                $counts['reminded']++;
            });
        }

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    public function format(OperationalTask $task): array
    {
        $departments = config('operational_tasks.departments', []);

        return [
            'id' => $task->id,
            'reference' => $task->reference,
            'branch_id' => $task->branch_id,
            'branch' => $task->relationLoaded('branch') ? $task->branch : null,
            'department' => $task->department,
            'department_label' => $departments[$task->department] ?? $task->department,
            'title' => $task->title,
            'description' => $task->description,
            'priority' => $task->priority,
            'status' => $task->status,
            'assignee_id' => $task->assignee_id,
            'assignee' => $task->relationLoaded('assignee') ? $task->assignee : null,
            'created_by' => $task->created_by,
            'creator' => $task->relationLoaded('creator') ? $task->creator : null,
            'due_date' => $task->due_date?->toDateString(),
            'source_type' => $task->source_type,
            'source_id' => $task->source_id,
            'attachments' => $task->attachments ?? [],
            'completion_evidence' => $task->completion_evidence ?? [],
            'started_at' => $task->started_at?->toIso8601String(),
            'completed_at' => $task->completed_at?->toIso8601String(),
            'cancelled_at' => $task->cancelled_at?->toIso8601String(),
            'marked_overdue_at' => $task->marked_overdue_at?->toIso8601String(),
            'last_overdue_reminder_at' => $task->last_overdue_reminder_at?->toIso8601String(),
            'is_past_due' => $task->due_date !== null
                && $task->due_date->isPast()
                && $task->isOpen(),
            'transitions' => $task->relationLoaded('transitions')
                ? $task->transitions->map(fn (OperationalTaskTransition $transition) => [
                    'id' => $transition->id,
                    'from_status' => $transition->from_status,
                    'to_status' => $transition->to_status,
                    'notes' => $transition->notes,
                    'completion_evidence' => $transition->completion_evidence,
                    'actor' => $transition->relationLoaded('actor') ? $transition->actor : null,
                    'metadata' => $transition->metadata,
                    'recorded_at' => $transition->recorded_at?->toIso8601String(),
                ])->values()->all()
                : [],
            'created_at' => $task->created_at?->toIso8601String(),
            'updated_at' => $task->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateCreatePayload(array $payload): array
    {
        return validator($payload, [
            'branch_id' => ['required', 'integer', 'exists:organizations,id'],
            'department' => ['required', 'string', 'in:' . implode(',', array_keys(config('operational_tasks.departments', [])))],
            'title' => ['required', 'string', 'min:3', 'max:255'],
            'description' => ['required', 'string', 'min:5', 'max:5000'],
            'assignee_id' => ['required', 'integer', 'exists:users,id'],
            'priority' => ['nullable', 'string', 'in:' . implode(',', config('operational_tasks.priorities', []))],
            'due_date' => ['required', 'date'],
            'source_type' => ['nullable', 'string', 'max:255'],
            'source_id' => ['nullable', 'integer'],
            'attachments' => ['nullable', 'array', 'max:' . (int) config('operational_tasks.attachment_constraints.max_items', 5)],
            'attachments.*.filename' => ['required_with:attachments', 'string', 'max:255'],
            'attachments.*.mime_type' => ['required_with:attachments', 'string', 'max:120'],
            'attachments.*.size_bytes' => ['required_with:attachments', 'integer', 'min:1'],
            'attachments.*.content_hash' => ['required_with:attachments', 'string', 'max:128'],
        ])->validate();
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function processAttachments(array $items): array
    {
        $constraints = config('operational_tasks.attachment_constraints', []);
        $processed = [];

        foreach ($items as $index => $item) {
            $filename = (string) ($item['filename'] ?? '');
            $mime = (string) ($item['mime_type'] ?? '');
            $size = (int) ($item['size_bytes'] ?? 0);
            $hash = (string) ($item['content_hash'] ?? '');

            if ($filename === '' || str_contains($filename, "\0") || str_contains($filename, '../')) {
                throw ValidationException::withMessages([
                    "attachments.{$index}" => ['Attachment filename is not safe.'],
                ]);
            }

            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (in_array($extension, $constraints['blocked_extensions'] ?? [], true)) {
                throw ValidationException::withMessages([
                    "attachments.{$index}" => ['This attachment type is not permitted.'],
                ]);
            }

            if (! in_array($mime, $constraints['allowed_mime_types'] ?? [], true)) {
                throw ValidationException::withMessages([
                    "attachments.{$index}" => ['Use PDF, JPEG, PNG, WEBP, or plain text attachments only.'],
                ]);
            }

            if ($size > (int) ($constraints['max_size_bytes'] ?? 0)) {
                throw ValidationException::withMessages([
                    "attachments.{$index}" => ['Attachment exceeds the maximum allowed size.'],
                ]);
            }

            $processed[] = [
                'document_id' => (string) Str::uuid(),
                'filename' => $filename,
                'mime_type' => $mime,
                'size_bytes' => $size,
                'content_hash' => $hash,
                'status' => 'accepted',
                'storage_path' => 'tasks/' . $hash . '/' . basename($filename),
            ];
        }

        return $processed;
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     * @param  array<int, array<string, mixed>>|null  $completionEvidence
     */
    private function recordTransition(
        User $actor,
        OperationalTask $task,
        ?string $from,
        string $to,
        ?string $notes = null,
        ?array $completionEvidence = null,
        ?array $metadata = null,
    ): OperationalTaskTransition {
        return OperationalTaskTransition::create([
            'operational_task_id' => $task->id,
            'from_status' => $from,
            'to_status' => $to,
            'notes' => $notes,
            'completion_evidence' => $completionEvidence,
            'actor_id' => $actor->id,
            'metadata' => $metadata,
            'recorded_at' => now(),
        ]);
    }

    private function assertTransition(string $from, string $to): void
    {
        $allowed = config('operational_tasks.transitions.' . $from, []);
        if (! in_array($to, $allowed, true)) {
            throw new OperationalTaskException(
                "Transition from {$from} to {$to} is not allowed.",
                'invalid_transition',
                422,
                ['from' => $from, 'to' => $to],
            );
        }
    }

    private function assertCanView(User $actor, OperationalTask $task): void
    {
        $this->assertCan($actor, 'tasks.read');

        if (! $this->isInBranchScope($actor, (int) $task->branch_id)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }

        if ($this->authorization->allows($actor, 'tasks.manage')) {
            return;
        }

        if ((int) $task->assignee_id === (int) $actor->id || (int) $task->created_by === (int) $actor->id) {
            return;
        }

        throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
    }

    private function assertCanWork(User $actor, OperationalTask $task): void
    {
        if ($task->isTerminal()) {
            throw new OperationalTaskException('Terminal tasks cannot change status.', 'invalid_status', 422);
        }

        if (! $this->isInBranchScope($actor, (int) $task->branch_id)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }

        if ($this->authorization->allows($actor, 'tasks.manage')) {
            return;
        }

        if ($this->authorization->allows($actor, 'tasks.work')
            && (int) $task->assignee_id === (int) $actor->id) {
            return;
        }

        throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
    }

    private function assertAssigneeInScope(User $actor, User $assignee, int $branchId): void
    {
        if ($assignee->isChurchWide()) {
            return;
        }

        if ($assignee->branch_id === null) {
            throw ValidationException::withMessages([
                'assignee_id' => ['Assignee must belong to a branch in your scope.'],
            ]);
        }

        if (! $this->isInBranchScope($actor, (int) $assignee->branch_id)) {
            throw ValidationException::withMessages([
                'assignee_id' => ['Assignment outside your branch scope is not allowed.'],
            ]);
        }

        // Assignee should be able to work on the task's branch.
        if (! $this->isInBranchScope($assignee, $branchId) && ! $assignee->isChurchWide()) {
            throw ValidationException::withMessages([
                'assignee_id' => ['Assignee is outside the selected task branch scope.'],
            ]);
        }
    }

    private function applyVisibilityScope(Builder $query, User $actor): void
    {
        if ($this->authorization->allows($actor, 'tasks.manage')) {
            return;
        }

        $query->where(function (Builder $inner) use ($actor): void {
            $inner->where('assignee_id', $actor->id)
                ->orWhere('created_by', $actor->id);
        });
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

    private function assertBranchWritable(User $actor, int $branchId): void
    {
        if (! $this->isInBranchScope($actor, $branchId)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
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

    private function assertCan(User $actor, string $action): void
    {
        if (! $this->authorization->allows($actor, $action)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function generateReference(): string
    {
        $prefix = (string) config('operational_tasks.reference_prefix', 'TASK');

        do {
            $reference = $prefix . '-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
        } while (OperationalTask::query()->where('reference', $reference)->exists());

        return $reference;
    }

    private function notifyUser(User $user, string $type, string $message, OperationalTask $task): void
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
                'operational_task_id' => $task->id,
                'reference' => $task->reference,
                'priority' => $task->priority,
                'due_date' => $task->due_date?->toDateString(),
                // Intentionally omit description body from notification previews.
            ],
        ]);
    }
}
