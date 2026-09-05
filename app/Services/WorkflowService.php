<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Models\WorkflowVersion;
use App\Models\WorkflowVersionTest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Story 9.2: design, validate, test, and publish reusable workflows.
 */
class WorkflowService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
    ) {
    }

    /**
     * @return Collection<int, Workflow>
     */
    public function list(User $actor): Collection
    {
        $this->assertCan($actor, 'workflows.read');

        $query = Workflow::query()
            ->with(['branch:id,name'])
            ->orderBy('name');

        $this->applyBranchScope($query, $actor);

        return $query->limit(100)->get();
    }

    public function show(User $actor, Workflow $workflow): Workflow
    {
        $this->assertCan($actor, 'workflows.read');
        $this->assertInScope($actor, $workflow);

        return $workflow->load([
            'branch:id,name',
            'versions' => fn ($q) => $q->orderByDesc('version'),
            'versions.tests' => fn ($q) => $q->orderByDesc('ran_at')->limit(20),
            'versions.publisher:id,name',
            'instances' => fn ($q) => $q->orderByDesc('id')->limit(50),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(User $actor, array $payload): Workflow
    {
        $this->assertCan($actor, 'workflows.manage');

        $validated = validator($payload, [
            'name' => ['required', 'string', 'min:3', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'branch_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'migration_policy' => ['nullable', 'string', 'in:' . implode(',', array_keys(config('workflows.migration_policies', [])))],
            'definition' => ['required', 'array'],
        ])->validate();

        if (! empty($validated['branch_id'])) {
            $this->assertBranchWritable($actor, (int) $validated['branch_id']);
        }

        $definition = $this->normalizeDefinition($validated['definition']);
        $validation = $this->validateDefinition($definition, $actor);
        if (! $validation['valid']) {
            throw new WorkflowException(
                'Workflow definition is invalid.',
                'invalid_definition',
                422,
                $validation,
            );
        }

        $policy = $validated['migration_policy'] ?? (string) config('workflows.default_migration_policy', Workflow::MIGRATION_KEEP_LOCKED);

        return DB::transaction(function () use ($actor, $validated, $definition, $validation, $policy): Workflow {
            $workflow = Workflow::create([
                'name' => $validated['name'],
                'slug' => Str::slug($validated['name']) . '-' . Str::lower(Str::random(4)),
                'description' => $validated['description'] ?? null,
                'branch_id' => $validated['branch_id'] ?? null,
                'status' => Workflow::STATUS_DRAFT,
                'current_version' => 0,
                'migration_policy' => $policy,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            WorkflowVersion::create([
                'workflow_id' => $workflow->id,
                'version' => 1,
                'status' => WorkflowVersion::STATUS_DRAFT,
                'definition' => $definition,
                'migration_policy' => $policy,
                'last_validation' => $validation,
                'created_by' => $actor->id,
            ]);

            $this->audit($actor, 'workflow.created', $workflow, [
                'version' => 1,
            ]);

            return $workflow->fresh(['versions']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateDraft(User $actor, Workflow $workflow, array $payload): Workflow
    {
        $this->assertCan($actor, 'workflows.manage');
        $this->assertInScope($actor, $workflow);

        $validated = validator($payload, [
            'name' => ['nullable', 'string', 'min:3', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'migration_policy' => ['nullable', 'string', 'in:' . implode(',', array_keys(config('workflows.migration_policies', [])))],
            'definition' => ['required', 'array'],
        ])->validate();

        $definition = $this->normalizeDefinition($validated['definition']);
        $validation = $this->validateDefinition($definition, $actor);
        if (! $validation['valid']) {
            throw new WorkflowException(
                'Workflow definition is invalid.',
                'invalid_definition',
                422,
                $validation,
            );
        }

        $draft = $this->draftVersion($workflow);
        $policy = $validated['migration_policy'] ?? $draft->migration_policy ?? $workflow->migration_policy;

        return DB::transaction(function () use ($actor, $workflow, $draft, $definition, $validation, $validated, $policy): Workflow {
            if ($draft->status === WorkflowVersion::STATUS_PUBLISHED) {
                $draft = WorkflowVersion::create([
                    'workflow_id' => $workflow->id,
                    'version' => $workflow->current_version + 1,
                    'status' => WorkflowVersion::STATUS_DRAFT,
                    'definition' => $definition,
                    'migration_policy' => $policy,
                    'last_validation' => $validation,
                    'created_by' => $actor->id,
                ]);
            } else {
                $draft->update([
                    'definition' => $definition,
                    'migration_policy' => $policy,
                    'last_validation' => $validation,
                ]);
            }

            $workflowUpdates = [
                'updated_by' => $actor->id,
                'migration_policy' => $policy,
            ];
            if (! empty($validated['name'])) {
                $workflowUpdates['name'] = $validated['name'];
            }
            if (array_key_exists('description', $validated)) {
                $workflowUpdates['description'] = $validated['description'];
            }
            $workflow->update($workflowUpdates);

            $this->audit($actor, 'workflow.draft_updated', $workflow, [
                'version' => $draft->version,
            ]);

            return $workflow->fresh(['versions']);
        });
    }

    /**
     * Graph-oriented visualization of the current draft (or latest) definition.
     *
     * @return array<string, mixed>
     */
    public function visualize(User $actor, Workflow $workflow): array
    {
        $this->assertCan($actor, 'workflows.read');
        $this->assertInScope($actor, $workflow);

        $draft = $this->draftVersion($workflow);
        $definition = $draft->definition ?? [];
        $validation = $this->validateDefinition($definition, $actor);

        return [
            'workflow_id' => $workflow->id,
            'version' => $draft->version,
            'status' => $draft->status,
            'nodes' => collect($definition['states'] ?? [])->map(fn (array $state) => [
                'id' => $state['key'],
                'label' => $state['label'] ?? $state['key'],
                'type' => $state['type'],
                'is_end' => ($state['type'] ?? '') === 'end'
                    || in_array($state['key'], $definition['end_states'] ?? [], true),
            ])->values()->all(),
            'edges' => collect($definition['transitions'] ?? [])->map(fn (array $transition) => [
                'from' => $transition['from'],
                'to' => $transition['to'],
                'action' => $transition['action'] ?? null,
                'on' => $transition['on'] ?? null,
            ])->values()->all(),
            'trigger' => $definition['trigger'] ?? null,
            'validation' => $validation,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validate(User $actor, Workflow $workflow): array
    {
        $this->assertCan($actor, 'workflows.manage');
        $this->assertInScope($actor, $workflow);

        $draft = $this->draftVersion($workflow);
        $validation = $this->validateDefinition($draft->definition ?? [], $actor);
        $draft->update(['last_validation' => $validation]);

        return [
            'workflow_id' => $workflow->id,
            'version' => $draft->version,
            'validation' => $validation,
        ];
    }

    /**
     * Dry-run the draft against sample payload; evidence is retained.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function test(User $actor, Workflow $workflow, array $payload): array
    {
        $this->assertCan($actor, 'workflows.manage');
        $this->assertInScope($actor, $workflow);

        $validated = validator($payload, [
            'sample' => ['required', 'array'],
        ])->validate();

        $draft = $this->draftVersion($workflow);
        $definition = $draft->definition ?? [];
        $validation = $this->validateDefinition($definition, $actor);

        $sample = $validated['sample'];
        $conditionResults = $this->evaluateConditions($definition['conditions'] ?? [], $sample);
        $startState = collect($definition['states'] ?? [])->firstWhere('type', 'start');
        $walk = $this->simulateWalk($definition, $sample);

        $passed = $validation['valid']
            && $conditionResults['passed']
            && ($walk['ok'] ?? false);

        $result = [
            'validation' => $validation,
            'conditions' => $conditionResults,
            'start_state' => $startState['key'] ?? null,
            'simulation' => $walk,
            'passed' => $passed,
        ];

        $test = WorkflowVersionTest::create([
            'workflow_version_id' => $draft->id,
            'sample_payload' => $sample,
            'result' => $result,
            'passed' => $passed,
            'ran_by' => $actor->id,
            'ran_at' => now(),
        ]);

        $this->audit($actor, 'workflow.tested', $workflow, [
            'version' => $draft->version,
            'test_id' => $test->id,
            'passed' => $passed,
        ]);

        return [
            'workflow_id' => $workflow->id,
            'version' => $draft->version,
            'test_id' => $test->id,
            'passed' => $passed,
            'result' => $result,
            'ran_at' => $test->ran_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function publish(User $actor, Workflow $workflow, array $payload = []): Workflow
    {
        $this->assertCan($actor, 'workflows.publish');
        $this->assertInScope($actor, $workflow);

        $validated = validator($payload, [
            'migration_policy' => ['nullable', 'string', 'in:' . implode(',', array_keys(config('workflows.migration_policies', [])))],
        ])->validate();

        $draft = $this->draftVersion($workflow);
        if ($draft->status === WorkflowVersion::STATUS_PUBLISHED) {
            throw new WorkflowException('There is no unpublished draft to publish.', 'nothing_to_publish', 422);
        }

        $definition = $draft->definition ?? [];
        $validation = $this->validateDefinition($definition, $actor);
        if (! $validation['valid']) {
            throw new WorkflowException(
                'Cannot publish an invalid workflow definition.',
                'invalid_definition',
                422,
                $validation,
            );
        }

        $policy = $validated['migration_policy']
            ?? $draft->migration_policy
            ?? $workflow->migration_policy
            ?? Workflow::MIGRATION_KEEP_LOCKED;

        return DB::transaction(function () use ($actor, $workflow, $draft, $validation, $policy): Workflow {
            $draft->update([
                'status' => WorkflowVersion::STATUS_PUBLISHED,
                'migration_policy' => $policy,
                'last_validation' => $validation,
                'published_at' => now(),
                'published_by' => $actor->id,
            ]);

            $previousVersion = $workflow->current_version;

            $workflow->update([
                'status' => Workflow::STATUS_PUBLISHED,
                'current_version' => $draft->version,
                'migration_policy' => $policy,
                'updated_by' => $actor->id,
            ]);

            $migrated = 0;
            if ($policy === Workflow::MIGRATION_MIGRATE_PENDING && $previousVersion > 0) {
                $migrated = $this->migrateOpenInstances($workflow, $draft, $previousVersion);
            }

            $this->audit($actor, 'workflow.published', $workflow, [
                'version' => $draft->version,
                'previous_version' => $previousVersion,
                'migration_policy' => $policy,
                'instances_migrated' => $migrated,
            ]);

            return $workflow->fresh([
                'versions' => fn ($q) => $q->orderByDesc('version'),
                'versions.tests',
                'instances',
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function format(Workflow $workflow): array
    {
        $draft = $workflow->relationLoaded('versions')
            ? $workflow->versions->sortByDesc('version')->first()
            : $this->draftVersion($workflow);

        return [
            'id' => $workflow->id,
            'name' => $workflow->name,
            'slug' => $workflow->slug,
            'description' => $workflow->description,
            'branch_id' => $workflow->branch_id,
            'branch' => $workflow->relationLoaded('branch') ? $workflow->branch : null,
            'status' => $workflow->status,
            'current_version' => $workflow->current_version,
            'draft_version' => $draft?->version,
            'draft_status' => $draft?->status,
            'migration_policy' => $workflow->migration_policy,
            'definition' => $draft?->definition,
            'last_validation' => $draft?->last_validation,
            'versions' => $workflow->relationLoaded('versions')
                ? $workflow->versions->map(fn (WorkflowVersion $version) => $this->formatVersion($version))->values()->all()
                : [],
            'instances' => $workflow->relationLoaded('instances')
                ? $workflow->instances->map(fn (WorkflowInstance $instance) => [
                    'id' => $instance->id,
                    'workflow_version_id' => $instance->workflow_version_id,
                    'workflow_version' => $instance->workflow_version,
                    'status' => $instance->status,
                    'current_state' => $instance->current_state,
                    'migrated_at' => $instance->migrated_at?->toIso8601String(),
                    'migrated_from_version' => $instance->migrated_from_version,
                ])->values()->all()
                : [],
            'created_at' => $workflow->created_at?->toIso8601String(),
            'updated_at' => $workflow->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formatVersion(WorkflowVersion $version): array
    {
        return [
            'id' => $version->id,
            'version' => $version->version,
            'status' => $version->status,
            'definition' => $version->definition,
            'migration_policy' => $version->migration_policy,
            'last_validation' => $version->last_validation,
            'published_at' => $version->published_at?->toIso8601String(),
            'published_by' => $version->relationLoaded('publisher') ? $version->publisher : null,
            'tests' => $version->relationLoaded('tests')
                ? $version->tests->map(fn (WorkflowVersionTest $test) => [
                    'id' => $test->id,
                    'passed' => $test->passed,
                    'sample_payload' => $test->sample_payload,
                    'result' => $test->result,
                    'ran_at' => $test->ran_at?->toIso8601String(),
                    'ran_by' => $test->ran_by,
                ])->values()->all()
                : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function normalizeDefinition(array $definition): array
    {
        return [
            'trigger' => $definition['trigger'] ?? ['type' => 'manual'],
            'conditions' => array_values($definition['conditions'] ?? []),
            'states' => array_values($definition['states'] ?? []),
            'transitions' => array_values($definition['transitions'] ?? []),
            'assignments' => array_values($definition['assignments'] ?? []),
            'approvals' => array_values($definition['approvals'] ?? []),
            'rejection' => $definition['rejection'] ?? null,
            'escalation' => $definition['escalation'] ?? null,
            'notifications' => array_values($definition['notifications'] ?? []),
            'deadlines' => array_values($definition['deadlines'] ?? []),
            'reminders' => array_values($definition['reminders'] ?? []),
            'end_states' => array_values($definition['end_states'] ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array{valid: bool, errors: array<int, array<string, mixed>>, warnings: array<int, string>}
     */
    private function validateDefinition(array $definition, User $actor): array
    {
        $errors = [];
        $warnings = [];

        $trigger = $definition['trigger'] ?? null;
        if (! is_array($trigger) || empty($trigger['type'])) {
            $errors[] = ['code' => 'missing_trigger', 'message' => 'A trigger is required.'];
        } elseif (! in_array($trigger['type'], config('workflows.trigger_types', []), true)) {
            $errors[] = ['code' => 'invalid_trigger', 'message' => 'Trigger type is not supported.'];
        } elseif ($trigger['type'] === 'event' && empty($trigger['event'])) {
            $errors[] = ['code' => 'missing_trigger_event', 'message' => 'Event triggers require an event name.'];
        }

        $states = $definition['states'] ?? [];
        if ($states === []) {
            $errors[] = ['code' => 'missing_states', 'message' => 'At least one state is required.'];
        }

        $stateKeys = [];
        $startCount = 0;
        $endKeys = [];
        foreach ($states as $index => $state) {
            if (! is_array($state) || empty($state['key']) || empty($state['type'])) {
                $errors[] = ['code' => 'invalid_state', 'message' => "State at index {$index} needs key and type."];

                continue;
            }

            if (! in_array($state['type'], config('workflows.state_types', []), true)) {
                $errors[] = ['code' => 'invalid_state_type', 'message' => "State {$state['key']} has an invalid type.", 'state' => $state['key']];
            }

            if (isset($stateKeys[$state['key']])) {
                $errors[] = ['code' => 'duplicate_state', 'message' => "Duplicate state key {$state['key']}."];
            }
            $stateKeys[$state['key']] = $state;

            if ($state['type'] === 'start') {
                $startCount++;
            }
            if ($state['type'] === 'end') {
                $endKeys[] = $state['key'];
            }
        }

        if ($startCount !== 1) {
            $errors[] = ['code' => 'start_state', 'message' => 'Exactly one start state is required.'];
        }

        $declaredEnds = $definition['end_states'] ?? [];
        if ($declaredEnds === [] && $endKeys === []) {
            $errors[] = ['code' => 'missing_end_states', 'message' => 'At least one end state is required.'];
        }
        foreach ($declaredEnds as $end) {
            if (! isset($stateKeys[$end])) {
                $errors[] = ['code' => 'unknown_end_state', 'message' => "End state {$end} is not defined.", 'state' => $end];
            }
        }
        $allEnds = array_values(array_unique(array_merge($endKeys, $declaredEnds)));

        $transitions = $definition['transitions'] ?? [];
        if ($transitions === []) {
            $errors[] = ['code' => 'missing_transitions', 'message' => 'At least one transition is required.'];
        }

        $adjacency = [];
        foreach ($transitions as $index => $transition) {
            if (! is_array($transition) || empty($transition['from']) || empty($transition['to'])) {
                $errors[] = ['code' => 'invalid_transition', 'message' => "Transition at index {$index} needs from and to."];

                continue;
            }
            if (! isset($stateKeys[$transition['from']]) || ! isset($stateKeys[$transition['to']])) {
                $errors[] = [
                    'code' => 'unknown_transition_state',
                    'message' => "Transition references unknown state ({$transition['from']} → {$transition['to']}).",
                ];
            }
            $adjacency[$transition['from']][] = $transition['to'];
        }

        // Reachability from start
        $start = collect($states)->firstWhere('type', 'start');
        if (is_array($start) && ! empty($start['key'])) {
            $reachable = $this->reachableKeys($start['key'], $adjacency);
            foreach (array_keys($stateKeys) as $key) {
                if (! isset($reachable[$key])) {
                    $errors[] = ['code' => 'unreachable_state', 'message' => "State {$key} is unreachable from start.", 'state' => $key];
                }
            }
            foreach ($allEnds as $end) {
                if (! isset($reachable[$end])) {
                    $errors[] = ['code' => 'unreachable_end', 'message' => "End state {$end} is unreachable.", 'state' => $end];
                }
            }
        }

        // Assignments / actors
        $assignments = $definition['assignments'] ?? [];
        $approvalStates = collect($states)->whereIn('type', ['approval', 'action', 'escalation'])->pluck('key')->all();
        $assignedStates = [];
        $allowedPermissions = config('workflows.assignable_permissions', []);

        foreach ($assignments as $index => $assignment) {
            if (! is_array($assignment) || empty($assignment['state']) || empty($assignment['permission'])) {
                $errors[] = ['code' => 'invalid_assignment', 'message' => "Assignment at index {$index} needs state and permission."];

                continue;
            }

            if (! isset($stateKeys[$assignment['state']])) {
                $errors[] = ['code' => 'assignment_unknown_state', 'message' => "Assignment references unknown state {$assignment['state']}."];
            }

            $permission = (string) $assignment['permission'];
            if (! in_array($permission, $allowedPermissions, true)) {
                $errors[] = [
                    'code' => 'privilege_escalation',
                    'message' => "Permission {$permission} is not an allowed workflow actor permission.",
                    'permission' => $permission,
                ];
            } elseif (! $this->authorization->allows($actor, $permission)
                && ! $this->authorization->allows($actor, 'workflows.publish')) {
                $errors[] = [
                    'code' => 'privilege_escalation',
                    'message' => "Cannot assign permission {$permission} without holding it or workflows.publish.",
                    'permission' => $permission,
                ];
            }

            $assignedStates[$assignment['state']] = true;
        }

        foreach ($approvalStates as $stateKey) {
            if (! isset($assignedStates[$stateKey])) {
                $errors[] = [
                    'code' => 'missing_actor',
                    'message' => "State {$stateKey} requires an assignment actor.",
                    'state' => $stateKey,
                ];
            }
        }

        // Escalation loop limits
        $escalation = $definition['escalation'] ?? null;
        if (is_array($escalation)) {
            $maxLoops = (int) ($escalation['max_loops'] ?? 0);
            $limit = (int) config('workflows.max_loop_limit', 10);
            if ($maxLoops < 1 || $maxLoops > $limit) {
                $errors[] = [
                    'code' => 'loop_without_limit',
                    'message' => "Escalation max_loops must be between 1 and {$limit}.",
                ];
            }
            if (! empty($escalation['to_permission'])
                && ! in_array($escalation['to_permission'], $allowedPermissions, true)) {
                $errors[] = [
                    'code' => 'privilege_escalation',
                    'message' => 'Escalation target permission is not allowed.',
                    'permission' => $escalation['to_permission'],
                ];
            }
        }

        // Detect cyclic subgraphs without an exit to an end state / without loop limit on escalation edges
        if ($this->hasUnboundedCycle($adjacency, $allEnds, $definition)) {
            $errors[] = [
                'code' => 'loop_without_limit',
                'message' => 'Workflow contains a cycle without a configured loop limit or end-state exit.',
            ];
        }

        // Rejection target
        if (! empty($definition['rejection']['target_state'])
            && ! isset($stateKeys[$definition['rejection']['target_state']])) {
            $errors[] = [
                'code' => 'invalid_rejection_target',
                'message' => 'Rejection target state is not defined.',
            ];
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  array<string, array<int, string>>  $adjacency
     * @return array<string, true>
     */
    private function reachableKeys(string $start, array $adjacency): array
    {
        $reachable = [$start => true];
        $queue = [$start];

        while ($queue !== []) {
            $current = array_shift($queue);
            foreach ($adjacency[$current] ?? [] as $next) {
                if (isset($reachable[$next])) {
                    continue;
                }
                $reachable[$next] = true;
                $queue[] = $next;
            }
        }

        return $reachable;
    }

    /**
     * @param  array<string, array<int, string>>  $adjacency
     * @param  array<int, string>  $endKeys
     * @param  array<string, mixed>  $definition
     */
    private function hasUnboundedCycle(array $adjacency, array $endKeys, array $definition): bool
    {
        $escalationMax = (int) (($definition['escalation']['max_loops'] ?? 0));
        if ($escalationMax >= 1) {
            return false;
        }

        // Tarjan-lite: if any node can reach itself and that component has no end exit, flag it.
        foreach (array_keys($adjacency) as $node) {
            $seen = [];
            $stack = [$node];
            while ($stack !== []) {
                $current = array_pop($stack);
                foreach ($adjacency[$current] ?? [] as $next) {
                    if ($next === $node) {
                        // Cycle back to node — check if any end is reachable from here
                        $fromHere = $this->reachableKeys($node, $adjacency);
                        $hitsEnd = false;
                        foreach ($endKeys as $end) {
                            if (isset($fromHere[$end])) {
                                $hitsEnd = true;
                                break;
                            }
                        }
                        if (! $hitsEnd) {
                            return true;
                        }
                    }
                    if (isset($seen[$next])) {
                        continue;
                    }
                    $seen[$next] = true;
                    $stack[] = $next;
                }
            }
        }

        return false;
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
                'actual' => $actual,
                'passed' => $ok,
            ];
            if (! $ok) {
                $passed = false;
            }
        }

        if ($conditions === []) {
            $passed = true;
        }

        return ['passed' => $passed, 'results' => $results];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $sample
     * @return array<string, mixed>
     */
    private function simulateWalk(array $definition, array $sample): array
    {
        $start = collect($definition['states'] ?? [])->firstWhere('type', 'start');
        if (! is_array($start)) {
            return ['ok' => false, 'path' => [], 'error' => 'No start state'];
        }

        $preferred = (string) ($sample['_next_action'] ?? 'approve');
        $path = [$start['key']];
        $current = $start['key'];
        $ends = $definition['end_states'] ?? collect($definition['states'] ?? [])
            ->where('type', 'end')
            ->pluck('key')
            ->all();
        $guard = 0;

        while ($guard++ < 50) {
            if (in_array($current, $ends, true)) {
                return ['ok' => true, 'path' => $path, 'ended' => true];
            }

            $candidates = collect($definition['transitions'] ?? [])
                ->filter(fn ($t) => ($t['from'] ?? null) === $current)
                ->values();

            if ($candidates->isEmpty()) {
                return ['ok' => false, 'path' => $path, 'error' => 'Dead end at ' . $current];
            }

            $chosen = $candidates->first(fn ($t) => ($t['on'] ?? $t['action'] ?? null) === $preferred)
                ?? $candidates->first();

            $current = $chosen['to'];
            $path[] = $current;
        }

        return ['ok' => false, 'path' => $path, 'error' => 'Simulation exceeded step limit'];
    }

    private function migrateOpenInstances(Workflow $workflow, WorkflowVersion $newVersion, int $fromVersion): int
    {
        $count = 0;
        $openStatuses = config('workflows.instance_open_statuses', []);

        WorkflowInstance::query()
            ->where('workflow_id', $workflow->id)
            ->whereIn('status', $openStatuses)
            ->where('workflow_version', $fromVersion)
            ->orderBy('id')
            ->each(function (WorkflowInstance $instance) use ($newVersion, $fromVersion, &$count): void {
                $start = collect($newVersion->definition['states'] ?? [])->firstWhere('type', 'start');
                $instance->update([
                    'workflow_version_id' => $newVersion->id,
                    'workflow_version' => $newVersion->version,
                    'current_state' => $start['key'] ?? $instance->current_state,
                    'migrated_at' => now(),
                    'migrated_from_version' => $fromVersion,
                ]);
                $count++;
            });

        return $count;
    }

    private function draftVersion(Workflow $workflow): WorkflowVersion
    {
        $latest = WorkflowVersion::query()
            ->where('workflow_id', $workflow->id)
            ->orderByDesc('version')
            ->first();

        if ($latest === null) {
            throw new WorkflowException('Workflow has no versions.', 'missing_version', 422);
        }

        return $latest;
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function audit(User $actor, string $action, Workflow $workflow, array $after = []): void
    {
        $this->audit->record(
            actor: $actor,
            action: $action,
            category: AuditEvent::CATEGORY_BUSINESS,
            module: 'workflows',
            branchId: $workflow->branch_id,
            subjectType: Workflow::class,
            subjectId: $workflow->id,
            after: array_merge([
                'name' => $workflow->name,
                'current_version' => $workflow->current_version,
            ], $after),
        );
    }

    private function assertInScope(User $actor, Workflow $workflow): void
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
