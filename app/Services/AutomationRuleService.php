<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\AutomationRule;
use App\Models\AutomationRuleEvaluation;
use App\Models\AutomationRuleExecution;
use App\Models\AutomationRuleSimulation;
use App\Models\AutomationRuleVersion;
use App\Models\Member;
use App\Models\MemberNotification;
use App\Models\OperationalTask;
use App\Models\User;
use App\Models\Workflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Story 9.4: configure, simulate, publish, and evaluate event-driven automation rules.
 */
class AutomationRuleService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
        private WorkflowExecutionService $workflowExecution,
    ) {
    }

    /**
     * @return Collection<int, AutomationRule>
     */
    public function list(User $actor): Collection
    {
        $this->assertCan($actor, 'automation.rules.read');

        $query = AutomationRule::query()->with(['branch:id,name'])->orderBy('name');
        $this->applyBranchScope($query, $actor);

        return $query->limit(100)->get();
    }

    public function show(User $actor, AutomationRule $rule): AutomationRule
    {
        $this->assertCan($actor, 'automation.rules.read');
        $this->assertInScope($actor, $rule);

        return $rule->load([
            'branch:id,name',
            'versions' => fn ($q) => $q->orderByDesc('version'),
            'versions.simulations' => fn ($q) => $q->orderByDesc('ran_at')->limit(10),
            'evaluations' => fn ($q) => $q->orderByDesc('evaluated_at')->limit(50),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(User $actor, array $payload): AutomationRule
    {
        $this->assertCan($actor, 'automation.rules.manage');

        $validated = $this->validateRulePayload($payload);
        if (! empty($validated['branch_id'])) {
            $this->assertBranchWritable($actor, (int) $validated['branch_id']);
        }

        $definition = $this->extractDefinition($validated);
        $validation = $this->validateDefinition($definition, $actor);
        if (! $validation['valid']) {
            throw new AutomationRuleException('Automation rule definition is invalid.', 'invalid_definition', 422, $validation);
        }

        return DB::transaction(function () use ($actor, $validated, $definition, $validation): AutomationRule {
            $rule = AutomationRule::create([
                'name' => $validated['name'],
                'slug' => Str::slug($validated['name']) . '-' . Str::lower(Str::random(4)),
                'description' => $validated['description'] ?? null,
                'branch_id' => $validated['branch_id'] ?? null,
                'status' => AutomationRule::STATUS_DRAFT,
                'current_version' => 0,
                'enabled' => true,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            AutomationRuleVersion::create(array_merge($definition, [
                'automation_rule_id' => $rule->id,
                'version' => 1,
                'status' => AutomationRuleVersion::STATUS_DRAFT,
                'last_validation' => $validation,
                'created_by' => $actor->id,
            ]));

            $this->audit($actor, 'automation_rule.created', $rule, ['version' => 1]);

            return $rule->fresh(['versions']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateDraft(User $actor, AutomationRule $rule, array $payload): AutomationRule
    {
        $this->assertCan($actor, 'automation.rules.manage');
        $this->assertInScope($actor, $rule);

        $validated = $this->validateRulePayload($payload, requireName: false);
        $definition = $this->extractDefinition($validated);
        $validation = $this->validateDefinition($definition, $actor);
        if (! $validation['valid']) {
            throw new AutomationRuleException('Automation rule definition is invalid.', 'invalid_definition', 422, $validation);
        }

        $draft = $this->draftVersion($rule);

        return DB::transaction(function () use ($actor, $rule, $draft, $definition, $validation, $validated): AutomationRule {
            if ($draft->status === AutomationRuleVersion::STATUS_PUBLISHED) {
                $draft = AutomationRuleVersion::create(array_merge($definition, [
                    'automation_rule_id' => $rule->id,
                    'version' => $rule->current_version + 1,
                    'status' => AutomationRuleVersion::STATUS_DRAFT,
                    'last_validation' => $validation,
                    'created_by' => $actor->id,
                ]));
            } else {
                $draft->update(array_merge($definition, [
                    'last_validation' => $validation,
                ]));
            }

            $updates = ['updated_by' => $actor->id];
            if (! empty($validated['name'])) {
                $updates['name'] = $validated['name'];
            }
            if (array_key_exists('description', $validated)) {
                $updates['description'] = $validated['description'];
            }
            $rule->update($updates);

            $this->audit($actor, 'automation_rule.draft_updated', $rule, ['version' => $draft->version]);

            return $rule->fresh(['versions']);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function validate(User $actor, AutomationRule $rule): array
    {
        $this->assertCan($actor, 'automation.rules.manage');
        $this->assertInScope($actor, $rule);

        $draft = $this->draftVersion($rule);
        $definition = $this->definitionFromVersion($draft);
        $validation = $this->validateDefinition($definition, $actor);
        $draft->update(['last_validation' => $validation]);

        return [
            'automation_rule_id' => $rule->id,
            'version' => $draft->version,
            'validation' => $validation,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function simulate(User $actor, AutomationRule $rule, array $payload): array
    {
        $this->assertCan($actor, 'automation.rules.manage');
        $this->assertInScope($actor, $rule);

        $validated = validator($payload, [
            'sample' => ['required', 'array'],
        ])->validate();

        $draft = $this->draftVersion($rule);
        $definition = $this->definitionFromVersion($draft);
        $validation = $this->validateDefinition($definition, $actor);
        $sample = $validated['sample'];
        $conditions = $this->evaluateConditions($definition['conditions'] ?? [], $sample);
        $wouldRun = $validation['valid'] && $conditions['passed'];

        $result = [
            'validation' => $validation,
            'conditions' => $conditions,
            'would_execute' => $wouldRun,
            'action_preview' => [
                'action_type' => $definition['action_type'],
                'action_params' => $definition['action_params'],
            ],
            'passed' => $wouldRun,
        ];

        $simulation = AutomationRuleSimulation::create([
            'automation_rule_version_id' => $draft->id,
            'sample_payload' => $this->sanitizePayload($sample),
            'result' => $result,
            'passed' => $wouldRun,
            'ran_by' => $actor->id,
            'ran_at' => now(),
        ]);

        $this->audit($actor, 'automation_rule.simulated', $rule, [
            'version' => $draft->version,
            'simulation_id' => $simulation->id,
            'passed' => $wouldRun,
        ]);

        return [
            'automation_rule_id' => $rule->id,
            'version' => $draft->version,
            'simulation_id' => $simulation->id,
            'passed' => $wouldRun,
            'result' => $result,
        ];
    }

    public function publish(User $actor, AutomationRule $rule): AutomationRule
    {
        $this->assertCan($actor, 'automation.rules.publish');
        $this->assertInScope($actor, $rule);

        $draft = $this->draftVersion($rule);
        if ($draft->status === AutomationRuleVersion::STATUS_PUBLISHED) {
            throw new AutomationRuleException('There is no unpublished draft to publish.', 'nothing_to_publish', 422);
        }

        $definition = $this->definitionFromVersion($draft);
        $validation = $this->validateDefinition($definition, $actor, forPublish: true, ruleId: $rule->id);
        if (! $validation['valid']) {
            throw new AutomationRuleException('Cannot publish an invalid automation rule.', 'invalid_definition', 422, $validation);
        }

        return DB::transaction(function () use ($actor, $rule, $draft, $validation): AutomationRule {
            if ($rule->current_version > 0) {
                AutomationRuleVersion::query()
                    ->where('automation_rule_id', $rule->id)
                    ->where('version', $rule->current_version)
                    ->where('status', AutomationRuleVersion::STATUS_PUBLISHED)
                    ->update(['status' => AutomationRuleVersion::STATUS_SUPERSEDED]);
            }

            $draft->update([
                'status' => AutomationRuleVersion::STATUS_PUBLISHED,
                'last_validation' => $validation,
                'published_at' => now(),
                'published_by' => $actor->id,
            ]);

            $rule->update([
                'status' => AutomationRule::STATUS_PUBLISHED,
                'current_version' => $draft->version,
                'enabled' => true,
                'updated_by' => $actor->id,
            ]);

            $this->audit($actor, 'automation_rule.published', $rule, [
                'version' => $draft->version,
            ]);

            return $rule->fresh(['versions']);
        });
    }

    public function setEnabled(User $actor, AutomationRule $rule, bool $enabled): AutomationRule
    {
        $this->assertCan($actor, 'automation.rules.manage');
        $this->assertInScope($actor, $rule);

        $rule->update([
            'enabled' => $enabled,
            'status' => $enabled
                ? ($rule->current_version > 0 ? AutomationRule::STATUS_PUBLISHED : AutomationRule::STATUS_DRAFT)
                : AutomationRule::STATUS_DISABLED,
            'updated_by' => $actor->id,
        ]);

        $this->audit($actor, $enabled ? 'automation_rule.enabled' : 'automation_rule.disabled', $rule);

        return $rule->fresh();
    }

    /**
     * Evaluate published rules for a domain event.
     *
     * @param  array<string, mixed>  $payload
     * @return array{evaluated: int, executed: int, skipped: int, failed: int, quarantined: int, results: array<int, array<string, mixed>>}
     */
    public function evaluateEvent(User $actor, string $eventType, array $payload): array
    {
        if (! $this->authorization->allows($actor, 'automation.rules.evaluate')
            && ! $this->authorization->allows($actor, 'automation.rules.manage')) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }

        $validated = validator([
            'event_type' => $eventType,
            'payload' => $payload,
        ], [
            'event_type' => ['required', 'string', 'in:' . implode(',', config('automation_rules.supported_events', []))],
            'payload' => ['required', 'array'],
            'payload.event_key' => ['nullable', 'string', 'max:191'],
            'payload.branch_id' => ['nullable', 'integer'],
            'payload.consent' => ['nullable', 'boolean'],
            'payload._emit_depth' => ['nullable', 'integer', 'min:0'],
        ])->validate();

        $eventKey = (string) ($payload['event_key'] ?? Str::uuid());
        $branchId = isset($payload['branch_id']) ? (int) $payload['branch_id'] : null;
        $emitDepth = (int) ($payload['_emit_depth'] ?? 0);
        $maxDepth = (int) config('automation_rules.max_emit_depth', 3);

        $counts = ['evaluated' => 0, 'executed' => 0, 'skipped' => 0, 'failed' => 0, 'quarantined' => 0, 'results' => []];

        if ($emitDepth > $maxDepth) {
            $this->recordEvaluation(null, null, $eventType, $eventKey, AutomationRuleEvaluation::OUTCOME_SKIPPED, 'emit_depth_exceeded', [
                'emit_depth' => $emitDepth,
            ], $branchId, $actor);
            $counts['skipped']++;
            $counts['results'][] = ['outcome' => 'skipped', 'reason' => 'emit_depth_exceeded'];

            return $counts;
        }

        $versions = AutomationRuleVersion::query()
            ->with('rule')
            ->where('status', AutomationRuleVersion::STATUS_PUBLISHED)
            ->where('event_type', $eventType)
            ->whereHas('rule', function (Builder $q) use ($actor): void {
                $q->where('enabled', true)
                    ->where('status', AutomationRule::STATUS_PUBLISHED);
                $this->applyBranchScope($q, $actor);
            })
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get();

        $fanOut = 0;
        $maxFanOut = (int) config('automation_rules.max_fan_out', 5);

        foreach ($versions as $version) {
            $counts['evaluated']++;
            $rule = $version->rule;

            if ($rule === null || ! $rule->enabled || $rule->status === AutomationRule::STATUS_DISABLED) {
                $this->recordSkip($rule, $version, $eventType, $eventKey, 'disabled', $branchId, $actor);
                $counts['skipped']++;
                $counts['results'][] = ['rule_id' => $rule?->id, 'outcome' => 'skipped', 'reason' => 'disabled'];

                continue;
            }

            if ($version->status === AutomationRuleVersion::STATUS_SUPERSEDED) {
                $this->recordSkip($rule, $version, $eventType, $eventKey, 'superseded', $branchId, $actor);
                $counts['skipped']++;
                $counts['results'][] = ['rule_id' => $rule->id, 'outcome' => 'skipped', 'reason' => 'superseded'];

                continue;
            }

            if (! $version->isEffectiveAt(now())) {
                $this->recordSkip($rule, $version, $eventType, $eventKey, 'outside_effective_period', $branchId, $actor);
                $counts['skipped']++;
                $counts['results'][] = ['rule_id' => $rule->id, 'outcome' => 'skipped', 'reason' => 'outside_effective_period'];

                continue;
            }

            if ($version->requires_consent && empty($payload['consent'])) {
                $this->recordSkip($rule, $version, $eventType, $eventKey, 'missing_consent', $branchId, $actor);
                $counts['skipped']++;
                $counts['results'][] = ['rule_id' => $rule->id, 'outcome' => 'skipped', 'reason' => 'missing_consent'];

                continue;
            }

            if ($branchId !== null && $rule->branch_id !== null && (int) $rule->branch_id !== $branchId) {
                $this->recordSkip($rule, $version, $eventType, $eventKey, 'out_of_scope', $branchId, $actor);
                $counts['skipped']++;
                $counts['results'][] = ['rule_id' => $rule->id, 'outcome' => 'skipped', 'reason' => 'out_of_scope'];

                continue;
            }

            $conditions = $this->evaluateConditions($version->conditions ?? [], $payload);
            if (! $conditions['passed']) {
                $this->recordSkip($rule, $version, $eventType, $eventKey, 'conditions_unmatched', $branchId, $actor, [
                    'conditions' => $conditions['results'],
                ]);
                $counts['skipped']++;
                $counts['results'][] = ['rule_id' => $rule->id, 'outcome' => 'skipped', 'reason' => 'conditions_unmatched'];

                continue;
            }

            if ($fanOut >= $maxFanOut) {
                $this->recordSkip($rule, $version, $eventType, $eventKey, 'fan_out_exceeded', $branchId, $actor);
                $counts['skipped']++;
                $counts['results'][] = ['rule_id' => $rule->id, 'outcome' => 'skipped', 'reason' => 'fan_out_exceeded'];

                continue;
            }

            // Idempotent once per rule + event_key
            if (AutomationRuleExecution::query()
                ->where('automation_rule_id', $rule->id)
                ->where('event_key', $eventKey)
                ->exists()) {
                $this->recordSkip($rule, $version, $eventType, $eventKey, 'already_executed', $branchId, $actor);
                $counts['skipped']++;
                $counts['results'][] = ['rule_id' => $rule->id, 'outcome' => 'skipped', 'reason' => 'already_executed'];

                if ($version->stop_behavior === 'stop_on_match') {
                    break;
                }

                continue;
            }

            $execution = $this->executeAction($actor, $rule, $version, $eventType, $eventKey, $payload, $branchId, $emitDepth);
            $fanOut++;
            $counts[$execution['count_key']]++;
            $counts['results'][] = $execution['result'];

            if ($version->stop_behavior === 'stop_on_match'
                || ($version->stop_behavior === 'stop_on_success' && $execution['count_key'] === 'executed')) {
                break;
            }
        }

        return $counts;
    }

    /**
     * Retry quarantined/failed evaluations that still have attempts remaining.
     *
     * @return array{retried: int, executed: int, quarantined: int, skipped: int}
     */
    public function processRetries(User $actor, ?int $branchId = null): array
    {
        if (! $this->authorization->allows($actor, 'automation.rules.evaluate')
            && ! $this->authorization->allows($actor, 'automation.rules.manage')) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }

        $counts = ['retried' => 0, 'executed' => 0, 'quarantined' => 0, 'skipped' => 0];
        $maxRetries = (int) config('automation_rules.max_retries', 3);

        $query = AutomationRuleEvaluation::query()
            ->whereIn('outcome', [AutomationRuleEvaluation::OUTCOME_FAILED, AutomationRuleEvaluation::OUTCOME_RETRIED])
            ->where('attempt', '<', $maxRetries)
            ->orderBy('id');

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        foreach ($query->limit(50)->get() as $evaluation) {
            $counts['retried']++;
            $rule = $evaluation->rule;
            $version = $evaluation->version;
            if ($rule === null || $version === null || ! $rule->enabled) {
                $counts['skipped']++;

                continue;
            }

            // Re-run using sanitized stored context keys only — payload not retained.
            $payload = [
                'event_key' => $evaluation->event_key,
                'branch_id' => $evaluation->branch_id,
                'consent' => true,
                '_retry_of' => $evaluation->id,
            ];

            $result = $this->executeAction(
                $actor,
                $rule,
                $version,
                $evaluation->event_type,
                $evaluation->event_key,
                $payload,
                $evaluation->branch_id,
                0,
                $evaluation->attempt + 1,
            );
            $counts[$result['count_key'] === 'executed' ? 'executed' : ($result['count_key'] === 'quarantined' ? 'quarantined' : 'skipped')]++;
        }

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    public function format(AutomationRule $rule): array
    {
        $draft = $rule->relationLoaded('versions')
            ? $rule->versions->sortByDesc('version')->first()
            : $this->draftVersion($rule);

        return [
            'id' => $rule->id,
            'name' => $rule->name,
            'slug' => $rule->slug,
            'description' => $rule->description,
            'branch_id' => $rule->branch_id,
            'branch' => $rule->relationLoaded('branch') ? $rule->branch : null,
            'status' => $rule->status,
            'enabled' => $rule->enabled,
            'current_version' => $rule->current_version,
            'draft_version' => $draft?->version,
            'draft_status' => $draft?->status,
            'event_type' => $draft?->event_type,
            'action_type' => $draft?->action_type,
            'action_params' => $draft?->action_params,
            'conditions' => $draft?->conditions,
            'priority' => $draft?->priority,
            'stop_behavior' => $draft?->stop_behavior,
            'failure_policy' => $draft?->failure_policy,
            'effective_from' => $draft?->effective_from?->toIso8601String(),
            'effective_to' => $draft?->effective_to?->toIso8601String(),
            'requires_consent' => $draft?->requires_consent,
            'last_validation' => $draft?->last_validation,
            'versions' => $rule->relationLoaded('versions')
                ? $rule->versions->map(fn (AutomationRuleVersion $version) => [
                    'id' => $version->id,
                    'version' => $version->version,
                    'status' => $version->status,
                    'event_type' => $version->event_type,
                    'action_type' => $version->action_type,
                    'priority' => $version->priority,
                    'published_at' => $version->published_at?->toIso8601String(),
                    'simulations' => $version->relationLoaded('simulations')
                        ? $version->simulations->map(fn (AutomationRuleSimulation $sim) => [
                            'id' => $sim->id,
                            'passed' => $sim->passed,
                            'ran_at' => $sim->ran_at?->toIso8601String(),
                            'result' => $sim->result,
                        ])->values()->all()
                        : [],
                ])->values()->all()
                : [],
            'evaluations' => $rule->relationLoaded('evaluations')
                ? $rule->evaluations->map(fn (AutomationRuleEvaluation $evaluation) => [
                    'id' => $evaluation->id,
                    'event_type' => $evaluation->event_type,
                    'event_key' => $evaluation->event_key,
                    'outcome' => $evaluation->outcome,
                    'skip_reason' => $evaluation->skip_reason,
                    'attempt' => $evaluation->attempt,
                    'action_type' => $evaluation->action_type,
                    'action_reference_type' => $evaluation->action_reference_type,
                    'action_reference_id' => $evaluation->action_reference_id,
                    'result' => $evaluation->result,
                    'evaluated_at' => $evaluation->evaluated_at?->toIso8601String(),
                ])->values()->all()
                : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{count_key: string, result: array<string, mixed>}
     */
    private function executeAction(
        User $actor,
        AutomationRule $rule,
        AutomationRuleVersion $version,
        string $eventType,
        string $eventKey,
        array $payload,
        ?int $branchId,
        int $emitDepth,
        int $attempt = 1,
    ): array {
        try {
            $actionResult = match ($version->action_type) {
                'create_task' => $this->actionCreateTask($actor, $version, $payload, $branchId),
                'send_notification' => $this->actionSendNotification($actor, $version, $payload),
                'start_workflow' => $this->actionStartWorkflow($actor, $version, $payload, $branchId),
                'emit_event' => $this->actionEmitEvent($actor, $version, $payload, $emitDepth),
                'log_only' => ['reference_type' => null, 'reference_id' => null, 'detail' => 'logged'],
                default => throw new AutomationRuleException('Unsupported action.', 'unsupported_action', 422),
            };

            AutomationRuleExecution::query()->updateOrCreate(
                [
                    'automation_rule_id' => $rule->id,
                    'event_key' => $eventKey,
                ],
                [
                    'automation_rule_version_id' => $version->id,
                    'action_type' => $version->action_type,
                    'status' => AutomationRuleExecution::STATUS_COMPLETED,
                    'executed_at' => now(),
                ],
            );

            $this->recordEvaluation(
                $rule,
                $version,
                $eventType,
                $eventKey,
                AutomationRuleEvaluation::OUTCOME_EXECUTED,
                null,
                [
                    'action_type' => $version->action_type,
                    'detail' => $actionResult['detail'] ?? null,
                ],
                $branchId,
                $actor,
                $attempt,
                $version->action_type,
                $actionResult['reference_type'] ?? null,
                $actionResult['reference_id'] ?? null,
            );

            $this->audit($actor, 'automation_rule.executed', $rule, [
                'version' => $version->version,
                'event_type' => $eventType,
                'event_key' => $eventKey,
                'action_type' => $version->action_type,
            ]);

            return [
                'count_key' => 'executed',
                'result' => [
                    'rule_id' => $rule->id,
                    'outcome' => 'executed',
                    'action_type' => $version->action_type,
                    'reference_id' => $actionResult['reference_id'] ?? null,
                ],
            ];
        } catch (\Throwable $exception) {
            return $this->handleFailure($actor, $rule, $version, $eventType, $eventKey, $branchId, $attempt, $exception);
        }
    }

    /**
     * @return array{count_key: string, result: array<string, mixed>}
     */
    private function handleFailure(
        User $actor,
        AutomationRule $rule,
        AutomationRuleVersion $version,
        string $eventType,
        string $eventKey,
        ?int $branchId,
        int $attempt,
        \Throwable $exception,
    ): array {
        $policy = $version->failure_policy ?: (string) config('automation_rules.default_failure_policy', 'retry');
        $maxRetries = (int) config('automation_rules.max_retries', 3);

        if ($policy === 'quarantine' || ($policy === 'retry' && $attempt >= $maxRetries) || $policy === 'skip') {
            $outcome = $policy === 'skip'
                ? AutomationRuleEvaluation::OUTCOME_FAILED
                : AutomationRuleEvaluation::OUTCOME_QUARANTINED;

            AutomationRuleExecution::query()->updateOrCreate(
                ['automation_rule_id' => $rule->id, 'event_key' => $eventKey],
                [
                    'automation_rule_version_id' => $version->id,
                    'action_type' => $version->action_type,
                    'status' => AutomationRuleExecution::STATUS_QUARANTINED,
                    'executed_at' => now(),
                ],
            );

            $this->recordEvaluation($rule, $version, $eventType, $eventKey, $outcome, 'execution_failed', [
                'error_class' => class_basename($exception),
                'message' => Str::limit($exception->getMessage(), 200),
                // Never persist full event payload.
            ], $branchId, $actor, $attempt, $version->action_type);

            return [
                'count_key' => $outcome === AutomationRuleEvaluation::OUTCOME_QUARANTINED ? 'quarantined' : 'failed',
                'result' => [
                    'rule_id' => $rule->id,
                    'outcome' => $outcome,
                    'attempt' => $attempt,
                ],
            ];
        }

        $this->recordEvaluation(
            $rule,
            $version,
            $eventType,
            $eventKey,
            AutomationRuleEvaluation::OUTCOME_RETRIED,
            'execution_failed',
            [
                'error_class' => class_basename($exception),
                'message' => Str::limit($exception->getMessage(), 200),
                'next_attempt' => $attempt + 1,
            ],
            $branchId,
            $actor,
            $attempt,
            $version->action_type,
        );

        return [
            'count_key' => 'failed',
            'result' => [
                'rule_id' => $rule->id,
                'outcome' => 'retried',
                'attempt' => $attempt,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{reference_type: ?string, reference_id: ?int, detail: string}
     */
    private function actionCreateTask(User $actor, AutomationRuleVersion $version, array $payload, ?int $branchId): array
    {
        $params = $version->action_params ?? [];
        $resolvedBranch = (int) ($params['branch_id'] ?? $branchId ?? $actor->branch_id ?? 0);
        if ($resolvedBranch < 1) {
            throw new AutomationRuleException('Task action requires branch_id.', 'missing_branch', 422);
        }

        $assigneeId = (int) ($params['assignee_id'] ?? $payload['assignee_id'] ?? $actor->id);
        $task = OperationalTask::create([
            'reference' => 'TASK-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6)),
            'branch_id' => $resolvedBranch,
            'department' => $params['department'] ?? 'operations',
            'title' => $params['title'] ?? 'Automation task',
            'description' => $params['description'] ?? ('Created by automation rule version ' . $version->version),
            'priority' => $params['priority'] ?? 'normal',
            'status' => OperationalTask::STATUS_OPEN,
            'assignee_id' => $assigneeId,
            'created_by' => $actor->id,
            'due_date' => now()->addDays((int) ($params['due_days'] ?? 3))->toDateString(),
            'source_type' => AutomationRule::class,
            'source_id' => $version->automation_rule_id,
        ]);

        return [
            'reference_type' => OperationalTask::class,
            'reference_id' => $task->id,
            'detail' => $task->reference,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{reference_type: ?string, reference_id: ?int, detail: string}
     */
    private function actionSendNotification(User $actor, AutomationRuleVersion $version, array $payload): array
    {
        $params = $version->action_params ?? [];
        $userId = (int) ($params['user_id'] ?? $payload['user_id'] ?? $actor->id);
        $user = User::query()->find($userId);
        if ($user === null) {
            throw new AutomationRuleException('Notification target user not found.', 'missing_user', 422);
        }

        $member = Member::query()->where('user_id', $user->id)->first();
        if ($member === null) {
            throw new AutomationRuleException('Notification target has no member profile.', 'missing_member', 422);
        }

        $notification = MemberNotification::create([
            'member_id' => $member->id,
            'user_id' => $user->id,
            'type' => 'automation.rule.notification',
            'message' => (string) ($params['message'] ?? 'Automation rule notification'),
            'metadata' => [
                'automation_rule_id' => $version->automation_rule_id,
                'version' => $version->version,
                // Omit event payload.
            ],
        ]);

        return [
            'reference_type' => MemberNotification::class,
            'reference_id' => $notification->id,
            'detail' => 'notification_sent',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{reference_type: ?string, reference_id: ?int, detail: string}
     */
    private function actionStartWorkflow(User $actor, AutomationRuleVersion $version, array $payload, ?int $branchId): array
    {
        $params = $version->action_params ?? [];
        $workflowId = (int) ($params['workflow_id'] ?? 0);
        $workflow = Workflow::query()->find($workflowId);
        if ($workflow === null) {
            throw new AutomationRuleException('Workflow not found for start action.', 'missing_workflow', 422);
        }

        $publishedDefinition = $workflow->versions()
            ->where('status', 'published')
            ->value('definition');
        $triggerEvent = $params['trigger_event']
            ?? (is_array($publishedDefinition) ? data_get($publishedDefinition, 'trigger.event') : null);

        $instance = $this->workflowExecution->start($actor, $workflow, [
            'trigger_type' => 'event',
            'trigger_event' => $triggerEvent,
            'branch_id' => $branchId ?? $workflow->branch_id ?? $actor->branch_id,
            'assignee_id' => $params['assignee_id'] ?? $payload['assignee_id'] ?? null,
            'idempotency_key' => 'auto:' . $version->automation_rule_id . ':' . ($payload['event_key'] ?? Str::uuid()),
            'context' => $params['context'] ?? ['amount' => $payload['amount'] ?? 0, 'source' => 'automation_rule'],
            'source_type' => AutomationRule::class,
            'source_id' => $version->automation_rule_id,
        ]);

        return [
            'reference_type' => $instance::class,
            'reference_id' => $instance->id,
            'detail' => $instance->reference,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{reference_type: ?string, reference_id: ?int, detail: string}
     */
    private function actionEmitEvent(User $actor, AutomationRuleVersion $version, array $payload, int $emitDepth): array
    {
        $params = $version->action_params ?? [];
        $event = (string) ($params['event'] ?? '');
        if ($event === '' || ! in_array($event, config('automation_rules.supported_events', []), true)) {
            throw new AutomationRuleException('emit_event target is not supported.', 'unsupported_event', 422);
        }

        $childPayload = array_merge($this->sanitizePayload($payload), [
            'event_key' => ($payload['event_key'] ?? Str::uuid()) . ':emit:' . $version->id,
            '_emit_depth' => $emitDepth + 1,
            'consent' => $payload['consent'] ?? true,
        ]);

        $child = $this->evaluateEvent($actor, $event, $childPayload);

        return [
            'reference_type' => null,
            'reference_id' => null,
            'detail' => 'emitted:' . $event . ':executed=' . ($child['executed'] ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array{valid: bool, errors: array<int, array<string, mixed>>, warnings: array<int, string>}
     */
    private function validateDefinition(array $definition, User $actor, bool $forPublish = false, ?int $ruleId = null): array
    {
        $errors = [];
        $warnings = [];

        $events = config('automation_rules.supported_events', []);
        $actions = config('automation_rules.supported_actions', []);

        if (! in_array($definition['event_type'] ?? null, $events, true)) {
            $errors[] = ['code' => 'unsupported_event', 'message' => 'Event type is not supported.'];
        }

        $actionType = $definition['action_type'] ?? null;
        if (! is_string($actionType) || ! isset($actions[$actionType])) {
            $errors[] = ['code' => 'unsupported_action', 'message' => 'Action type is not supported.'];
        } else {
            foreach ($actions[$actionType]['required_params'] ?? [] as $param) {
                if (! array_key_exists($param, $definition['action_params'] ?? [])) {
                    $errors[] = ['code' => 'missing_action_param', 'message' => "Action requires parameter {$param}.", 'param' => $param];
                }
            }
        }

        if (! in_array($definition['stop_behavior'] ?? null, array_keys(config('automation_rules.stop_behaviors', [])), true)) {
            $errors[] = ['code' => 'invalid_stop_behavior', 'message' => 'Stop behavior is invalid.'];
        }

        if (! in_array($definition['failure_policy'] ?? null, array_keys(config('automation_rules.failure_policies', [])), true)) {
            $errors[] = ['code' => 'invalid_failure_policy', 'message' => 'Failure policy is invalid.'];
        }

        if (($definition['effective_from'] ?? null) && ($definition['effective_to'] ?? null)) {
            if (strtotime((string) $definition['effective_from']) > strtotime((string) $definition['effective_to'])) {
                $errors[] = ['code' => 'invalid_effective_period', 'message' => 'effective_from must be before effective_to.'];
            }
        }

        if ($actionType === 'emit_event') {
            $target = $definition['action_params']['event'] ?? null;
            if ($target === ($definition['event_type'] ?? null)) {
                $errors[] = ['code' => 'circular_chain', 'message' => 'Rule cannot emit its own event type.'];
            } elseif ($forPublish && is_string($target)) {
                $cycle = $this->detectEmitCycle($definition['event_type'], $target, $ruleId);
                if ($cycle !== []) {
                    $errors[] = ['code' => 'circular_chain', 'message' => 'Emit chain forms a cycle: ' . implode(' → ', $cycle)];
                }
            }
        }

        if ($forPublish) {
            $conflicts = $this->findConflicts($definition, $ruleId);
            if ($conflicts !== []) {
                $errors[] = [
                    'code' => 'conflicting_rules',
                    'message' => 'Another published rule conflicts at the same event/priority without stop behavior.',
                    'conflicts' => $conflicts,
                ];
            }
        }

        $sameEventQuery = AutomationRuleVersion::query()
            ->where('status', AutomationRuleVersion::STATUS_PUBLISHED)
            ->where('event_type', $definition['event_type'] ?? '');
        if ($ruleId !== null) {
            $sameEventQuery->where('automation_rule_id', '!=', $ruleId);
        }
        $sameEventCount = $sameEventQuery->count();
        $maxFanOut = (int) config('automation_rules.max_fan_out', 5);
        if ($sameEventCount >= $maxFanOut) {
            $warnings[] = "Event already has {$sameEventCount} published rules; fan-out limit is {$maxFanOut}.";
            if ($forPublish) {
                $errors[] = [
                    'code' => 'excessive_fan_out',
                    'message' => "Publishing would exceed fan-out limit of {$maxFanOut} for this event.",
                ];
            }
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function detectEmitCycle(string $fromEvent, string $toEvent, ?int $excludeRuleId = null): array
    {
        $graph = [$fromEvent => [$toEvent]];

        $publishedEmits = AutomationRuleVersion::query()
            ->where('status', AutomationRuleVersion::STATUS_PUBLISHED)
            ->where('action_type', 'emit_event')
            ->when($excludeRuleId, fn ($q) => $q->where('automation_rule_id', '!=', $excludeRuleId))
            ->get(['event_type', 'action_params']);

        foreach ($publishedEmits as $version) {
            $target = $version->action_params['event'] ?? null;
            if (is_string($target)) {
                $graph[$version->event_type][] = $target;
            }
        }

        $path = [$fromEvent, $toEvent];
        $visited = [$fromEvent => true, $toEvent => true];
        $current = $toEvent;
        $guard = 0;

        while ($guard++ < 20) {
            $nexts = $graph[$current] ?? [];
            if ($nexts === []) {
                return [];
            }
            $next = $nexts[0];
            $path[] = $next;
            if ($next === $fromEvent) {
                return $path;
            }
            if (isset($visited[$next])) {
                return [];
            }
            $visited[$next] = true;
            $current = $next;
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<int, array<string, mixed>>
     */
    private function findConflicts(array $definition, ?int $excludeRuleId = null): array
    {
        $stop = $definition['stop_behavior'] ?? 'continue';
        if (in_array($stop, ['stop_on_match', 'stop_on_success'], true)) {
            return [];
        }

        $query = AutomationRuleVersion::query()
            ->with('rule:id,name,branch_id,enabled,status')
            ->where('status', AutomationRuleVersion::STATUS_PUBLISHED)
            ->where('event_type', $definition['event_type'])
            ->where('priority', (int) ($definition['priority'] ?? 50))
            ->whereIn('stop_behavior', ['continue'])
            ->when($excludeRuleId, fn ($q) => $q->where('automation_rule_id', '!=', $excludeRuleId));

        $conflicts = [];
        foreach ($query->get() as $version) {
            $rule = $version->rule;
            if ($rule === null || ! $rule->enabled) {
                continue;
            }
            $conflicts[] = [
                'rule_id' => $rule->id,
                'name' => $rule->name,
                'version' => $version->version,
                'action_type' => $version->action_type,
            ];
        }

        return $conflicts;
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
                'gt' => is_numeric($actual) && is_numeric($expected) && (float) $actual > (float) $expected,
                'gte' => is_numeric($actual) && is_numeric($expected) && (float) $actual >= (float) $expected,
                'lt' => is_numeric($actual) && is_numeric($expected) && (float) $actual < (float) $expected,
                'lte' => is_numeric($actual) && is_numeric($expected) && (float) $actual <= (float) $expected,
                'in' => is_array($expected) && in_array($actual, $expected, true),
                'exists' => $actual !== null && $actual !== '',
                default => false,
            };
            $results[] = [
                'field' => $field,
                'operator' => $operator,
                'expected' => $expected,
                'passed' => $ok,
                'actual' => $ok ? $actual : null,
            ];
            if (! $ok) {
                $passed = false;
            }
        }

        return ['passed' => $conditions === [] ? true : $passed, 'results' => $results];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateRulePayload(array $payload, bool $requireName = true): array
    {
        return validator($payload, [
            'name' => [$requireName ? 'required' : 'nullable', 'string', 'min:3', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'branch_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'event_type' => ['required', 'string', 'in:' . implode(',', config('automation_rules.supported_events', []))],
            'conditions' => ['nullable', 'array'],
            'action_type' => ['required', 'string', 'in:' . implode(',', array_keys(config('automation_rules.supported_actions', [])))],
            'action_params' => ['nullable', 'array'],
            'scope_type' => ['nullable', 'string', 'in:branch,church_wide'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:100'],
            'stop_behavior' => ['nullable', 'string', 'in:' . implode(',', array_keys(config('automation_rules.stop_behaviors', [])))],
            'failure_policy' => ['nullable', 'string', 'in:' . implode(',', array_keys(config('automation_rules.failure_policies', [])))],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date'],
            'requires_consent' => ['nullable', 'boolean'],
        ])->validate();
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function extractDefinition(array $validated): array
    {
        $priorityKey = $validated['priority'] ?? null;
        $priority = is_int($priorityKey)
            ? $priorityKey
            : (int) (config('automation_rules.priorities.normal', 50));

        return [
            'event_type' => $validated['event_type'],
            'conditions' => array_values($validated['conditions'] ?? []),
            'action_type' => $validated['action_type'],
            'action_params' => $validated['action_params'] ?? [],
            'scope_type' => $validated['scope_type'] ?? 'branch',
            'priority' => $priority,
            'stop_behavior' => $validated['stop_behavior'] ?? 'continue',
            'failure_policy' => $validated['failure_policy'] ?? (string) config('automation_rules.default_failure_policy', 'retry'),
            'effective_from' => $validated['effective_from'] ?? null,
            'effective_to' => $validated['effective_to'] ?? null,
            'requires_consent' => (bool) ($validated['requires_consent'] ?? false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function definitionFromVersion(AutomationRuleVersion $version): array
    {
        return [
            'event_type' => $version->event_type,
            'conditions' => $version->conditions ?? [],
            'action_type' => $version->action_type,
            'action_params' => $version->action_params ?? [],
            'scope_type' => $version->scope_type,
            'priority' => $version->priority,
            'stop_behavior' => $version->stop_behavior,
            'failure_policy' => $version->failure_policy,
            'effective_from' => $version->effective_from?->toDateTimeString(),
            'effective_to' => $version->effective_to?->toDateTimeString(),
            'requires_consent' => $version->requires_consent,
        ];
    }

    private function draftVersion(AutomationRule $rule): AutomationRuleVersion
    {
        $latest = AutomationRuleVersion::query()
            ->where('automation_rule_id', $rule->id)
            ->orderByDesc('version')
            ->first();

        if ($latest === null) {
            throw new AutomationRuleException('Automation rule has no versions.', 'missing_version', 422);
        }

        return $latest;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function recordSkip(
        ?AutomationRule $rule,
        ?AutomationRuleVersion $version,
        string $eventType,
        string $eventKey,
        string $reason,
        ?int $branchId,
        User $actor,
        array $result = [],
    ): void {
        $this->recordEvaluation(
            $rule,
            $version,
            $eventType,
            $eventKey,
            AutomationRuleEvaluation::OUTCOME_SKIPPED,
            $reason,
            $result,
            $branchId,
            $actor,
        );
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function recordEvaluation(
        ?AutomationRule $rule,
        ?AutomationRuleVersion $version,
        string $eventType,
        string $eventKey,
        string $outcome,
        ?string $skipReason,
        array $result,
        ?int $branchId,
        User $actor,
        int $attempt = 1,
        ?string $actionType = null,
        ?string $actionReferenceType = null,
        ?int $actionReferenceId = null,
    ): void {
        AutomationRuleEvaluation::create([
            'automation_rule_id' => $rule?->id,
            'automation_rule_version_id' => $version?->id,
            'event_type' => $eventType,
            'event_key' => $eventKey,
            'outcome' => $outcome,
            'skip_reason' => $skipReason,
            'attempt' => $attempt,
            'result' => $result,
            'action_type' => $actionType ?? $version?->action_type,
            'action_reference_type' => $actionReferenceType,
            'action_reference_id' => $actionReferenceId,
            'branch_id' => $branchId,
            'actor_id' => $actor->id,
            'evaluated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sanitizePayload(array $payload): array
    {
        $clean = $payload;
        foreach (['password', 'secret', 'token', 'ssn', 'national_id', 'request_body', 'description'] as $key) {
            if (array_key_exists($key, $clean)) {
                $clean[$key] = '[redacted]';
            }
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function audit(User $actor, string $action, AutomationRule $rule, array $after = []): void
    {
        $this->audit->record(
            actor: $actor,
            action: $action,
            category: AuditEvent::CATEGORY_BUSINESS,
            module: 'automation',
            branchId: $rule->branch_id,
            subjectType: AutomationRule::class,
            subjectId: $rule->id,
            after: array_merge([
                'name' => $rule->name,
                'current_version' => $rule->current_version,
            ], $after),
        );
    }

    private function assertInScope(User $actor, AutomationRule $rule): void
    {
        if ($rule->branch_id === null) {
            return;
        }

        if (! $this->isInBranchScope($actor, (int) $rule->branch_id)) {
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
            $query->where(function (Builder $inner) use ($scope): void {
                $inner->whereNull('branch_id')
                    ->orWhereIn('branch_id', $scope->subtreeIds((int) $scope->branchId()));
            });
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
}
