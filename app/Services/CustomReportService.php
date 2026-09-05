<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\AuditEvent;
use App\Models\ChurchGroupMembership;
use App\Models\CustomReport;
use App\Models\CustomReportPreview;
use App\Models\CustomReportVersion;
use App\Models\Member;
use App\Models\Organization;
use App\Models\ServiceTeamAssignment;
use App\Models\User;
use App\Models\Visitor;
use App\Models\WelfareRequest;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Story 13.3: design, validate, version, preview, and run custom reports without code.
 */
class CustomReportService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function catalog(User $actor): array
    {
        $this->assertCan($actor, 'reports.custom.read');

        $sources = [];
        foreach (config('custom_reports.data_sources', []) as $key => $meta) {
            if (! $this->allows($actor, $meta['permission'] ?? '')) {
                continue;
            }

            $fields = [];
            foreach ($meta['fields'] ?? [] as $fieldKey => $fieldMeta) {
                if (! $this->canUseField($actor, $fieldMeta)) {
                    continue;
                }
                $fields[$fieldKey] = array_merge($fieldMeta, ['key' => $fieldKey]);
            }

            $sources[$key] = array_merge($meta, [
                'key' => $key,
                'fields' => $fields,
            ]);
        }

        return [
            'data_sources' => $sources,
            'filters' => config('custom_reports.filters', []),
            'calculations' => config('custom_reports.calculations', []),
            'limits' => [
                'max_fields' => config('custom_reports.max_fields', 12),
                'max_group_by' => config('custom_reports.max_group_by', 3),
                'max_sorts' => config('custom_reports.max_sorts', 3),
                'max_row_limit' => config('custom_reports.max_row_limit', 500),
            ],
            'join_rules' => config('custom_reports.join_rules', []),
        ];
    }

    /**
     * @return Collection<int, CustomReport>
     */
    public function list(User $actor): Collection
    {
        $this->assertCan($actor, 'reports.custom.read');

        $query = CustomReport::query()->with('branch:id,name')->orderBy('name');
        $this->applyReportBranchScope($query, $actor);

        return $query->limit(100)->get();
    }

    public function show(User $actor, CustomReport $report): CustomReport
    {
        $this->assertCan($actor, 'reports.custom.read');
        $this->assertInScope($actor, $report);

        return $report->load([
            'branch:id,name',
            'versions' => fn ($q) => $q->orderByDesc('version'),
            'versions.previews' => fn ($q) => $q->orderByDesc('ran_at')->limit(3),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(User $actor, array $payload): CustomReport
    {
        $this->assertCan($actor, 'reports.custom.manage');

        $validated = $this->validateReportPayload($payload);
        if (! empty($validated['branch_id'])) {
            BranchScope::for($actor)->assertIncludes((int) $validated['branch_id']);
        }

        $definition = $validated['definition'] ?? [];
        $validation = $this->validateDefinition($actor, $definition);

        return DB::transaction(function () use ($actor, $validated, $definition, $validation): CustomReport {
            $report = CustomReport::create([
                'name' => $validated['name'],
                'slug' => Str::slug($validated['name']) . '-' . Str::lower(Str::random(4)),
                'description' => $validated['description'] ?? null,
                'branch_id' => $validated['branch_id'] ?? null,
                'status' => CustomReport::STATUS_DRAFT,
                'current_version' => 0,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            CustomReportVersion::create([
                'custom_report_id' => $report->id,
                'version' => 1,
                'status' => CustomReportVersion::STATUS_DRAFT,
                'definition' => $definition,
                'last_validation' => $validation,
                'warnings' => $validation['warnings'] ?? [],
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->audit($actor, 'custom_report.created', $report, ['version' => 1]);

            return $report->fresh(['versions']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateDraft(User $actor, CustomReport $report, array $payload): CustomReport
    {
        $this->assertCan($actor, 'reports.custom.manage');
        $this->assertInScope($actor, $report);

        $validated = $this->validateReportPayload($payload, partial: true);
        $draft = $this->draftVersion($report);
        $definition = $validated['definition'] ?? $draft->definition ?? [];
        $validation = $this->validateDefinition($actor, $definition);

        return DB::transaction(function () use ($actor, $report, $draft, $validated, $definition, $validation): CustomReport {
            if ($draft->status === CustomReportVersion::STATUS_PUBLISHED) {
                $draft = CustomReportVersion::create([
                    'custom_report_id' => $report->id,
                    'version' => $report->current_version + 1,
                    'status' => CustomReportVersion::STATUS_DRAFT,
                    'definition' => $definition,
                    'last_validation' => $validation,
                    'warnings' => $validation['warnings'] ?? [],
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);
            } else {
                $draft->update([
                    'definition' => $definition,
                    'last_validation' => $validation,
                    'warnings' => $validation['warnings'] ?? [],
                    'updated_by' => $actor->id,
                ]);
            }

            $report->update([
                'name' => $validated['name'] ?? $report->name,
                'description' => $validated['description'] ?? $report->description,
                'branch_id' => array_key_exists('branch_id', $validated) ? $validated['branch_id'] : $report->branch_id,
                'updated_by' => $actor->id,
            ]);

            $this->audit($actor, 'custom_report.draft_updated', $report, ['version' => $draft->version]);

            return $report->fresh(['versions']);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function validateReportDefinition(User $actor, CustomReport $report): array
    {
        $this->assertCan($actor, 'reports.custom.manage');
        $this->assertInScope($actor, $report);

        $draft = $this->draftVersion($report);
        $validation = $this->validateDefinition($actor, $draft->definition ?? []);
        $draft->update(['last_validation' => $validation, 'warnings' => $validation['warnings'] ?? []]);

        return $validation;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function preview(User $actor, CustomReport $report, array $payload = []): array
    {
        $this->assertCan($actor, 'reports.custom.manage');
        $this->assertInScope($actor, $report);

        $draft = $this->draftVersion($report);
        $validation = $this->validateDefinition($actor, $draft->definition ?? [], runtime: true);
        if (! ($validation['valid'] ?? false)) {
            throw new CustomReportException(
                'Report definition has validation errors.',
                'invalid_definition',
                422,
                $validation,
            );
        }

        $result = $this->executeDefinition($actor, $draft->definition ?? [], $report->branch_id, $payload);

        $preview = CustomReportPreview::create([
            'custom_report_version_id' => $draft->id,
            'preview_payload' => $result,
            'ran_at' => now(),
            'ran_by' => $actor->id,
        ]);

        return [
            'preview_id' => $preview->id,
            'version' => $draft->version,
            'generated_at' => now()->toIso8601String(),
            'validation' => $validation,
            'result' => $result,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function publish(User $actor, CustomReport $report, array $payload = []): CustomReport
    {
        $this->assertCan($actor, 'reports.custom.publish');

        if ($report->status === CustomReport::STATUS_RETIRED) {
            throw new CustomReportException('Retired reports cannot be published.', 'retired', 422);
        }

        $draft = $this->draftVersion($report);
        if ($draft->status === CustomReportVersion::STATUS_PUBLISHED) {
            throw new CustomReportException('There is no unpublished draft to publish.', 'nothing_to_publish', 422);
        }

        $validation = $this->validateDefinition($actor, $draft->definition ?? [], runtime: true);
        if (! ($validation['valid'] ?? false)) {
            throw new CustomReportException(
                'Cannot publish a report with validation errors.',
                'invalid_definition',
                422,
                $validation,
            );
        }

        $effectiveFrom = ! empty($payload['effective_from'])
            ? Carbon::parse($payload['effective_from'])
            : now();

        return DB::transaction(function () use ($actor, $report, $draft, $validation, $effectiveFrom): CustomReport {
            if ($report->current_version > 0) {
                CustomReportVersion::query()
                    ->where('custom_report_id', $report->id)
                    ->where('version', $report->current_version)
                    ->update([
                        'status' => CustomReportVersion::STATUS_SUPERSEDED,
                        'effective_to' => $effectiveFrom,
                    ]);
            }

            $draft->update([
                'status' => CustomReportVersion::STATUS_PUBLISHED,
                'last_validation' => $validation,
                'warnings' => $validation['warnings'] ?? [],
                'published_at' => now(),
                'published_by' => $actor->id,
                'effective_from' => $effectiveFrom,
                'effective_to' => null,
            ]);

            $report->update([
                'status' => CustomReport::STATUS_PUBLISHED,
                'current_version' => $draft->version,
                'updated_by' => $actor->id,
            ]);

            $this->audit($actor, 'custom_report.published', $report, ['version' => $draft->version]);

            return $report->fresh(['versions']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function run(User $actor, CustomReport $report, array $payload = []): array
    {
        $this->assertCan($actor, 'reports.custom.read');
        $this->assertInScope($actor, $report);

        if ($report->status !== CustomReport::STATUS_PUBLISHED || $report->current_version < 1) {
            throw new CustomReportException('Report is not published.', 'not_published', 422);
        }

        $version = $this->publishedVersion($report);
        if ($version === null) {
            throw new CustomReportException('Published version not found.', 'missing_version', 422);
        }

        $validation = $this->validateDefinition($actor, $version->definition ?? [], runtime: true, runner: true);
        if (! ($validation['valid'] ?? false)) {
            throw new CustomReportException(
                'Current permissions do not allow running this report definition.',
                'permission_mismatch',
                403,
                $validation,
            );
        }

        $result = $this->executeDefinition($actor, $version->definition ?? [], $report->branch_id, $payload);

        $this->audit($actor, 'custom_report.run', $report, [
            'version' => $version->version,
            'row_count' => count($result['rows'] ?? []),
        ]);

        return array_merge($result, [
            'report_id' => $report->id,
            'report_name' => $report->name,
            'version' => $version->version,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    public function format(CustomReport $report): array
    {
        $draft = $report->relationLoaded('versions')
            ? $report->versions->sortByDesc('version')->first()
            : $this->draftVersion($report);

        return [
            'id' => $report->id,
            'name' => $report->name,
            'slug' => $report->slug,
            'description' => $report->description,
            'branch_id' => $report->branch_id,
            'branch_name' => $report->relationLoaded('branch') ? $report->branch?->name : null,
            'status' => $report->status,
            'current_version' => $report->current_version,
            'draft_version' => $draft?->version,
            'definition' => $draft?->definition ?? [],
            'last_validation' => $draft?->last_validation,
            'warnings' => $draft?->warnings ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function validateDefinition(User $actor, array $definition, bool $runtime = false, bool $runner = false): array
    {
        $errors = [];
        $warnings = [];

        $sourceKey = $definition['data_source'] ?? null;
        $source = config("custom_reports.data_sources.{$sourceKey}");
        if ($source === null) {
            $errors[] = ['field' => 'data_source', 'message' => 'Unknown or unsupported data source.'];

            return $this->validationResult($errors, $warnings);
        }

        if (! $this->allows($actor, $source['permission'] ?? '')) {
            $errors[] = ['field' => 'data_source', 'message' => 'You do not have permission to use this data source.'];
        }

        if (! empty($definition['joins'])) {
            $errors[] = ['field' => 'joins', 'message' => 'Unsafe or unsupported joins are not allowed.'];
        }

        $fields = $definition['fields'] ?? [];
        if ($fields === []) {
            $errors[] = ['field' => 'fields', 'message' => 'Select at least one field.'];
        }

        if (count($fields) > (int) config('custom_reports.max_fields', 12)) {
            $errors[] = ['field' => 'fields', 'message' => 'Too many selected fields.'];
        }

        $allowedFieldKeys = array_keys($source['fields'] ?? []);
        foreach ($fields as $index => $field) {
            if (! in_array($field, $allowedFieldKeys, true)) {
                $errors[] = ['field' => "fields.{$index}", 'message' => "Field {$field} is not available on this source."];

                continue;
            }

            $fieldMeta = $source['fields'][$field];
            if (! $this->canUseField($actor, $fieldMeta)) {
                $message = $runner
                    ? "Field {$field} is not permitted for your current access."
                    : "Field {$field} requires additional permission.";
                $errors[] = ['field' => "fields.{$index}", 'message' => $message];
            }
        }

        $groupBy = $definition['group_by'] ?? [];
        if (count($groupBy) > (int) config('custom_reports.max_group_by', 3)) {
            $errors[] = ['field' => 'group_by', 'message' => 'Too many group-by fields.'];
        }

        if ($groupBy !== []) {
            foreach ($fields as $index => $field) {
                if (! in_array($field, $groupBy, true)) {
                    $errors[] = ['field' => "fields.{$index}", 'message' => 'Selected fields must be included in group-by when aggregating.'];
                }
            }
        }

        foreach ($groupBy as $index => $field) {
            if (! in_array($field, $allowedFieldKeys, true)) {
                $errors[] = ['field' => "group_by.{$index}", 'message' => "Cannot group by {$field}."];
            }
        }

        $sorts = $definition['sort'] ?? [];
        if (count($sorts) > (int) config('custom_reports.max_sorts', 3)) {
            $errors[] = ['field' => 'sort', 'message' => 'Too many sort rules.'];
        }

        foreach ($sorts as $index => $sort) {
            $field = $sort['field'] ?? null;
            $direction = $sort['direction'] ?? 'asc';
            if (! in_array($field, array_merge($fields, $groupBy), true)) {
                $errors[] = ['field' => "sort.{$index}.field", 'message' => 'Sort field must be selected or grouped.'];
            }
            if (! in_array($direction, ['asc', 'desc'], true)) {
                $errors[] = ['field' => "sort.{$index}.direction", 'message' => 'Invalid sort direction.'];
            }
        }

        $calculations = $definition['calculations'] ?? [];
        foreach ($calculations as $index => $calc) {
            $type = $calc['type'] ?? null;
            $calcMeta = config("custom_reports.calculations.{$type}");
            if ($calcMeta === null) {
                $errors[] = ['field' => "calculations.{$index}.type", 'message' => 'Invalid calculation formula.'];

                continue;
            }

            $targetField = $calc['field'] ?? null;
            if (($calcMeta['numeric_only'] ?? false) && $targetField !== null) {
                $fieldMeta = $source['fields'][$targetField] ?? null;
                if ($fieldMeta === null || ($fieldMeta['type'] ?? '') !== 'numeric') {
                    $errors[] = ['field' => "calculations.{$index}.field", 'message' => 'Calculation requires a numeric field.'];
                }
            }

            if ($groupBy === [] && $type !== 'count') {
                $errors[] = ['field' => "calculations.{$index}.type", 'message' => 'Aggregations require group-by fields.'];
            }
        }

        $cost = $this->estimateCost($definition);
        if ($cost > (int) config('custom_reports.max_cost_score', 2500)) {
            $errors[] = ['field' => 'definition', 'message' => 'Report query cost exceeds the allowed threshold.'];
        }

        if ($runtime && $errors === [] && $groupBy === [] && ($definition['calculations'] ?? []) === []) {
            $warnings[] = ['field' => 'definition', 'message' => 'Detail reports are limited to 500 rows.'];
        }

        return $this->validationResult($errors, $warnings, $cost);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $runtimeParams
     * @return array<string, mixed>
     */
    private function executeDefinition(User $actor, array $definition, ?int $reportBranchId, array $runtimeParams = []): array
    {
        $sourceKey = $definition['data_source'];
        $source = config("custom_reports.data_sources.{$sourceKey}");
        /** @var class-string<Model> $modelClass */
        $modelClass = $source['model'];

        $query = $modelClass::query();
        $this->applySourceScope($query, $actor, $sourceKey, $reportBranchId);
        $this->applyDefinitionFilters($query, $actor, $definition, $sourceKey, $runtimeParams);

        $fields = $definition['fields'] ?? [];
        $groupBy = $definition['group_by'] ?? [];
        $calculations = $definition['calculations'] ?? [];
        $columns = array_values(array_unique(array_merge($fields, $groupBy)));

        if ($groupBy !== [] || $calculations !== []) {
            foreach ($groupBy as $field) {
                $query->addSelect($field);
                $query->groupBy($field);
            }

            foreach ($calculations as $calc) {
                $alias = $calc['alias'] ?? $calc['type'];
                $type = $calc['type'];
                $field = $calc['field'] ?? '*';
                $expression = match ($type) {
                    'count' => $field === '*' ? 'count(*)' : "count({$field})",
                    'sum' => "sum({$field})",
                    'avg' => "avg({$field})",
                    default => 'count(*)',
                };
                $query->selectRaw("{$expression} as {$alias}");
            }

            foreach ($definition['sort'] ?? [] as $sort) {
                $query->orderBy($sort['field'], $sort['direction'] ?? 'asc');
            }

            $rows = $query->limit((int) config('custom_reports.max_row_limit', 500))->get()
                ->map(fn ($row) => $this->formatRow($row, array_merge($columns, collect($calculations)->pluck('alias')->filter()->all())))
                ->values()
                ->all();
        } else {
            $query->select($columns);
            foreach ($definition['sort'] ?? [] as $sort) {
                $query->orderBy($sort['field'], $sort['direction'] ?? 'asc');
            }

            $rows = $query->limit((int) config('custom_reports.max_row_limit', 500))->get()
                ->map(fn ($row) => $this->formatRow($row, $columns))
                ->values()
                ->all();
        }

        return [
            'data_source' => $sourceKey,
            'columns' => $columns,
            'rows' => $rows,
            'row_count' => count($rows),
            'truncated' => count($rows) >= (int) config('custom_reports.max_row_limit', 500),
            'state' => $rows === [] ? 'empty' : 'ready',
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function applyDefinitionFilters(
        Builder $query,
        User $actor,
        array $definition,
        string $sourceKey,
        array $runtimeParams,
    ): void {
        foreach ($definition['filters'] ?? [] as $filter) {
            $type = $filter['type'] ?? null;

            match ($type) {
                'branch' => $this->applyBranchFilter($query, $actor, (int) ($filter['value'] ?? $runtimeParams['branch_id'] ?? 0)),
                'date' => $this->applyDateFilter($query, $filter, $sourceKey),
                'age' => $this->applyAgeFilter($query, $filter),
                'gender' => $query->where('gender', $filter['value'] ?? ''),
                'membership_stage' => $query->where('lifecycle_stage', $filter['value'] ?? ''),
                'membership_status' => $query->where('lifecycle_status', $filter['value'] ?? ''),
                'team' => $this->applyTeamFilter($query, (int) ($filter['value'] ?? 0)),
                'group' => $this->applyGroupFilter($query, (int) ($filter['value'] ?? 0)),
                'attendance_status' => $query->where('status', $filter['value'] ?? ''),
                'event' => $query->where('session_id', (int) ($filter['value'] ?? 0)),
                'welfare_type' => $query->where('request_type', $filter['value'] ?? ''),
                'location_city' => $query->where('city', $filter['value'] ?? ''),
                default => null,
            };
        }
    }

    private function applyBranchFilter(Builder $query, User $actor, int $branchId): void
    {
        if ($branchId <= 0) {
            return;
        }

        $branch = Organization::query()->findOrFail($branchId);
        BranchScope::for($actor)->assertIncludes($branch);
        $query->where('branch_id', $branchId);
    }

    /**
     * @param  array<string, mixed>  $filter
     */
    private function applyDateFilter(Builder $query, array $filter, string $sourceKey): void
    {
        $source = config("custom_reports.data_sources.{$sourceKey}");
        $field = $filter['field'] ?? ($source['default_date_field'] ?? 'created_at');
        $from = ! empty($filter['from']) ? Carbon::parse($filter['from'])->startOfDay() : null;
        $to = ! empty($filter['to']) ? Carbon::parse($filter['to'])->endOfDay() : null;

        if ($from !== null && $to !== null) {
            $query->whereBetween($field, [$from, $to]);
        } elseif ($from !== null) {
            $query->where($field, '>=', $from);
        } elseif ($to !== null) {
            $query->where($field, '<=', $to);
        }
    }

    /**
     * @param  array<string, mixed>  $filter
     */
    private function applyAgeFilter(Builder $query, array $filter): void
    {
        $min = isset($filter['min']) ? (int) $filter['min'] : null;
        $max = isset($filter['max']) ? (int) $filter['max'] : null;

        if ($min !== null) {
            $query->whereDate('date_of_birth', '<=', now()->subYears($min)->toDateString());
        }

        if ($max !== null) {
            $query->whereDate('date_of_birth', '>=', now()->subYears($max + 1)->toDateString());
        }
    }

    private function applyTeamFilter(Builder $query, int $teamId): void
    {
        if ($teamId <= 0) {
            return;
        }

        $memberIds = ServiceTeamAssignment::query()
            ->where('service_team_id', $teamId)
            ->where('status', ServiceTeamAssignment::STATUS_ACTIVE)
            ->pluck('member_id');

        $query->whereIn('id', $memberIds->isEmpty() ? [0] : $memberIds);
    }

    private function applyGroupFilter(Builder $query, int $groupId): void
    {
        if ($groupId <= 0) {
            return;
        }

        $memberIds = ChurchGroupMembership::query()
            ->where('church_group_id', $groupId)
            ->where('status', ChurchGroupMembership::STATUS_ACTIVE)
            ->pluck('member_id');

        $query->whereIn('id', $memberIds->isEmpty() ? [0] : $memberIds);
    }

    private function applySourceScope(Builder $query, User $actor, string $sourceKey, ?int $reportBranchId): void
    {
        if ($reportBranchId !== null) {
            BranchScope::for($actor)->assertIncludes($reportBranchId);
            $query->where('branch_id', $reportBranchId);

            return;
        }

        if (in_array($sourceKey, ['members'], true)) {
            $query->whereNull('merged_into_id');
        }

        if (in_array($sourceKey, ['attendance'], true)) {
            $query->where('service_cancelled', false);
        }

        $ids = $this->scopedBranchIds($actor);
        if ($ids !== null) {
            $query->whereIn('branch_id', $ids ?: [0]);
        }
    }

    /**
     * @return list<int>|null
     */
    private function scopedBranchIds(User $actor): ?array
    {
        if (BranchScope::for($actor)->isChurchWide()) {
            return null;
        }

        $query = Organization::query()
            ->where('type', 'branch')
            ->where('is_active', true);
        BranchScope::for($actor)->applyToQuery($query);

        return $query->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @param  array<string, mixed>  $fieldMeta
     */
    private function canUseField(User $actor, array $fieldMeta): bool
    {
        if (! ($fieldMeta['sensitive'] ?? false)) {
            return true;
        }

        $permission = $fieldMeta['permission'] ?? null;

        return $permission !== null && $this->allows($actor, $permission);
    }

    /**
     * @param  list<array<string, string>>  $errors
     * @param  list<array<string, string>>  $warnings
     * @return array<string, mixed>
     */
    private function validationResult(array $errors, array $warnings, ?int $cost = null): array
    {
        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'estimated_cost' => $cost,
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function estimateCost(array $definition): int
    {
        $fieldCount = count($definition['fields'] ?? []);
        $groupCount = count($definition['group_by'] ?? []);
        $filterCount = count($definition['filters'] ?? []);
        $base = $groupCount > 0 ? 200 : 500;

        return ($fieldCount + $filterCount + 1) * $base;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatRow(Model $row, array $columns): array
    {
        $formatted = [];
        foreach ($columns as $column) {
            $value = $row->{$column} ?? null;
            $formatted[$column] = $value instanceof Carbon ? $value->toIso8601String() : $value;
        }

        return $formatted;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateReportPayload(array $payload, bool $partial = false): array
    {
        $rules = [
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'branch_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'definition' => [$partial ? 'sometimes' : 'required', 'array'],
        ];

        return validator($payload, $rules)->validate();
    }

    private function publishedVersion(CustomReport $report): ?CustomReportVersion
    {
        return CustomReportVersion::query()
            ->where('custom_report_id', $report->id)
            ->where('version', $report->current_version)
            ->where('status', CustomReportVersion::STATUS_PUBLISHED)
            ->first();
    }

    private function draftVersion(CustomReport $report): CustomReportVersion
    {
        $draft = CustomReportVersion::query()
            ->where('custom_report_id', $report->id)
            ->orderByDesc('version')
            ->first();

        if ($draft === null) {
            throw new CustomReportException('Report has no versions.', 'missing_version', 422);
        }

        return $draft;
    }

    private function applyReportBranchScope(Builder $query, User $actor): void
    {
        if (BranchScope::for($actor)->isChurchWide()) {
            return;
        }

        $ids = $this->scopedBranchIds($actor) ?? [];
        $query->where(function (Builder $inner) use ($ids): void {
            $inner->whereNull('branch_id')->orWhereIn('branch_id', $ids ?: [0]);
        });
    }

    private function assertInScope(User $actor, CustomReport $report): void
    {
        if ($report->branch_id === null) {
            return;
        }

        BranchScope::for($actor)->assertIncludes($report->branch_id);
    }

    private function allows(User $actor, string $permission): bool
    {
        return $this->authorization->allows($actor, $permission);
    }

    private function assertCan(User $actor, string $permission): void
    {
        if (! $this->allows($actor, $permission)) {
            throw new AuthorizationException('Forbidden.');
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function audit(User $actor, string $action, CustomReport $report, array $context = []): void
    {
        $this->audit->record(
            actor: $actor,
            action: $action,
            category: AuditEvent::CATEGORY_BUSINESS,
            module: 'reports',
            branchId: $report->branch_id,
            subjectType: CustomReport::class,
            subjectId: $report->id,
            metadata: $context,
        );
    }
}
