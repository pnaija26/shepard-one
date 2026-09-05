<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\CareCase;
use App\Models\CareCaseActivity;
use App\Models\CareCaseEscalation;
use App\Models\Member;
use App\Models\MemberNotification;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stories 8.1–8.2: create, deliver, escalate, and close restricted pastoral care cases.
 */
class CareCaseService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
    ) {
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, CareCase>
     */
    public function listCases(User $actor, array $filters = []): Collection
    {
        $this->assertCan($actor, 'care.cases.read');

        $query = CareCase::query()
            ->with(['branch:id,name', 'assignedOfficer:id,name', 'beneficiary:id,first_name,last_name,membership_id'])
            ->orderByDesc('id');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        $this->applyBranchScope($query, $actor);
        $this->applyVisibilityFilter($query, $actor);

        return $query->limit(200)->get();
    }

    public function showCase(User $actor, CareCase $case): CareCase
    {
        if (! $this->canDiscover($actor, $case)) {
            $this->auditAccessDenied($actor, $case, 'show');

            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }

        if ($this->canReadSensitive($actor, $case)) {
            $this->audit->record(
                actor: $actor,
                action: 'care_case.sensitive_viewed',
                category: AuditEvent::CATEGORY_SECURITY,
                module: 'care',
                branchId: $case->branch_id,
                subjectType: CareCase::class,
                subjectId: $case->id,
                after: [
                    'case_number' => $case->case_number,
                    'data_classification' => $case->data_classification,
                ],
            );
        }

        return $case->load([
            'branch:id,name',
            'assignedOfficer:id,name',
            'beneficiary:id,first_name,last_name,membership_id',
            'creator:id,name',
            'activities.actor:id,name',
            'activities.responsibleOfficer:id,name',
            'escalations.toOfficer:id,name',
            'escalations.fromOfficer:id,name',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createCase(User $actor, array $payload): CareCase
    {
        $this->assertCan($actor, 'care.cases.create');

        $validated = $this->validateCreatePayload($payload);
        $this->assertBranchWritable($actor, (int) $validated['branch_id']);

        $beneficiary = Member::query()->findOrFail((int) $validated['beneficiary_member_id']);
        $this->assertMemberInScope($actor, $beneficiary);

        if ((int) $beneficiary->branch_id !== (int) $validated['branch_id']) {
            throw ValidationException::withMessages([
                'beneficiary_member_id' => ['Beneficiary must belong to the selected branch.'],
            ]);
        }

        $assignee = null;
        if (! empty($validated['assigned_officer_id'])) {
            $this->assertCan($actor, 'care.cases.manage');
            $assignee = User::query()->findOrFail((int) $validated['assigned_officer_id']);
            $this->assertEligibleCareOfficer($assignee, (int) $validated['branch_id']);
        } else {
            $assignee = $this->resolveEligibleOfficer((int) $validated['branch_id'], $validated['confidentiality']);
        }

        if ($assignee === null) {
            throw new CareCaseException(
                'No eligible care officer is available for this branch and confidentiality level.',
                'no_eligible_officer',
                422,
            );
        }

        $evidence = $this->processEvidence($validated['evidence'] ?? []);

        return DB::transaction(function () use ($actor, $validated, $beneficiary, $assignee, $evidence): CareCase {
            $case = CareCase::create([
                'case_number' => $this->generateCaseNumber(),
                'branch_id' => $validated['branch_id'],
                'beneficiary_member_id' => $beneficiary->id,
                'category' => $validated['category'],
                'description' => $validated['description'],
                'sensitive_notes' => $validated['sensitive_notes'] ?? null,
                'priority' => $validated['priority'],
                'status' => CareCase::STATUS_ASSIGNED,
                'consent_basis' => $validated['consent_basis'],
                'confidentiality' => $validated['confidentiality'],
                'data_classification' => (string) config('care_cases.data_classification', 'restricted_sensitive'),
                'is_restricted' => true,
                'evidence' => $evidence,
                'assigned_care_role' => (string) config('care_cases.assignee_permission', 'care.cases.manage'),
                'assigned_officer_id' => $assignee->id,
                'assigned_at' => now(),
                'next_follow_up_on' => now()->addDays((int) config('care_cases.default_follow_up_days', 7))->toDateString(),
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->audit->record(
                actor: $actor,
                action: 'care_case.created',
                category: AuditEvent::CATEGORY_BUSINESS,
                module: 'care',
                branchId: $case->branch_id,
                subjectType: CareCase::class,
                subjectId: $case->id,
                after: [
                    'case_number' => $case->case_number,
                    'category' => $case->category,
                    'priority' => $case->priority,
                    'confidentiality' => $case->confidentiality,
                    'assigned_officer_id' => $case->assigned_officer_id,
                    'data_classification' => $case->data_classification,
                    // Never log encrypted narrative content.
                ],
            );

            $this->notifyAssignee($case, $assignee);

            return $case->fresh([
                'branch:id,name',
                'assignedOfficer:id,name',
                'beneficiary:id,first_name,last_name,membership_id',
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function recordActivity(User $actor, CareCase $case, array $payload): CareCaseActivity
    {
        $this->assertCanWork($actor, $case);

        if ($case->status === CareCase::STATUS_CLOSED) {
            throw new CareCaseException('Closed care cases cannot receive new care activities. Reopen the case first.', 'closed', 422);
        }

        $validated = validator($payload, [
            'activity_type' => ['required', 'string', 'in:' . implode(',', config('care_cases.activity_types', []))],
            'outcome' => ['nullable', 'string', 'in:' . implode(',', config('care_cases.outcomes', []))],
            'notes' => ['nullable', 'string', 'max:5000'],
            'restricted_note' => ['nullable', 'string', 'max:5000'],
            'next_follow_up_on' => ['nullable', 'date'],
            'responsible_officer_id' => ['nullable', 'integer', 'exists:users,id'],
        ])->validate();

        $responsibleId = $validated['responsible_officer_id'] ?? $case->assigned_officer_id;
        if (! empty($validated['responsible_officer_id'])) {
            $officer = User::query()->findOrFail((int) $validated['responsible_officer_id']);
            $this->assertEligibleCareOfficer($officer, (int) $case->branch_id);
            $responsibleId = $officer->id;
        }

        $followUp = $validated['next_follow_up_on']
            ?? ($case->next_follow_up_on?->toDateString()
                ?? now()->addDays((int) config('care_cases.default_follow_up_days', 7))->toDateString());

        return DB::transaction(function () use ($actor, $case, $validated, $responsibleId, $followUp): CareCaseActivity {
            $activity = CareCaseActivity::create([
                'care_case_id' => $case->id,
                'activity_type' => $validated['activity_type'],
                'outcome' => $validated['outcome'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'restricted_note' => $validated['restricted_note'] ?? null,
                'next_follow_up_on' => $followUp,
                'actor_id' => $actor->id,
                'responsible_officer_id' => $responsibleId,
                'metadata' => [
                    'previous_officer_id' => $case->assigned_officer_id,
                ],
                'recorded_at' => now(),
            ]);

            $status = CareCase::STATUS_IN_PROGRESS;
            if (($validated['outcome'] ?? null) === 'resolved') {
                $status = CareCase::STATUS_RESOLVED;
            } elseif ($case->status === CareCase::STATUS_ESCALATED) {
                $status = CareCase::STATUS_ESCALATED;
            }

            $case->update([
                'status' => $status,
                'assigned_officer_id' => $responsibleId,
                'next_follow_up_on' => $followUp,
                'updated_by' => $actor->id,
            ]);

            $this->audit->record(
                actor: $actor,
                action: 'care_case.activity_recorded',
                category: AuditEvent::CATEGORY_BUSINESS,
                module: 'care',
                branchId: $case->branch_id,
                subjectType: CareCase::class,
                subjectId: $case->id,
                after: [
                    'activity_id' => $activity->id,
                    'activity_type' => $activity->activity_type,
                    'outcome' => $activity->outcome,
                    'next_follow_up_on' => $followUp,
                    'responsible_officer_id' => $responsibleId,
                ],
            );

            return $activity->fresh(['actor:id,name', 'responsibleOfficer:id,name']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function escalateCase(User $actor, CareCase $case, array $payload): CareCaseEscalation
    {
        $this->assertCanEscalate($actor, $case);

        if (! in_array($case->status, config('care_cases.open_statuses', []), true)) {
            throw new CareCaseException('Only open care cases can be escalated.', 'invalid_status', 422);
        }

        $validated = validator($payload, [
            'trigger_type' => ['required', 'string', 'in:' . implode(',', config('care_cases.escalation_triggers', []))],
            'to_officer_id' => ['nullable', 'integer', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        $target = ! empty($validated['to_officer_id'])
            ? User::query()->findOrFail((int) $validated['to_officer_id'])
            : $this->resolveEscalationTarget($case);

        if ($target === null) {
            throw new CareCaseException(
                'No qualified escalation officer is available for this case.',
                'no_escalation_target',
                422,
            );
        }

        $this->assertEligibleEscalationTarget($target, (int) $case->branch_id);

        if ((int) $target->id === (int) $case->assigned_officer_id) {
            throw new CareCaseException('Escalation target must differ from the current assignee.', 'invalid_target', 422);
        }

        return $this->performEscalation($actor, $case, $target, $validated['trigger_type'], $validated['notes'] ?? null);
    }

    /**
     * @return array{processed: int, escalated: int, skipped: int}
     */
    public function processEscalations(User $actor, ?int $branchId = null): array
    {
        if (! $this->authorization->allows($actor, 'care.cases.escalate')
            && ! $this->authorization->allows($actor, 'care.cases.manage')) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }

        $counts = ['processed' => 0, 'escalated' => 0, 'skipped' => 0];

        $query = CareCase::query()
            ->whereIn('status', config('care_cases.open_statuses', []))
            ->orderBy('id');

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        $this->applyBranchScope($query, $actor);

        foreach ($query->cursor() as $case) {
            $counts['processed']++;
            $trigger = $this->resolveEscalationTrigger($case);

            if ($trigger === null) {
                $counts['skipped']++;

                continue;
            }

            if (CareCaseEscalation::query()
                ->where('care_case_id', $case->id)
                ->where('trigger_type', $trigger)
                ->exists()) {
                $counts['skipped']++;

                continue;
            }

            $target = $this->resolveEscalationTarget($case);
            if ($target === null) {
                $counts['skipped']++;

                continue;
            }

            $this->performEscalation($actor, $case, $target, $trigger, 'Automatic escalation: ' . $trigger);
            $counts['escalated']++;
        }

        return $counts;
    }

    public function acknowledgeEscalation(User $actor, CareCaseEscalation $escalation): CareCaseEscalation
    {
        $case = $escalation->careCase ?? CareCase::query()->findOrFail($escalation->care_case_id);

        if ((int) $escalation->to_officer_id !== (int) $actor->id
            && ! $this->authorization->allows($actor, 'care.cases.escalate')) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }

        if (! $this->canDiscover($actor, $case)) {
            $this->auditAccessDenied($actor, $case, 'acknowledge_escalation');

            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }

        if ($escalation->acknowledged_at !== null) {
            throw new CareCaseException('Escalation has already been acknowledged.', 'already_acknowledged', 422);
        }

        return DB::transaction(function () use ($actor, $case, $escalation): CareCaseEscalation {
            $escalation->update([
                'acknowledged_by' => $actor->id,
                'acknowledged_at' => now(),
            ]);

            CareCaseActivity::create([
                'care_case_id' => $case->id,
                'activity_type' => 'note',
                'outcome' => null,
                'notes' => 'Escalation acknowledged.',
                'actor_id' => $actor->id,
                'responsible_officer_id' => $case->assigned_officer_id,
                'metadata' => ['escalation_id' => $escalation->id, 'system_event' => 'acknowledgement'],
                'recorded_at' => now(),
            ]);

            $this->audit->record(
                actor: $actor,
                action: 'care_case.escalation_acknowledged',
                category: AuditEvent::CATEGORY_SECURITY,
                module: 'care',
                branchId: $case->branch_id,
                subjectType: CareCase::class,
                subjectId: $case->id,
                after: [
                    'escalation_id' => $escalation->id,
                    'trigger_type' => $escalation->trigger_type,
                ],
            );

            return $escalation->fresh(['toOfficer:id,name', 'acknowledgedBy:id,name']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function closeCase(User $actor, CareCase $case, array $payload): CareCase
    {
        $this->assertCanClose($actor, $case);

        if ($case->status === CareCase::STATUS_CLOSED) {
            throw new CareCaseException('Care case is already closed.', 'already_closed', 422);
        }

        $validated = validator($payload, [
            'closure_reason' => ['required', 'string', 'in:' . implode(',', config('care_cases.closure_reasons', []))],
            'closure_outcome' => ['required', 'string', 'min:5', 'max:5000'],
            'future_care_plan' => ['nullable', 'string', 'max:5000'],
        ])->validate();

        return DB::transaction(function () use ($actor, $case, $validated): CareCase {
            $case->update([
                'status' => CareCase::STATUS_CLOSED,
                'closed_at' => now(),
                'closure_reason' => $validated['closure_reason'],
                'closure_outcome' => $validated['closure_outcome'],
                'future_care_plan' => $validated['future_care_plan'] ?? null,
                'updated_by' => $actor->id,
            ]);

            CareCaseActivity::create([
                'care_case_id' => $case->id,
                'activity_type' => 'outcome',
                'outcome' => 'resolved',
                'notes' => 'Case closed: ' . $validated['closure_reason'],
                'restricted_note' => $validated['closure_outcome'],
                'actor_id' => $actor->id,
                'responsible_officer_id' => $case->assigned_officer_id,
                'metadata' => [
                    'closure_reason' => $validated['closure_reason'],
                    'system_event' => 'closure',
                ],
                'recorded_at' => now(),
            ]);

            $this->audit->record(
                actor: $actor,
                action: 'care_case.closed',
                category: AuditEvent::CATEGORY_BUSINESS,
                module: 'care',
                branchId: $case->branch_id,
                subjectType: CareCase::class,
                subjectId: $case->id,
                after: [
                    'closure_reason' => $validated['closure_reason'],
                ],
            );

            return $case->fresh([
                'branch:id,name',
                'assignedOfficer:id,name',
                'activities.actor:id,name',
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function reopenCase(User $actor, CareCase $case, array $payload): CareCase
    {
        $this->assertCanReopen($actor, $case);

        if ($case->status !== CareCase::STATUS_CLOSED) {
            throw new CareCaseException('Only closed care cases can be reopened.', 'invalid_status', 422);
        }

        $validated = validator($payload, [
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'assigned_officer_id' => ['nullable', 'integer', 'exists:users,id'],
        ])->validate();

        $officerId = $case->assigned_officer_id;
        if (! empty($validated['assigned_officer_id'])) {
            $officer = User::query()->findOrFail((int) $validated['assigned_officer_id']);
            $this->assertEligibleCareOfficer($officer, (int) $case->branch_id);
            $officerId = $officer->id;
        }

        return DB::transaction(function () use ($actor, $case, $validated, $officerId): CareCase {
            $case->update([
                'status' => CareCase::STATUS_ASSIGNED,
                'reopened_at' => now(),
                'reopen_reason' => $validated['reason'],
                'closed_at' => null,
                'assigned_officer_id' => $officerId,
                'assigned_at' => now(),
                'next_follow_up_on' => now()->addDays((int) config('care_cases.default_follow_up_days', 7))->toDateString(),
                'updated_by' => $actor->id,
            ]);

            CareCaseActivity::create([
                'care_case_id' => $case->id,
                'activity_type' => 'note',
                'notes' => 'Case reopened: ' . $validated['reason'],
                'actor_id' => $actor->id,
                'responsible_officer_id' => $officerId,
                'next_follow_up_on' => $case->next_follow_up_on,
                'metadata' => ['system_event' => 'reopen'],
                'recorded_at' => now(),
            ]);

            $this->audit->record(
                actor: $actor,
                action: 'care_case.reopened',
                category: AuditEvent::CATEGORY_BUSINESS,
                module: 'care',
                branchId: $case->branch_id,
                subjectType: CareCase::class,
                subjectId: $case->id,
                after: [
                    'reason' => $validated['reason'],
                    'assigned_officer_id' => $officerId,
                ],
            );

            return $case->fresh([
                'branch:id,name',
                'assignedOfficer:id,name',
                'activities.actor:id,name',
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function formatActivity(CareCaseActivity $activity, bool $includeRestricted): array
    {
        return [
            'id' => $activity->id,
            'activity_type' => $activity->activity_type,
            'outcome' => $activity->outcome,
            'notes' => $activity->notes,
            'restricted_note' => $includeRestricted ? $activity->restricted_note : null,
            'next_follow_up_on' => $activity->next_follow_up_on?->toDateString(),
            'actor' => $activity->relationLoaded('actor') ? $activity->actor : null,
            'responsible_officer' => $activity->relationLoaded('responsibleOfficer') ? $activity->responsibleOfficer : null,
            'recorded_at' => $activity->recorded_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formatEscalation(CareCaseEscalation $escalation): array
    {
        return [
            'id' => $escalation->id,
            'trigger_type' => $escalation->trigger_type,
            'from_officer_id' => $escalation->from_officer_id,
            'to_officer_id' => $escalation->to_officer_id,
            'to_officer' => $escalation->relationLoaded('toOfficer') ? $escalation->toOfficer : null,
            'from_officer' => $escalation->relationLoaded('fromOfficer') ? $escalation->fromOfficer : null,
            'acknowledged_at' => $escalation->acknowledged_at?->toIso8601String(),
            'acknowledged_by' => $escalation->acknowledged_by,
            'notes' => $escalation->notes,
            'created_at' => $escalation->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formatForActor(CareCase $case, User $actor): array
    {
        $canSensitive = $this->canReadSensitive($actor, $case);
        $categories = config('care_cases.categories', []);
        $confidentiality = config('care_cases.confidentiality_levels', []);
        $consent = config('care_cases.consent_bases', []);

        return [
            'id' => $case->id,
            'case_number' => $case->case_number,
            'branch_id' => $case->branch_id,
            'branch' => $case->relationLoaded('branch') ? $case->branch : null,
            'beneficiary_member_id' => $canSensitive ? $case->beneficiary_member_id : null,
            'beneficiary' => $canSensitive && $case->relationLoaded('beneficiary') && $case->beneficiary
                ? [
                    'id' => $case->beneficiary->id,
                    'membership_id' => $case->beneficiary->membership_id,
                    'name' => $case->beneficiary->fullName(),
                ]
                : null,
            'category' => $case->category,
            'category_label' => $categories[$case->category] ?? $case->category,
            'description' => $canSensitive ? $case->description : null,
            'sensitive_notes' => $canSensitive ? $case->sensitive_notes : null,
            'priority' => $case->priority,
            'status' => $case->status,
            'consent_basis' => $case->consent_basis,
            'consent_basis_label' => $consent[$case->consent_basis] ?? $case->consent_basis,
            'confidentiality' => $case->confidentiality,
            'confidentiality_label' => $confidentiality[$case->confidentiality] ?? $case->confidentiality,
            'data_classification' => $case->data_classification,
            'is_restricted' => $case->is_restricted,
            'evidence' => $canSensitive ? ($case->evidence ?? []) : [],
            'assigned_care_role' => $case->assigned_care_role,
            'assigned_officer_id' => $case->assigned_officer_id,
            'assigned_officer' => $case->relationLoaded('assignedOfficer') ? $case->assignedOfficer : null,
            'assigned_at' => $case->assigned_at?->toIso8601String(),
            'next_follow_up_on' => $case->next_follow_up_on?->toDateString(),
            'closed_at' => $case->closed_at?->toIso8601String(),
            'closure_reason' => $case->closure_reason,
            'closure_outcome' => $canSensitive ? $case->closure_outcome : null,
            'future_care_plan' => $canSensitive ? $case->future_care_plan : null,
            'reopened_at' => $case->reopened_at?->toIso8601String(),
            'reopen_reason' => $case->reopen_reason,
            'escalated_at' => $case->escalated_at?->toIso8601String(),
            'created_by' => $case->created_by,
            'created_at' => $case->created_at?->toIso8601String(),
            'restricted_details_omitted' => ! $canSensitive,
            'activities' => $case->relationLoaded('activities')
                ? $case->activities->map(fn (CareCaseActivity $activity) => $this->formatActivity($activity, $canSensitive))->values()->all()
                : [],
            'escalations' => $case->relationLoaded('escalations')
                ? $case->escalations->map(fn (CareCaseEscalation $escalation) => $this->formatEscalation($escalation))->values()->all()
                : [],
        ];
    }

    private function performEscalation(
        User $actor,
        CareCase $case,
        User $target,
        string $trigger,
        ?string $notes,
    ): CareCaseEscalation {
        return DB::transaction(function () use ($actor, $case, $target, $trigger, $notes): CareCaseEscalation {
            $fromOfficerId = $case->assigned_officer_id;

            $escalation = CareCaseEscalation::create([
                'care_case_id' => $case->id,
                'trigger_type' => $trigger,
                'from_officer_id' => $fromOfficerId,
                'to_officer_id' => $target->id,
                'escalated_by' => $actor->id,
                'notes' => $notes,
                'created_at' => now(),
            ]);

            $case->update([
                'status' => CareCase::STATUS_ESCALATED,
                'assigned_officer_id' => $target->id,
                'assigned_at' => now(),
                'escalated_at' => now(),
                'priority' => in_array($case->priority, ['urgent', 'high'], true) ? $case->priority : 'high',
                'updated_by' => $actor->id,
            ]);

            CareCaseActivity::create([
                'care_case_id' => $case->id,
                'activity_type' => 'note',
                'outcome' => 'unresolved',
                'notes' => 'Case escalated (' . $trigger . ').',
                'actor_id' => $actor->id,
                'responsible_officer_id' => $target->id,
                'metadata' => [
                    'escalation_id' => $escalation->id,
                    'trigger_type' => $trigger,
                    'system_event' => 'escalation',
                ],
                'recorded_at' => now(),
            ]);

            $this->audit->record(
                actor: $actor,
                action: 'care_case.escalated',
                category: AuditEvent::CATEGORY_SECURITY,
                module: 'care',
                branchId: $case->branch_id,
                subjectType: CareCase::class,
                subjectId: $case->id,
                before: ['assigned_officer_id' => $fromOfficerId],
                after: [
                    'assigned_officer_id' => $target->id,
                    'trigger_type' => $trigger,
                    'escalation_id' => $escalation->id,
                ],
            );

            $this->notifyAssignee($case->fresh(), $target, 'care.case.escalated', 'A restricted care case was escalated to you. Open the care workspace to review details.');

            return $escalation->fresh(['toOfficer:id,name', 'fromOfficer:id,name']);
        });
    }

    private function resolveEscalationTrigger(CareCase $case): ?string
    {
        if (in_array($case->priority, ['urgent'], true) && $case->status !== CareCase::STATUS_ESCALATED) {
            return 'urgency';
        }

        if ($case->confidentiality === 'safeguarding' && $case->status !== CareCase::STATUS_ESCALATED) {
            return 'safeguarding_concern';
        }

        if ($case->next_follow_up_on !== null
            && $case->next_follow_up_on->lt(now()->startOfDay()->subDays((int) config('care_cases.missed_deadline_grace_days', 0)))
            && $case->status !== CareCase::STATUS_ESCALATED) {
            return 'missed_deadline';
        }

        return null;
    }

    private function resolveEscalationTarget(CareCase $case): ?User
    {
        $permission = (string) config('care_cases.escalation_permission', 'care.cases.escalate');

        return User::query()
            ->where('id', '!=', $case->assigned_officer_id)
            ->where(function (Builder $query) use ($case): void {
                $query->where('branch_id', $case->branch_id)->orWhereNull('branch_id');
            })
            ->whereHas('assignedRoles.permissions', fn (Builder $q) => $q->where('action', $permission))
            ->orderByRaw('branch_id is null')
            ->first();
    }

    private function assertEligibleEscalationTarget(User $officer, int $branchId): void
    {
        $permission = (string) config('care_cases.escalation_permission', 'care.cases.escalate');

        if (! $this->authorization->allows($officer, $permission)
            && ! $this->authorization->allows($officer, 'care.cases.manage')) {
            throw ValidationException::withMessages([
                'to_officer_id' => ['Selected user is not qualified to receive care escalations.'],
            ]);
        }

        if ($officer->branch_id !== null && (int) $officer->branch_id !== $branchId && ! $officer->isChurchWide()) {
            try {
                BranchScope::for($officer)->assertIncludes($branchId);
            } catch (BranchScopeException) {
                throw ValidationException::withMessages([
                    'to_officer_id' => ['Selected escalation officer is outside the case branch scope.'],
                ]);
            }
        }
    }

    private function assertCanWork(User $actor, CareCase $case): void
    {
        if (! $this->authorization->allows($actor, 'care.cases.manage')
            && (int) $case->assigned_officer_id !== (int) $actor->id) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }

        if (! $this->canDiscover($actor, $case)) {
            $this->auditAccessDenied($actor, $case, 'record_activity');

            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function assertCanEscalate(User $actor, CareCase $case): void
    {
        if (! $this->authorization->allows($actor, 'care.cases.escalate')
            && ! $this->authorization->allows($actor, 'care.cases.manage')
            && (int) $case->assigned_officer_id !== (int) $actor->id) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }

        if (! $this->isInBranchScope($actor, (int) $case->branch_id)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function assertCanClose(User $actor, CareCase $case): void
    {
        if (! $this->authorization->allows($actor, 'care.cases.close')
            && ! $this->authorization->allows($actor, 'care.cases.manage')
            && (int) $case->assigned_officer_id !== (int) $actor->id) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }

        if (! $this->canDiscover($actor, $case)) {
            $this->auditAccessDenied($actor, $case, 'close');

            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function assertCanReopen(User $actor, CareCase $case): void
    {
        if (! $this->authorization->allows($actor, 'care.cases.reopen')) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }

        if (! $this->isInBranchScope($actor, (int) $case->branch_id)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function notifyAssignee(
        CareCase $case,
        User $assignee,
        string $type = 'care.case.assigned',
        string $message = 'A restricted care case was assigned to you. Open the care workspace to review details.',
    ): void {
        $member = Member::query()->where('user_id', $assignee->id)->first();
        if ($member === null) {
            return;
        }

        MemberNotification::create([
            'member_id' => $member->id,
            'user_id' => $assignee->id,
            'type' => $type,
            'message' => $message,
            'metadata' => [
                'care_case_id' => $case->id,
                'case_number' => $case->case_number,
                'priority' => $case->priority,
                // Intentionally omit description / beneficiary identity.
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateCreatePayload(array $payload): array
    {
        return validator($payload, [
            'branch_id' => ['required', 'integer', 'exists:organizations,id'],
            'beneficiary_member_id' => ['required', 'integer', 'exists:members,id'],
            'category' => ['required', 'string', 'in:' . implode(',', array_keys(config('care_cases.categories', [])))],
            'description' => ['required', 'string', 'min:10', 'max:5000'],
            'sensitive_notes' => ['nullable', 'string', 'max:5000'],
            'priority' => ['required', 'string', 'in:' . implode(',', config('care_cases.priorities', []))],
            'consent_basis' => ['required', 'string', 'in:' . implode(',', array_keys(config('care_cases.consent_bases', [])))],
            'confidentiality' => ['required', 'string', 'in:' . implode(',', array_keys(config('care_cases.confidentiality_levels', [])))],
            'assigned_officer_id' => ['nullable', 'integer', 'exists:users,id'],
            'evidence' => ['nullable', 'array', 'max:' . (int) config('care_cases.evidence_constraints.max_items', 5)],
            'evidence.*.filename' => ['required_with:evidence', 'string', 'max:255'],
            'evidence.*.mime_type' => ['required_with:evidence', 'string', 'max:120'],
            'evidence.*.size_bytes' => ['required_with:evidence', 'integer', 'min:1'],
            'evidence.*.content_hash' => ['required_with:evidence', 'string', 'max:128'],
        ])->validate();
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function processEvidence(array $items): array
    {
        $constraints = config('care_cases.evidence_constraints', []);
        $processed = [];

        foreach ($items as $index => $item) {
            $filename = (string) ($item['filename'] ?? '');
            $mime = (string) ($item['mime_type'] ?? '');
            $size = (int) ($item['size_bytes'] ?? 0);
            $hash = (string) ($item['content_hash'] ?? '');

            if ($filename === '' || str_contains($filename, "\0") || str_contains($filename, '../')) {
                throw ValidationException::withMessages([
                    "evidence.{$index}" => ['Evidence filename is not safe.'],
                ]);
            }

            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (in_array($extension, $constraints['blocked_extensions'] ?? [], true)) {
                throw ValidationException::withMessages([
                    "evidence.{$index}" => ['This evidence file type is not permitted.'],
                ]);
            }

            if (! in_array($mime, $constraints['allowed_mime_types'] ?? [], true)) {
                throw ValidationException::withMessages([
                    "evidence.{$index}" => ['Use PDF, JPEG, PNG, or WEBP evidence only.'],
                ]);
            }

            if ($size > (int) ($constraints['max_size_bytes'] ?? 0)) {
                throw ValidationException::withMessages([
                    "evidence.{$index}" => ['Evidence file exceeds the maximum allowed size.'],
                ]);
            }

            $processed[] = [
                'document_id' => (string) Str::uuid(),
                'filename' => $filename,
                'mime_type' => $mime,
                'size_bytes' => $size,
                'content_hash' => $hash,
                'status' => 'accepted',
                'storage_path' => 'care/cases/' . $hash . '/' . basename($filename),
            ];
        }

        return $processed;
    }

    private function generateCaseNumber(): string
    {
        do {
            $number = 'CARE-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
        } while (CareCase::query()->where('case_number', $number)->exists());

        return $number;
    }

    private function resolveEligibleOfficer(int $branchId, string $confidentiality): ?User
    {
        $permission = (string) config('care_cases.assignee_permission', 'care.cases.manage');

        $query = User::query()
            ->whereHas('assignedRoles.permissions', fn (Builder $q) => $q->where('action', $permission));

        if ($confidentiality === 'pastor_only') {
            $pastor = (clone $query)
                ->whereHas('assignedRoles.permissions', fn (Builder $q) => $q->where('action', 'care.cases.sensitive.read'))
                ->where(function (Builder $q) use ($branchId): void {
                    $q->where('branch_id', $branchId)->orWhereNull('branch_id');
                })
                ->orderByRaw('branch_id is null')
                ->first();

            if ($pastor !== null) {
                return $pastor;
            }
        }

        return (clone $query)
            ->where(function (Builder $q) use ($branchId): void {
                $q->where('branch_id', $branchId)->orWhereNull('branch_id');
            })
            ->orderByRaw('branch_id is null')
            ->first();
    }

    private function assertEligibleCareOfficer(User $officer, int $branchId): void
    {
        $permission = (string) config('care_cases.assignee_permission', 'care.cases.manage');

        if (! $this->authorization->allows($officer, $permission)) {
            throw ValidationException::withMessages([
                'assigned_officer_id' => ['Selected user is not an eligible care officer.'],
            ]);
        }

        if ($officer->branch_id !== null && (int) $officer->branch_id !== $branchId && ! $officer->isChurchWide()) {
            try {
                BranchScope::for($officer)->assertIncludes($branchId);
            } catch (BranchScopeException) {
                throw ValidationException::withMessages([
                    'assigned_officer_id' => ['Selected care officer is outside the case branch scope.'],
                ]);
            }
        }
    }

    private function canDiscover(User $actor, CareCase $case): bool
    {
        if (! $this->authorization->allows($actor, 'care.cases.read')
            && ! $this->authorization->allows($actor, 'care.cases.create')
            && ! $this->authorization->allows($actor, 'care.cases.manage')) {
            return false;
        }

        if (! $this->isInBranchScope($actor, (int) $case->branch_id)) {
            return false;
        }

        if ($this->canReadSensitive($actor, $case)) {
            return true;
        }

        // Without sensitivity clearance, only assigned officer or creator may discover the shell.
        return (int) $case->assigned_officer_id === (int) $actor->id
            || (int) $case->created_by === (int) $actor->id;
    }

    private function canReadSensitive(User $actor, CareCase $case): bool
    {
        if (! $this->isInBranchScope($actor, (int) $case->branch_id)) {
            return false;
        }

        if ($this->authorization->allows($actor, 'care.cases.sensitive.read')) {
            if ($case->confidentiality === 'assigned_only') {
                return (int) $case->assigned_officer_id === (int) $actor->id
                    || (int) $case->created_by === (int) $actor->id;
            }

            return true;
        }

        return (int) $case->assigned_officer_id === (int) $actor->id;
    }

    private function applyVisibilityFilter(Builder $query, User $actor): void
    {
        if ($this->authorization->allows($actor, 'care.cases.sensitive.read')) {
            // Still honor assigned_only confidentiality.
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

    private function auditAccessDenied(User $actor, CareCase $case, string $context): void
    {
        $this->audit->record(
            actor: $actor,
            action: 'care_case.access_denied',
            category: AuditEvent::CATEGORY_SECURITY,
            module: 'care',
            branchId: $case->branch_id,
            subjectType: CareCase::class,
            subjectId: $case->id,
            after: [
                'case_number' => $case->case_number,
                'context' => $context,
                'reason' => 'missing_role_branch_or_sensitivity_clearance',
            ],
        );
    }

    private function assertCan(User $actor, string $action): void
    {
        if (! $this->authorization->allows($actor, $action)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function isInBranchScope(User $actor, int $branchId): bool
    {
        if ($actor->isChurchWide()) {
            return true;
        }

        try {
            BranchScope::for($actor)->assertIncludes($branchId);

            return true;
        } catch (BranchScopeException) {
            return false;
        }
    }

    private function assertBranchWritable(User $actor, int $branchId): void
    {
        if (! $this->isInBranchScope($actor, $branchId)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function assertMemberInScope(User $actor, Member $member): void
    {
        if (! $this->isInBranchScope($actor, (int) $member->branch_id)) {
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
