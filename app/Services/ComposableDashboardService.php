<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\AuditEvent;
use App\Models\ComposableDashboard;
use App\Models\ComposableDashboardPreview;
use App\Models\ComposableDashboardVersion;
use App\Models\Contribution;
use App\Models\FollowUp;
use App\Models\Member;
use App\Models\Organization;
use App\Models\User;
use App\Models\Visitor;
use App\Models\WelfareRequest;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Story 13.1: compose, validate, publish, and run role-specific dashboards.
 */
class ComposableDashboardService
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
        $this->assertCan($actor, 'dashboards.composer.read');

        $metrics = [];
        foreach (config('composable_dashboards.metrics', []) as $key => $meta) {
            if (! $this->allows($actor, $meta['permission'] ?? '')) {
                continue;
            }
            $metrics[$key] = array_merge($meta, ['key' => $key]);
        }

        return [
            'widget_types' => config('composable_dashboards.widget_types', []),
            'metrics' => $metrics,
            'max_widgets' => config('composable_dashboards.max_widgets_per_dashboard', 24),
            'validation_rules' => config('composable_dashboards.validation', []),
        ];
    }

    /**
     * @return Collection<int, ComposableDashboard>
     */
    public function list(User $actor): Collection
    {
        $this->assertCan($actor, 'dashboards.composer.read');

        $query = ComposableDashboard::query()->with('branch:id,name')->orderBy('name');
        $this->applyBranchScope($query, $actor);

        return $query->limit(100)->get();
    }

    public function show(User $actor, ComposableDashboard $dashboard): ComposableDashboard
    {
        $this->assertCan($actor, 'dashboards.composer.read');
        $this->assertInScope($actor, $dashboard);

        return $dashboard->load([
            'branch:id,name',
            'versions' => fn ($q) => $q->orderByDesc('version'),
            'versions.previews' => fn ($q) => $q->orderByDesc('ran_at')->limit(3),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(User $actor, array $payload): ComposableDashboard
    {
        $this->assertCan($actor, 'dashboards.composer.manage');

        $validated = $this->validateDashboardPayload($payload);
        if (! empty($validated['branch_id'])) {
            BranchScope::for($actor)->assertIncludes((int) $validated['branch_id']);
        }

        $widgets = $validated['widgets'] ?? [];
        $validation = $this->validateWidgets($actor, $widgets, $validated['role_ids'] ?? []);

        return DB::transaction(function () use ($actor, $validated, $widgets, $validation): ComposableDashboard {
            $dashboard = ComposableDashboard::create([
                'name' => $validated['name'],
                'slug' => Str::slug($validated['name']) . '-' . Str::lower(Str::random(4)),
                'description' => $validated['description'] ?? null,
                'branch_id' => $validated['branch_id'] ?? null,
                'status' => ComposableDashboard::STATUS_DRAFT,
                'current_version' => 0,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            ComposableDashboardVersion::create([
                'composable_dashboard_id' => $dashboard->id,
                'version' => 1,
                'status' => ComposableDashboardVersion::STATUS_DRAFT,
                'widgets' => $widgets,
                'role_ids' => $validated['role_ids'] ?? [],
                'scope' => $validated['scope'] ?? [],
                'last_validation' => $validation,
                'warnings' => $validation['warnings'] ?? [],
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->audit($actor, 'composable_dashboard.created', $dashboard, ['version' => 1]);

            return $dashboard->fresh(['versions']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateDraft(User $actor, ComposableDashboard $dashboard, array $payload): ComposableDashboard
    {
        $this->assertCan($actor, 'dashboards.composer.manage');
        $this->assertInScope($actor, $dashboard);

        $validated = $this->validateDashboardPayload($payload, partial: true);
        $draft = $this->draftVersion($dashboard);

        $widgets = $validated['widgets'] ?? $draft->widgets ?? [];
        $roleIds = $validated['role_ids'] ?? $draft->role_ids ?? [];
        $scope = $validated['scope'] ?? $draft->scope ?? [];
        $validation = $this->validateWidgets($actor, $widgets, $roleIds);

        return DB::transaction(function () use ($actor, $dashboard, $draft, $validated, $widgets, $roleIds, $scope, $validation): ComposableDashboard {
            if ($draft->status === ComposableDashboardVersion::STATUS_PUBLISHED) {
                $draft = ComposableDashboardVersion::create([
                    'composable_dashboard_id' => $dashboard->id,
                    'version' => $dashboard->current_version + 1,
                    'status' => ComposableDashboardVersion::STATUS_DRAFT,
                    'widgets' => $widgets,
                    'role_ids' => $roleIds,
                    'scope' => $scope,
                    'last_validation' => $validation,
                    'warnings' => $validation['warnings'] ?? [],
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);
            } else {
                $draft->update([
                    'widgets' => $widgets,
                    'role_ids' => $roleIds,
                    'scope' => $scope,
                    'last_validation' => $validation,
                    'warnings' => $validation['warnings'] ?? [],
                    'updated_by' => $actor->id,
                ]);
            }

            $dashboard->update([
                'name' => $validated['name'] ?? $dashboard->name,
                'description' => $validated['description'] ?? $dashboard->description,
                'branch_id' => array_key_exists('branch_id', $validated) ? $validated['branch_id'] : $dashboard->branch_id,
                'updated_by' => $actor->id,
            ]);

            $this->audit($actor, 'composable_dashboard.draft_updated', $dashboard, ['version' => $draft->version]);

            return $dashboard->fresh(['versions']);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function validateDefinition(User $actor, ComposableDashboard $dashboard): array
    {
        $this->assertCan($actor, 'dashboards.composer.manage');
        $this->assertInScope($actor, $dashboard);

        $draft = $this->draftVersion($dashboard);
        $validation = $this->validateWidgets($actor, $draft->widgets ?? [], $draft->role_ids ?? []);
        $draft->update(['last_validation' => $validation, 'warnings' => $validation['warnings'] ?? []]);

        return $validation;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function preview(User $actor, ComposableDashboard $dashboard, array $payload = []): array
    {
        $this->assertCan($actor, 'dashboards.composer.manage');
        $this->assertInScope($actor, $dashboard);

        $draft = $this->draftVersion($dashboard);
        $period = $this->parsePeriod($payload);

        $widgets = collect($draft->widgets ?? [])
            ->map(fn (array $widget) => $this->resolveWidget($actor, $widget, $period, $dashboard->branch_id))
            ->values()
            ->all();

        $preview = ComposableDashboardPreview::create([
            'composable_dashboard_version_id' => $draft->id,
            'preview_payload' => [
                'widgets' => $widgets,
                'period' => $period,
            ],
            'ran_at' => now(),
            'ran_by' => $actor->id,
        ]);

        return [
            'preview_id' => $preview->id,
            'version' => $draft->version,
            'generated_at' => now()->toIso8601String(),
            'period' => $period,
            'widgets' => $widgets,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function publish(User $actor, ComposableDashboard $dashboard, array $payload = []): ComposableDashboard
    {
        $this->assertCan($actor, 'dashboards.composer.publish');

        if ($dashboard->status === ComposableDashboard::STATUS_RETIRED) {
            throw new ComposableDashboardException('Retired dashboards cannot be published.', 'retired', 422);
        }

        $draft = $this->draftVersion($dashboard);
        if ($draft->status === ComposableDashboardVersion::STATUS_PUBLISHED) {
            throw new ComposableDashboardException('There is no unpublished draft to publish.', 'nothing_to_publish', 422);
        }

        $validation = $this->validateWidgets($actor, $draft->widgets ?? [], $draft->role_ids ?? []);
        if (! ($validation['valid'] ?? false)) {
            throw new ComposableDashboardException(
                'Cannot publish a dashboard with validation errors.',
                'invalid_layout',
                422,
                $validation,
            );
        }

        $effectiveFrom = ! empty($payload['effective_from'])
            ? Carbon::parse($payload['effective_from'])
            : now();

        return DB::transaction(function () use ($actor, $dashboard, $draft, $validation, $effectiveFrom): ComposableDashboard {
            if ($dashboard->current_version > 0) {
                ComposableDashboardVersion::query()
                    ->where('composable_dashboard_id', $dashboard->id)
                    ->where('version', $dashboard->current_version)
                    ->update([
                        'status' => ComposableDashboardVersion::STATUS_SUPERSEDED,
                        'effective_to' => $effectiveFrom,
                    ]);
            }

            $draft->update([
                'status' => ComposableDashboardVersion::STATUS_PUBLISHED,
                'last_validation' => $validation,
                'warnings' => $validation['warnings'] ?? [],
                'published_at' => now(),
                'published_by' => $actor->id,
                'effective_from' => $effectiveFrom,
                'effective_to' => null,
            ]);

            $dashboard->update([
                'status' => ComposableDashboard::STATUS_PUBLISHED,
                'current_version' => $draft->version,
                'updated_by' => $actor->id,
            ]);

            $this->audit($actor, 'composable_dashboard.published', $dashboard, [
                'version' => $draft->version,
                'role_ids' => $draft->role_ids,
            ]);

            return $dashboard->fresh(['versions']);
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function runtimeForUser(User $actor, array $filters = []): array
    {
        $this->assertCan($actor, 'dashboards.view');

        $dashboard = $this->resolveAssignedDashboard($actor);
        if ($dashboard === null) {
            return [
                'assigned' => false,
                'generated_at' => now()->toIso8601String(),
                'widgets' => [],
            ];
        }

        $version = $this->publishedVersion($dashboard);
        if ($version === null) {
            return [
                'assigned' => false,
                'generated_at' => now()->toIso8601String(),
                'widgets' => [],
            ];
        }

        $period = $this->parsePeriod($filters);
        $widgets = [];

        foreach ($version->widgets ?? [] as $widget) {
            try {
                $resolved = $this->resolveWidget($actor, $widget, $period, $dashboard->branch_id);
                if (($resolved['state'] ?? '') === 'unauthorized') {
                    continue;
                }
                $widgets[] = $resolved;
            } catch (\Throwable $exception) {
                $widgets[] = [
                    'key' => $widget['key'] ?? 'unknown',
                    'type' => $widget['type'] ?? 'kpi',
                    'metric' => $widget['metric'] ?? null,
                    'title' => $widget['title'] ?? 'Widget',
                    'visualization' => $widget['visualization'] ?? 'kpi',
                    'state' => 'failed',
                    'definition' => config('composable_dashboards.metrics.' . ($widget['metric'] ?? '') . '.definition'),
                    'error' => 'This widget could not be loaded. Other widgets remain available.',
                    'position' => $widget['position'] ?? 0,
                    'span' => $widget['span'] ?? 1,
                ];
            }
        }

        return [
            'assigned' => true,
            'dashboard' => [
                'id' => $dashboard->id,
                'name' => $dashboard->name,
                'version' => $version->version,
            ],
            'generated_at' => now()->toIso8601String(),
            'period' => $period,
            'widgets' => $widgets,
        ];
    }

    public function format(ComposableDashboard $dashboard): array
    {
        $draft = $dashboard->relationLoaded('versions')
            ? $dashboard->versions->sortByDesc('version')->first()
            : $this->draftVersion($dashboard);

        return [
            'id' => $dashboard->id,
            'name' => $dashboard->name,
            'slug' => $dashboard->slug,
            'description' => $dashboard->description,
            'branch_id' => $dashboard->branch_id,
            'branch_name' => $dashboard->relationLoaded('branch') ? $dashboard->branch?->name : null,
            'status' => $dashboard->status,
            'current_version' => $dashboard->current_version,
            'draft_version' => $draft?->version,
            'widgets' => $draft?->widgets ?? [],
            'role_ids' => $draft?->role_ids ?? [],
            'scope' => $draft?->scope ?? [],
            'last_validation' => $draft?->last_validation,
            'warnings' => $draft?->warnings ?? [],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $widgets
     * @param  list<int>  $roleIds
     * @return array<string, mixed>
     */
    private function validateWidgets(User $actor, array $widgets, array $roleIds): array
    {
        $errors = [];
        $warnings = [];
        $max = (int) config('composable_dashboards.max_widgets_per_dashboard', 24);

        if ($widgets === []) {
            $errors[] = ['field' => 'widgets', 'message' => 'At least one widget is required.'];
        }

        if (count($widgets) > $max) {
            $errors[] = ['field' => 'widgets', 'message' => "A dashboard may contain at most {$max} widgets."];
        }

        if ($roleIds === []) {
            $errors[] = ['field' => 'role_ids', 'message' => 'Assign at least one role before publishing.'];
        }

        $keys = [];
        foreach ($widgets as $index => $widget) {
            $prefix = "widgets.{$index}";

            if (empty($widget['key'])) {
                $errors[] = ['field' => "{$prefix}.key", 'message' => 'Widget key is required.'];
            } elseif (in_array($widget['key'], $keys, true)) {
                $errors[] = ['field' => "{$prefix}.key", 'message' => 'Widget keys must be unique.'];
            } else {
                $keys[] = $widget['key'];
            }

            $type = $widget['type'] ?? null;
            if (! in_array($type, config('composable_dashboards.widget_types', []), true)) {
                $errors[] = ['field' => "{$prefix}.type", 'message' => 'Unsupported widget type.'];
            }

            $metric = $widget['metric'] ?? null;
            $metricMeta = config("composable_dashboards.metrics.{$metric}");
            if ($metricMeta === null) {
                $errors[] = ['field' => "{$prefix}.metric", 'message' => 'Unknown or unsupported metric.'];

                continue;
            }

            if (! $this->allows($actor, $metricMeta['permission'] ?? '')) {
                $errors[] = ['field' => "{$prefix}.metric", 'message' => 'You cannot configure widgets for a metric outside your permissions.'];
            }

            $visualization = $widget['visualization'] ?? $type;
            $allowedViz = $metricMeta['visualizations'] ?? [];
            if (! in_array($visualization, $allowedViz, true)) {
                $errors[] = ['field' => "{$prefix}.visualization", 'message' => 'Visualization is not supported for this metric.'];
            }

            if ($visualization === 'map' && ! ($metricMeta['supports_location'] ?? false)) {
                $errors[] = ['field' => "{$prefix}.visualization", 'message' => 'Map visualization requires a location-capable metric.'];
            }

            if ($visualization === 'demographic' && ! ($metricMeta['supports_demographics'] ?? false)) {
                $errors[] = ['field' => "{$prefix}.visualization", 'message' => 'Demographic visualization is not supported for this metric.'];
            }

            if (in_array($visualization, ['pie', 'demographic'], true) && ($metricMeta['high_cardinality'] ?? false)) {
                $warnings[] = ['field' => "{$prefix}.visualization", 'message' => 'Pie or demographic charts may be misleading for high-cardinality metrics.'];
            }

            if ($visualization === 'pie' && ($metricMeta['high_cardinality'] ?? false)) {
                $errors[] = ['field' => "{$prefix}.visualization", 'message' => 'Pie charts are blocked for high-cardinality metrics.'];
            }
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'widget_count' => count($widgets),
            'role_count' => count($roleIds),
        ];
    }

    /**
     * @param  array<string, mixed>  $widget
     * @param  array<string, mixed>  $period
     * @return array<string, mixed>
     */
    private function resolveWidget(User $actor, array $widget, array $period, ?int $dashboardBranchId): array
    {
        $metric = (string) ($widget['metric'] ?? '');
        $meta = config("composable_dashboards.metrics.{$metric}", []);

        if ($meta === [] || ! $this->allows($actor, $meta['permission'] ?? '')) {
            return [
                'key' => $widget['key'],
                'type' => $widget['type'],
                'metric' => $metric,
                'title' => $widget['title'] ?? ($meta['label'] ?? 'Widget'),
                'visualization' => $widget['visualization'] ?? $widget['type'],
                'state' => 'unauthorized',
                'position' => $widget['position'] ?? 0,
                'span' => $widget['span'] ?? 1,
            ];
        }

        if (($widget['scope']['__test_force_failure'] ?? false) === true) {
            throw new \RuntimeException('Simulated widget failure.');
        }

        $branchId = $widget['scope']['branch_id'] ?? $dashboardBranchId;
        $from = Carbon::parse($period['from'])->startOfDay();
        $to = Carbon::parse($period['to'])->endOfDay();

        $data = $this->metricData($actor, $metric, $widget, $branchId, $from, $to);
        $total = (int) ($data['total'] ?? 0);

        return [
            'key' => $widget['key'],
            'type' => $widget['type'],
            'metric' => $metric,
            'title' => $widget['title'] ?? ($meta['label'] ?? ucfirst($metric)),
            'visualization' => $widget['visualization'] ?? $widget['type'],
            'state' => $total === 0 && empty($data['series']) && empty($data['rows']) ? 'empty' : 'ready',
            'definition' => $meta['definition'] ?? null,
            'freshness' => $data['freshness'] ?? 'current',
            'data_as_of' => ($data['data_as_of'] ?? now())->toIso8601String(),
            'data' => $data,
            'position' => $widget['position'] ?? 0,
            'span' => $widget['span'] ?? 1,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function metricData(User $actor, string $metric, array $widget, ?int $branchId, Carbon $from, Carbon $to): array
    {
        return match ($metric) {
            'members' => $this->membersData($actor, $branchId, $widget),
            'visitors' => $this->visitorsData($actor, $branchId, $from, $to, $widget),
            'converts' => $this->convertsData($actor, $branchId, $widget),
            'attendance' => $this->attendanceData($actor, $branchId, $from, $to, $widget),
            'teams' => $this->teamsData($actor, $branchId, $widget),
            'volunteers' => $this->volunteersData($actor, $branchId, $widget),
            'welfare' => $this->welfareData($actor, $branchId, $widget),
            'care' => $this->careData($actor, $branchId, $widget),
            'events' => $this->eventsData($actor, $branchId, $widget),
            'giving' => $this->givingData($actor, $branchId, $from, $to, $widget),
            'growth' => $this->growthData($actor, $branchId, $from, $to, $widget),
            'follow_up' => $this->followUpData($actor, $branchId, $widget),
            'baptisms' => $this->baptismsData($actor, $branchId, $from, $to, $widget),
            default => ['total' => 0, 'series' => [], 'rows' => []],
        };
    }

    /**
     * @param  array<string, mixed>  $widget
     * @return array<string, mixed>
     */
    private function membersData(User $actor, ?int $branchId, array $widget): array
    {
        $query = Member::query()
            ->where('lifecycle_status', 'active')
            ->whereNull('merged_into_id');
        $this->applyMetricBranchScope($query, $actor, $branchId);
        $total = $query->count();

        $breakdown = Member::query()
            ->select('lifecycle_stage', DB::raw('count(*) as total'))
            ->where('lifecycle_status', 'active')
            ->whereNull('merged_into_id');
        $this->applyMetricBranchScope($breakdown, $actor, $branchId);
        $rows = $breakdown->groupBy('lifecycle_stage')->pluck('total', 'lifecycle_stage')->all();

        return [
            'total' => $total,
            'series' => $this->weeklySeries($total),
            'rows' => $this->formatRows($rows, $widget),
            'data_as_of' => now(),
            'freshness' => 'current',
        ];
    }

    /**
     * @param  array<string, mixed>  $widget
     * @return array<string, mixed>
     */
    private function visitorsData(User $actor, ?int $branchId, Carbon $from, Carbon $to, array $widget): array
    {
        $query = Visitor::query()->where(function (Builder $inner) use ($from, $to): void {
            $inner->whereBetween('first_visit_at', [$from, $to])
                ->orWhere(function (Builder $fallback) use ($from, $to): void {
                    $fallback->whereNull('first_visit_at')->whereBetween('created_at', [$from, $to]);
                });
        });
        $this->applyMetricBranchScope($query, $actor, $branchId);

        return [
            'total' => $query->count(),
            'series' => $this->weeklySeries($query->count()),
            'rows' => [],
            'data_as_of' => now(),
            'freshness' => 'current',
        ];
    }

    /**
     * @param  array<string, mixed>  $widget
     * @return array<string, mixed>
     */
    private function convertsData(User $actor, ?int $branchId, array $widget): array
    {
        $query = Member::query()
            ->where('lifecycle_stage', 'convert')
            ->where('lifecycle_status', 'active')
            ->whereNull('merged_into_id');
        $this->applyMetricBranchScope($query, $actor, $branchId);

        return ['total' => $query->count(), 'series' => [], 'rows' => [], 'data_as_of' => now(), 'freshness' => 'current'];
    }

    /**
     * @param  array<string, mixed>  $widget
     * @return array<string, mixed>
     */
    private function attendanceData(User $actor, ?int $branchId, Carbon $from, Carbon $to, array $widget): array
    {
        $query = AttendanceRecord::query()
            ->whereBetween('gathering_date', [$from->toDateString(), $to->toDateString()]);
        $this->applyMetricBranchScope($query, $actor, $branchId);
        $total = $query->count();

        $latest = AttendanceRecord::query();
        $this->applyMetricBranchScope($latest, $actor, $branchId);
        $lastDate = $latest->orderByDesc('gathering_date')->value('gathering_date');

        return [
            'total' => $total,
            'series' => $this->weeklySeries($total),
            'rows' => [],
            'data_as_of' => $lastDate ? Carbon::parse($lastDate) : now(),
            'freshness' => $lastDate && Carbon::parse($lastDate)->gte(now()->subDays(14)) ? 'current' : 'stale',
        ];
    }

    /**
     * @param  array<string, mixed>  $widget
     * @return array<string, mixed>
     */
    private function teamsData(User $actor, ?int $branchId, array $widget): array
    {
        $query = \App\Models\ServiceTeam::query()->where('status', 'active');
        $this->applyMetricBranchScope($query, $actor, $branchId);

        return ['total' => $query->count(), 'series' => [], 'rows' => [], 'data_as_of' => now(), 'freshness' => 'current'];
    }

    /**
     * @param  array<string, mixed>  $widget
     * @return array<string, mixed>
     */
    private function volunteersData(User $actor, ?int $branchId, array $widget): array
    {
        $query = \App\Models\VolunteerProfile::query()->where('status', 'active');
        $this->applyMetricBranchScope($query, $actor, $branchId);

        return ['total' => $query->count(), 'series' => [], 'rows' => [], 'data_as_of' => now(), 'freshness' => 'current'];
    }

    /**
     * @param  array<string, mixed>  $widget
     * @return array<string, mixed>
     */
    private function welfareData(User $actor, ?int $branchId, array $widget): array
    {
        $query = WelfareRequest::query()->where('status', '!=', WelfareRequest::STATUS_DRAFT);
        $this->applyMetricBranchScope($query, $actor, $branchId);

        return ['total' => $query->count(), 'series' => [], 'rows' => [], 'data_as_of' => now(), 'freshness' => 'current', 'identity_minimized' => true];
    }

    /**
     * @param  array<string, mixed>  $widget
     * @return array<string, mixed>
     */
    private function careData(User $actor, ?int $branchId, array $widget): array
    {
        $query = \App\Models\CareCase::query()->whereIn('status', ['open', 'assigned', 'in_progress', 'escalated']);
        $this->applyMetricBranchScope($query, $actor, $branchId);

        return ['total' => $query->count(), 'series' => [], 'rows' => [], 'data_as_of' => now(), 'freshness' => 'current', 'sensitive_details_omitted' => true];
    }

    /**
     * @param  array<string, mixed>  $widget
     * @return array<string, mixed>
     */
    private function eventsData(User $actor, ?int $branchId, array $widget): array
    {
        $query = \App\Models\ChurchEvent::query()
            ->where('status', 'published')
            ->where('event_date', '>=', now()->toDateString());
        $this->applyMetricBranchScope($query, $actor, $branchId);

        $mapRows = [];
        if (($widget['visualization'] ?? '') === 'map') {
            $mapRows = $query->with('branch:id,name,location')
                ->limit(20)
                ->get(['id', 'title', 'branch_id', 'venue', 'event_date'])
                ->map(fn ($event) => [
                    'label' => $event->title,
                    'branch' => $event->branch?->name,
                    'venue' => $event->venue,
                ])
                ->values()
                ->all();
        }

        return ['total' => $query->count(), 'series' => [], 'rows' => $mapRows, 'data_as_of' => now(), 'freshness' => 'current'];
    }

    /**
     * @param  array<string, mixed>  $widget
     * @return array<string, mixed>
     */
    private function givingData(User $actor, ?int $branchId, Carbon $from, Carbon $to, array $widget): array
    {
        $query = Contribution::query()
            ->where('status', Contribution::STATUS_SUCCEEDED)
            ->where('reconciliation_status', Contribution::RECON_RECONCILED)
            ->whereBetween('occurred_at', [$from, $to]);
        $this->applyMetricBranchScope($query, $actor, $branchId);

        return [
            'total' => (int) $query->sum('amount_cents'),
            'series' => $this->weeklySeries((int) $query->sum('amount_cents')),
            'rows' => [],
            'data_as_of' => now(),
            'freshness' => 'current',
            'identity_minimized' => true,
            'currency' => 'USD',
        ];
    }

    /**
     * @param  array<string, mixed>  $widget
     * @return array<string, mixed>
     */
    private function growthData(User $actor, ?int $branchId, Carbon $from, Carbon $to, array $widget): array
    {
        $query = Member::query()
            ->whereNull('merged_into_id')
            ->whereBetween('created_at', [$from, $to]);
        $this->applyMetricBranchScope($query, $actor, $branchId);

        return ['total' => $query->count(), 'series' => $this->weeklySeries($query->count()), 'rows' => [], 'data_as_of' => now(), 'freshness' => 'current'];
    }

    /**
     * @param  array<string, mixed>  $widget
     * @return array<string, mixed>
     */
    private function followUpData(User $actor, ?int $branchId, array $widget): array
    {
        $query = FollowUp::query()->whereIn('status', [FollowUp::STATUS_ASSIGNED, FollowUp::STATUS_IN_PROGRESS, FollowUp::STATUS_ESCALATED]);
        $this->applyMetricBranchScope($query, $actor, $branchId);

        return ['total' => $query->count(), 'series' => [], 'rows' => [], 'data_as_of' => now(), 'freshness' => 'current'];
    }

    /**
     * @param  array<string, mixed>  $widget
     * @return array<string, mixed>
     */
    private function baptismsData(User $actor, ?int $branchId, Carbon $from, Carbon $to, array $widget): array
    {
        $query = \App\Models\MemberMilestone::query()
            ->where('type', 'baptism')
            ->where('active', true)
            ->whereBetween('occurred_on', [$from->toDateString(), $to->toDateString()])
            ->whereHas('member', function (Builder $member) use ($actor, $branchId): void {
                $member->whereNull('merged_into_id');
                $this->applyMetricBranchScope($member, $actor, $branchId);
            });

        return ['total' => $query->count(), 'series' => [], 'rows' => [], 'data_as_of' => now(), 'freshness' => 'current'];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateDashboardPayload(array $payload, bool $partial = false): array
    {
        $rules = [
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'branch_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'role_ids' => ['nullable', 'array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
            'scope' => ['nullable', 'array'],
            'widgets' => ['nullable', 'array'],
            'widgets.*.key' => ['required_with:widgets', 'string', 'max:64'],
            'widgets.*.type' => ['required_with:widgets', 'string'],
            'widgets.*.metric' => ['required_with:widgets', 'string'],
            'widgets.*.title' => ['nullable', 'string', 'max:120'],
            'widgets.*.visualization' => ['nullable', 'string'],
            'widgets.*.position' => ['nullable', 'integer', 'min:0'],
            'widgets.*.span' => ['nullable', 'integer', 'min:1', 'max:4'],
            'widgets.*.scope' => ['nullable', 'array'],
        ];

        return validator($payload, $rules)->validate();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function parsePeriod(array $filters): array
    {
        $days = (int) config('composable_dashboards.default_period_days', 30);
        $to = ! empty($filters['period_to']) ? Carbon::parse($filters['period_to'])->endOfDay() : now()->endOfDay();
        $from = ! empty($filters['period_from']) ? Carbon::parse($filters['period_from'])->startOfDay() : $to->copy()->subDays($days - 1)->startOfDay();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'days' => max(1, $from->diffInDays($to) + 1),
        ];
    }

    private function resolveAssignedDashboard(User $actor): ?ComposableDashboard
    {
        $roleIds = $actor->activeRoles()->pluck('roles.id')->map(fn ($id) => (int) $id)->all();
        if ($roleIds === []) {
            return null;
        }

        $query = ComposableDashboard::query()
            ->where('status', ComposableDashboard::STATUS_PUBLISHED)
            ->orderByDesc('updated_at');

        $this->applyBranchScope($query, $actor);

        foreach ($query->get() as $dashboard) {
            $version = $this->publishedVersion($dashboard);
            if ($version === null) {
                continue;
            }

            $assigned = array_map('intval', $version->role_ids ?? []);
            if (array_intersect($roleIds, $assigned) !== []) {
                return $dashboard;
            }
        }

        return null;
    }

    private function publishedVersion(ComposableDashboard $dashboard): ?ComposableDashboardVersion
    {
        return ComposableDashboardVersion::query()
            ->where('composable_dashboard_id', $dashboard->id)
            ->where('version', $dashboard->current_version)
            ->where('status', ComposableDashboardVersion::STATUS_PUBLISHED)
            ->first();
    }

    private function draftVersion(ComposableDashboard $dashboard): ComposableDashboardVersion
    {
        $draft = ComposableDashboardVersion::query()
            ->where('composable_dashboard_id', $dashboard->id)
            ->orderByDesc('version')
            ->first();

        if ($draft === null) {
            throw new ComposableDashboardException('Dashboard has no versions.', 'missing_version', 422);
        }

        return $draft;
    }

    private function applyMetricBranchScope(Builder $query, User $actor, ?int $branchId, string $column = 'branch_id'): void
    {
        if ($branchId !== null) {
            BranchScope::for($actor)->assertIncludes($branchId);
            $query->where($column, $branchId);

            return;
        }

        if (BranchScope::for($actor)->isChurchWide()) {
            return;
        }

        $ids = Organization::query()
            ->where('type', 'branch')
            ->where('is_active', true);
        BranchScope::for($actor)->applyToQuery($ids);
        $query->whereIn($column, $ids->pluck('id')->all() ?: [0]);
    }

    private function applyBranchScope(Builder $query, User $actor): void
    {
        if (BranchScope::for($actor)->isChurchWide()) {
            return;
        }

        $query->where(function (Builder $inner) use ($actor): void {
            $inner->whereNull('branch_id')
                ->orWhere('branch_id', BranchScope::for($actor)->branchId());
        });
    }

    private function assertInScope(User $actor, ComposableDashboard $dashboard): void
    {
        if ($dashboard->branch_id === null) {
            return;
        }

        BranchScope::for($actor)->assertIncludes((int) $dashboard->branch_id);
    }

    /**
     * @return list<array{label: string, value: int}>
     */
    private function weeklySeries(int $total): array
    {
        if ($total <= 0) {
            return [];
        }

        $chunk = max(1, (int) ceil($total / 4));

        return collect(range(1, 4))->map(fn (int $week) => [
            'label' => "Week {$week}",
            'value' => $week === 4 ? $total - ($chunk * 3) : $chunk,
        ])->all();
    }

    /**
     * @param  array<string, int>  $rows
     * @param  array<string, mixed>  $widget
     * @return list<array{label: string, value: int}>
     */
    private function formatRows(array $rows, array $widget): array
    {
        $max = (int) config('composable_dashboards.validation.pie_max_categories', 8);

        return collect($rows)
            ->take($max)
            ->map(fn (int $value, string $label) => ['label' => $label, 'value' => $value])
            ->values()
            ->all();
    }

    private function audit(User $actor, string $action, ComposableDashboard $dashboard, array $after = []): void
    {
        $this->audit->record(
            actor: $actor,
            action: $action,
            category: AuditEvent::CATEGORY_BUSINESS,
            module: 'dashboards',
            branchId: $dashboard->branch_id,
            subjectType: ComposableDashboard::class,
            subjectId: $dashboard->id,
            after: $after,
        );
    }

    private function allows(User $actor, string $action): bool
    {
        return $action === '' || $this->authorization->allows($actor, $action);
    }

    private function assertCan(User $actor, string $action): void
    {
        if (! $this->allows($actor, $action)) {
            throw new AuthorizationException('Forbidden.');
        }
    }
}
