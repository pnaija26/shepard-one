<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\AuditEvent;
use App\Models\CareCase;
use App\Models\Communication;
use App\Models\CommunicationDelivery;
use App\Models\Contribution;
use App\Models\Member;
use App\Models\Organization;
use App\Models\ServiceTeam;
use App\Models\ServiceTeamAssignment;
use App\Models\TeamReport;
use App\Models\User;
use App\Models\VolunteerProfile;
use App\Models\WelfareRequest;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Story 13.2: trusted standard church reports with disclosure and limitation handling.
 */
class StandardReportService
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
        $this->assertCan($actor, 'reports.standard.read');

        $reports = [];
        foreach (config('standard_reports.reports', []) as $key => $meta) {
            if (! $this->allows($actor, $meta['permission'] ?? 'reports.standard.read')) {
                continue;
            }

            if (! $this->hasRequiredPermissions($actor, $meta['required_permissions'] ?? [])) {
                continue;
            }

            $reports[$key] = array_merge($meta, [
                'key' => $key,
                'period_presets' => config('standard_reports.period_presets', []),
            ]);
        }

        return [
            'reports' => $reports,
            'period_presets' => config('standard_reports.period_presets', []),
            'disclosure_policy' => config('standard_reports.disclosure', []),
            'performance_target_ms' => config('standard_reports.performance_target_ms', 3000),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function run(User $actor, string $reportKey, array $filters = []): array
    {
        $started = microtime(true);
        $reference = 'sr-' . Str::lower(Str::random(10));

        $meta = config("standard_reports.reports.{$reportKey}");
        if ($meta === null) {
            throw ValidationException::withMessages(['report' => ['Unknown standard report.']]);
        }

        $this->assertCan($actor, $meta['permission'] ?? 'reports.standard.read');

        if (! $this->hasRequiredPermissions($actor, $meta['required_permissions'] ?? [])) {
            throw new AuthorizationException('Forbidden.');
        }

        if (! empty($filters['__test_force_failure'])) {
            throw new StandardReportException(
                'Report calculation could not be completed.',
                'calculation_failed',
                500,
                [
                    'reference' => $reference,
                    'retryable' => true,
                    'support_hint' => 'Retry the report or contact support with the reference code.',
                ],
            );
        }

        $branchId = $this->resolveOptionalBranchFilter($actor, $filters);
        [$from, $to, $prevFrom, $prevTo, $period] = $this->parsePeriod($filters, $meta);

        try {
            $sections = match ($reportKey) {
                'membership' => $this->membershipSections($actor, $branchId, $from, $to, $prevFrom, $prevTo, $filters),
                'attendance' => $this->attendanceSections($actor, $branchId, $from, $to, $prevFrom, $prevTo, $filters),
                'team' => $this->teamSections($actor, $branchId, $from, $to, $filters),
                'welfare' => $this->welfareSections($actor, $branchId, $from, $to, $prevFrom, $prevTo, $filters),
                'care' => $this->careSections($actor, $branchId, $from, $to, $filters),
                'communication' => $this->communicationSections($actor, $branchId, $from, $to, $filters),
                'management_weekly', 'management_monthly', 'management_quarterly', 'management_annual' => $this->managementSections(
                    $actor,
                    $branchId,
                    $from,
                    $to,
                    $prevFrom,
                    $prevTo,
                ),
                default => throw ValidationException::withMessages(['report' => ['Report runner not implemented.']]),
            };
        } catch (StandardReportException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new StandardReportException(
                'Report calculation could not be completed.',
                'calculation_failed',
                500,
                [
                    'reference' => $reference,
                    'retryable' => true,
                    'support_hint' => 'Retry the report or contact support with the reference code.',
                ],
            );
        }

        $state = $this->resolveReportState($sections);
        $limitations = $this->buildLimitations($sections, $state);
        $reconciliation = $this->reconcileSections($reportKey, $sections);

        $branch = $branchId !== null
            ? Organization::query()->find($branchId, ['id', 'name', 'identifier', 'type'])
            : null;

        $elapsedMs = (int) round((microtime(true) - $started) * 1000);

        $payload = [
            'key' => $reportKey,
            'label' => $meta['label'],
            'category' => $meta['category'],
            'definition' => $meta['definition'],
            'generated_at' => now()->toIso8601String(),
            'reference' => $reference,
            'state' => $state,
            'period' => $period,
            'filters' => $this->sanitizeFilters($filters),
            'branch' => $branch ? [
                'id' => $branch->id,
                'name' => $branch->name,
                'identifier' => $branch->identifier,
            ] : null,
            'sections' => $sections,
            'limitations' => $limitations,
            'reconciliation' => $reconciliation,
            'support' => [
                'reference' => $reference,
                'retryable' => in_array($state, ['failed', 'partial', 'stale'], true),
                'hint' => $limitations[0]['message'] ?? 'Contact support with the reference if the issue persists.',
            ],
            'performance' => [
                'elapsed_ms' => $elapsedMs,
                'within_target' => $elapsedMs <= (int) config('standard_reports.performance_target_ms', 3000),
            ],
        ];

        $this->audit($actor, 'standard_report.run', $reportKey, [
            'reference' => $reference,
            'state' => $state,
            'branch_id' => $branchId,
            'period' => $period,
        ]);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function membershipSections(
        User $actor,
        ?int $branchId,
        Carbon $from,
        Carbon $to,
        Carbon $prevFrom,
        Carbon $prevTo,
        array $filters,
    ): array {
        $query = Member::query()->whereNull('merged_into_id');
        $this->applyBranchScope($query, $actor, $branchId);

        if (! empty($filters['lifecycle_status'])) {
            $query->where('lifecycle_status', $filters['lifecycle_status']);
        }

        if (! empty($filters['lifecycle_stage'])) {
            $query->where('lifecycle_stage', $filters['lifecycle_stage']);
        }

        $active = (clone $query)->where('lifecycle_status', 'active')->count();
        $newCurrent = (clone $query)->whereBetween('created_at', [$from, $to])->count();
        $newPrevious = (clone $query)->whereBetween('created_at', [$prevFrom, $prevTo])->count();

        $byStage = (clone $query)
            ->selectRaw('lifecycle_stage, count(*) as total')
            ->groupBy('lifecycle_stage')
            ->pluck('total', 'lifecycle_stage')
            ->all();

        return [
            $this->section('active_members', 'Active members', 'Members with lifecycle status active, excluding merged records.', $active, $newPrevious, now()),
            $this->section('new_members', 'New registrations', 'Members registered in the selected period.', $newCurrent, $newPrevious, now()),
            $this->section('lifecycle_distribution', 'Lifecycle distribution', 'Member counts grouped by lifecycle stage.', array_sum($byStage), 0, now(), [
                'breakdown' => $byStage,
                'metric_type' => 'distribution',
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function attendanceSections(
        User $actor,
        ?int $branchId,
        Carbon $from,
        Carbon $to,
        Carbon $prevFrom,
        Carbon $prevTo,
        array $filters,
    ): array {
        $query = AttendanceRecord::query()->where('service_cancelled', false);
        $this->applyBranchScope($query, $actor, $branchId);

        if (! empty($filters['service_type'])) {
            $query->where('service_type', $filters['service_type']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $current = (clone $query)->whereBetween('gathering_date', [$from->toDateString(), $to->toDateString()])->count();
        $previous = (clone $query)->whereBetween('gathering_date', [$prevFrom->toDateString(), $prevTo->toDateString()])->count();

        $latest = (clone $query)->max('gathering_date');
        $staleDays = (int) config('standard_reports.stale_thresholds.attendance_days', 14);
        $freshness = $latest === null || Carbon::parse($latest)->lt(now()->subDays($staleDays))
            ? 'stale'
            : 'current';

        $byStatus = (clone $query)
            ->whereBetween('gathering_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $section = $this->section(
            'attendance_records',
            'Attendance records',
            'Captured attendance records in the selected period.',
            $current,
            $previous,
            $latest ? Carbon::parse($latest)->endOfDay() : now(),
            ['breakdown' => $byStatus],
            $freshness,
        );

        if ($freshness === 'stale') {
            $section['state'] = 'stale';
            $section['limitation'] = 'Attendance source data may be stale; results may not reflect recent gatherings.';
        }

        if ($current === 0 && $previous === 0) {
            $section['state'] = 'empty';
            $section['limitation'] = 'No attendance records found for the selected period.';
            $section['value'] = null;
        }

        return [$section];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function teamSections(User $actor, ?int $branchId, Carbon $from, Carbon $to, array $filters): array
    {
        $teamQuery = ServiceTeam::query()->where('status', ServiceTeam::STATUS_ACTIVE);
        $this->applyBranchScope($teamQuery, $actor, $branchId);

        if (! empty($filters['service_team_id'])) {
            $teamQuery->where('id', (int) $filters['service_team_id']);
        }

        $teams = (clone $teamQuery)->count();

        $assignmentQuery = ServiceTeamAssignment::query()
            ->where('status', ServiceTeamAssignment::STATUS_ACTIVE)
            ->whereHas('team', function (Builder $inner) use ($actor, $branchId, $filters): void {
                $inner->where('status', ServiceTeam::STATUS_ACTIVE);
                $this->applyBranchScope($inner, $actor, $branchId);
                if (! empty($filters['service_team_id'])) {
                    $inner->where('id', (int) $filters['service_team_id']);
                }
            });
        $assignments = $assignmentQuery->count();

        $reportQuery = TeamReport::query()
            ->whereBetween('reporting_period_start', [$from->toDateString(), $to->toDateString()]);
        $this->applyBranchScope($reportQuery, $actor, $branchId, 'branch_id');
        $submitted = (clone $reportQuery)->where('status', TeamReport::STATUS_SUBMITTED)->count();
        $approved = (clone $reportQuery)->where('status', TeamReport::STATUS_APPROVED)->count();

        $volunteerQuery = VolunteerProfile::query()->where('status', VolunteerProfile::STATUS_ACTIVE);
        $this->applyBranchScope($volunteerQuery, $actor, $branchId);

        return [
            $this->section('active_teams', 'Active service teams', 'Teams marked active in scope.', $teams, 0, now()),
            $this->section('active_assignments', 'Active assignments', 'Members actively assigned to team duties.', $assignments, 0, now()),
            $this->section('team_reports_submitted', 'Submitted team reports', 'Team reports submitted in the selected period.', $submitted, 0, now()),
            $this->section('team_reports_approved', 'Approved team reports', 'Team reports approved in the selected period.', $approved, 0, now()),
            $this->section('active_volunteers', 'Active volunteers', 'Volunteer profiles marked active in scope.', $volunteerQuery->count(), 0, now()),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function welfareSections(
        User $actor,
        ?int $branchId,
        Carbon $from,
        Carbon $to,
        Carbon $prevFrom,
        Carbon $prevTo,
        array $filters,
    ): array {
        $query = WelfareRequest::query();
        $this->applyBranchScope($query, $actor, $branchId);

        if (! empty($filters['request_type'])) {
            $query->where('request_type', $filters['request_type']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $submitted = (clone $query)->whereBetween('created_at', [$from, $to])->count();
        $submittedPrevious = (clone $query)->whereBetween('created_at', [$prevFrom, $prevTo])->count();
        $open = (clone $query)->whereNotIn('status', [
            WelfareRequest::STATUS_CLOSED,
            WelfareRequest::STATUS_REJECTED,
        ])->count();

        $disclosure = $this->applyDisclosure($open, 'welfare_open_cases');

        return [
            $this->section('welfare_submissions', 'Welfare submissions', 'Welfare requests created in the selected period.', $submitted, $submittedPrevious, now()),
            $this->section(
                'welfare_open_cases',
                'Open welfare cases',
                'Cases not closed or rejected; beneficiary identity minimized.',
                $disclosure['value'] ?? 0,
                0,
                now(),
                [
                    'suppressed' => $disclosure['suppressed'],
                    'suppression_reason' => $disclosure['reason'] ?? null,
                ],
                'current',
                $disclosure['suppressed'] ? 'suppressed' : null,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function careSections(User $actor, ?int $branchId, Carbon $from, Carbon $to, array $filters): array
    {
        $query = CareCase::query();
        $this->applyBranchScope($query, $actor, $branchId);
        $this->applyCareVisibilityFilter($query, $actor);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $opened = (clone $query)->whereBetween('created_at', [$from, $to])->count();
        $open = (clone $query)->where('status', CareCase::STATUS_OPEN)->count();
        $disclosure = $this->applyDisclosure($open, 'care_open_cases');

        return [
            $this->section('care_opened', 'Cases opened', 'Pastoral care cases opened in the selected period.', $opened, 0, now()),
            $this->section(
                'care_open_cases',
                'Open care cases',
                'Open cases visible to the viewer within scope.',
                $disclosure['value'] ?? 0,
                0,
                now(),
                [
                    'suppressed' => $disclosure['suppressed'],
                    'suppression_reason' => $disclosure['reason'] ?? null,
                ],
                'current',
                $disclosure['suppressed'] ? 'suppressed' : null,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function communicationSections(User $actor, ?int $branchId, Carbon $from, Carbon $to, array $filters): array
    {
        $query = Communication::query();
        $this->applyBranchScope($query, $actor, $branchId);

        if (! empty($filters['purpose'])) {
            $query->where('purpose', $filters['purpose']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $communications = (clone $query)
            ->whereBetween('created_at', [$from, $to])
            ->get(['id', 'status', 'sent_count', 'failed_count', 'skipped_count', 'processed_at']);

        $total = $communications->count();
        $sent = (int) $communications->sum('sent_count');
        $failed = (int) $communications->sum('failed_count');

        $deliveryQuery = CommunicationDelivery::query()
            ->whereHas('communication', function (Builder $inner) use ($actor, $branchId, $from, $to, $filters): void {
                $this->applyBranchScope($inner, $actor, $branchId);
                $inner->whereBetween('created_at', [$from, $to]);
                if (! empty($filters['purpose'])) {
                    $inner->where('purpose', $filters['purpose']);
                }
                if (! empty($filters['status'])) {
                    $inner->where('status', $filters['status']);
                }
            });

        $pendingDeliveries = (clone $deliveryQuery)
            ->whereIn('status', [
                CommunicationDelivery::STATUS_PENDING,
                CommunicationDelivery::STATUS_QUEUED,
                CommunicationDelivery::STATUS_DEFERRED,
            ])
            ->count();

        $latestProcessed = $communications->max('processed_at');
        $staleDays = (int) config('standard_reports.stale_thresholds.communication_days', 3);
        $freshness = $latestProcessed === null || Carbon::parse($latestProcessed)->lt(now()->subDays($staleDays))
            ? 'stale'
            : 'current';

        $state = 'ready';
        $limitation = null;

        if ($total === 0) {
            $state = 'empty';
            $limitation = 'No communications were created in the selected period.';
        } elseif ($pendingDeliveries > 0 || $failed > 0) {
            $state = 'partial';
            $limitation = 'Some deliveries are still pending or failed; totals may be incomplete.';
        } elseif ($freshness === 'stale') {
            $state = 'stale';
            $limitation = 'Communication provider data may be stale.';
        }

        $section = $this->section(
            'communications_sent',
            'Communications',
            'Outbound communications created in the selected period.',
            $total,
            0,
            $latestProcessed ? Carbon::parse($latestProcessed) : now(),
            [
                'sent_deliveries' => $sent,
                'failed_deliveries' => $failed,
                'pending_deliveries' => $pendingDeliveries,
            ],
            $freshness,
            $state,
        );

        if ($limitation !== null) {
            $section['limitation'] = $limitation;
        }

        if ($state === 'empty') {
            $section['value'] = null;
        }

        return [$section];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function managementSections(
        User $actor,
        ?int $branchId,
        Carbon $from,
        Carbon $to,
        Carbon $prevFrom,
        Carbon $prevTo,
    ): array {
        $sections = [];

        if ($this->allows($actor, 'members.read')) {
            $sections = array_merge($sections, $this->membershipSections($actor, $branchId, $from, $to, $prevFrom, $prevTo, []));
        }

        if ($this->allows($actor, 'attendance.read')) {
            $sections = array_merge($sections, $this->attendanceSections($actor, $branchId, $from, $to, $prevFrom, $prevTo, []));
        }

        if ($this->allows($actor, 'teams.read')) {
            $sections[] = $this->teamSections($actor, $branchId, $from, $to, [])[0];
        }

        if ($this->allows($actor, 'welfare.reports.read')) {
            $sections = array_merge($sections, $this->welfareSections($actor, $branchId, $from, $to, $prevFrom, $prevTo, []));
        }

        if ($this->allows($actor, 'care.cases.read')) {
            $sections = array_merge($sections, $this->careSections($actor, $branchId, $from, $to, []));
        }

        if ($this->allows($actor, 'communications.read')) {
            $sections = array_merge($sections, $this->communicationSections($actor, $branchId, $from, $to, []));
        }

        if ($this->allows($actor, 'payments.giving.reports')) {
            $giving = $this->givingSection($actor, $branchId, $from, $to, $prevFrom, $prevTo);
            if ($giving !== null) {
                $sections[] = $giving;
            }
        }

        return $sections;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function givingSection(
        User $actor,
        ?int $branchId,
        Carbon $from,
        Carbon $to,
        Carbon $prevFrom,
        Carbon $prevTo,
    ): ?array {
        $query = Contribution::query()
            ->where('status', Contribution::STATUS_SUCCEEDED)
            ->where('reconciliation_status', Contribution::RECON_RECONCILED);
        $this->applyBranchScope($query, $actor, $branchId);

        $current = (int) (clone $query)->whereBetween('occurred_at', [$from, $to])->sum('amount_cents');
        $previous = (int) (clone $query)->whereBetween('occurred_at', [$prevFrom, $prevTo])->sum('amount_cents');

        $latest = (clone $query)->max('occurred_at');
        $staleDays = (int) config('standard_reports.stale_thresholds.giving_days', 7);
        $freshness = $latest === null || Carbon::parse($latest)->lt(now()->subDays($staleDays))
            ? 'stale'
            : 'current';

        $disclosure = $this->applyDisclosure((int) round($current / 100), 'giving_total_cents');

        $section = $this->section(
            'giving_total_cents',
            'Reconciled giving',
            'Reconciled successful contributions in the selected period (donor identity minimized).',
            $disclosure['value'] !== null ? $current : 0,
            $previous,
            $latest ? Carbon::parse($latest) : now(),
            [
                'amount_cents' => $disclosure['suppressed'] ? null : $current,
                'suppressed' => $disclosure['suppressed'],
                'suppression_reason' => $disclosure['reason'] ?? null,
            ],
            $freshness,
            $disclosure['suppressed'] ? 'suppressed' : null,
        );

        if ($disclosure['suppressed']) {
            $section['value'] = null;
        }

        return $section;
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function section(
        string $key,
        string $label,
        string $definition,
        int $value,
        int $previousValue,
        Carbon $dataAsOf,
        array $details = [],
        string $freshness = 'current',
        ?string $forcedState = null,
    ): array {
        $state = $forcedState ?? ($value === 0 && $previousValue === 0 ? 'empty' : 'ready');

        if ($forcedState === null && $value > 0) {
            $state = 'ready';
        }

        return array_merge([
            'key' => $key,
            'label' => $label,
            'definition' => $definition,
            'state' => $state,
            'value' => in_array($state, ['empty', 'suppressed'], true) ? null : $value,
            'previous_value' => $previousValue,
            'trend' => $this->trend($value, $previousValue),
            'data_as_of' => $dataAsOf->toIso8601String(),
            'freshness' => $freshness,
        ], $details);
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @return array<string, mixed>
     */
    private function reconcileSections(string $reportKey, array $sections): array
    {
        $readyCount = collect($sections)->whereIn('state', ['ready', 'suppressed'])->count();

        return [
            'section_count' => count($sections),
            'ready_sections' => $readyCount,
            'reconciled' => $readyCount > 0 || collect($sections)->contains(fn (array $section) => ($section['state'] ?? '') === 'empty'),
            'report_key' => $reportKey,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @return list<array<string, mixed>>
     */
    private function buildLimitations(array $sections, string $state): array
    {
        $limitations = [];

        foreach ($sections as $section) {
            if (! empty($section['limitation'])) {
                $limitations[] = [
                    'section' => $section['key'],
                    'code' => $section['state'],
                    'message' => $section['limitation'],
                ];
            }

            if (! empty($section['suppressed'])) {
                $limitations[] = [
                    'section' => $section['key'],
                    'code' => 'small_group_suppression',
                    'message' => 'Value suppressed to protect a small sensitive cohort.',
                ];
            }
        }

        if ($state === 'empty' && $limitations === []) {
            $limitations[] = [
                'section' => null,
                'code' => 'no_data',
                'message' => 'No matching data for the selected filters and period.',
            ];
        }

        return $limitations;
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     */
    private function resolveReportState(array $sections): string
    {
        $states = collect($sections)->pluck('state')->unique()->values()->all();

        if (in_array('failed', $states, true)) {
            return 'failed';
        }

        if (collect($sections)->every(fn (array $section) => ($section['state'] ?? '') === 'empty')) {
            return 'empty';
        }

        if (in_array('partial', $states, true)) {
            return 'partial';
        }

        if (in_array('stale', $states, true)) {
            return 'stale';
        }

        if (in_array('suppressed', $states, true) && ! in_array('ready', $states, true)) {
            return 'partial';
        }

        return 'ready';
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{0: Carbon, 1: Carbon, 2: Carbon, 3: Carbon, 4: array<string, mixed>}
     */
    private function parsePeriod(array $filters, array $meta): array
    {
        $preset = $meta['fixed_period_preset'] ?? ($filters['period_preset'] ?? 'monthly');
        $presets = config('standard_reports.period_presets', []);

        if ($preset !== 'custom' && isset($presets[$preset]['days'])) {
            $days = (int) $presets[$preset]['days'];
            $to = now()->endOfDay();
            $from = $to->copy()->subDays($days - 1)->startOfDay();
        } else {
            $to = ! empty($filters['period_to'])
                ? Carbon::parse($filters['period_to'])->endOfDay()
                : now()->endOfDay();
            $from = ! empty($filters['period_from'])
                ? Carbon::parse($filters['period_from'])->startOfDay()
                : $to->copy()->subDays(29)->startOfDay();
            $preset = 'custom';
        }

        $span = max(1, $from->diffInDays($to) + 1);
        $prevTo = $from->copy()->subDay()->endOfDay();
        $prevFrom = $prevTo->copy()->subDays($span - 1)->startOfDay();

        return [
            $from,
            $to,
            $prevFrom,
            $prevTo,
            [
                'preset' => $preset,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'previous_from' => $prevFrom->toDateString(),
                'previous_to' => $prevTo->toDateString(),
                'days' => $span,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function resolveOptionalBranchFilter(User $actor, array $filters): ?int
    {
        if (empty($filters['branch_id'])) {
            return null;
        }

        $branchId = (int) $filters['branch_id'];
        $branch = Organization::query()->findOrFail($branchId);

        if ($branch->type !== 'branch') {
            throw ValidationException::withMessages([
                'branch_id' => ['Branch filter must reference a branch organization.'],
            ]);
        }

        BranchScope::for($actor)->assertIncludes($branch);

        return $branchId;
    }

    private function applyBranchScope(Builder $query, User $actor, ?int $branchId, string $column = 'branch_id'): void
    {
        if ($branchId !== null) {
            $query->where($column, $branchId);

            return;
        }

        $ids = $this->scopedBranchIds($actor);
        $query->whereIn($column, $ids ?: [0]);
    }

    /**
     * @return list<int>
     */
    private function scopedBranchIds(User $actor): array
    {
        $query = Organization::query()
            ->where('type', 'branch')
            ->where('is_active', true);

        BranchScope::for($actor)->applyToQuery($query);

        return $query->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function applyCareVisibilityFilter(Builder $query, User $actor): void
    {
        if ($this->allows($actor, 'care.cases.sensitive.read')) {
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

    /**
     * @return array{value: int|null, suppressed: bool, reason?: string}
     */
    private function applyDisclosure(int $value, string $sectionKey): array
    {
        $min = (int) config('standard_reports.disclosure.min_cohort_size', 5);
        $sensitive = in_array($sectionKey, config('standard_reports.disclosure.sensitive_sections', []), true);

        if ($sensitive && $value > 0 && $value < $min) {
            return [
                'value' => null,
                'suppressed' => true,
                'reason' => 'small_group_suppression',
            ];
        }

        return [
            'value' => $value,
            'suppressed' => false,
        ];
    }

    /**
     * @param  list<string>  $permissions
     */
    private function hasRequiredPermissions(User $actor, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (! $this->allows($actor, $permission)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function sanitizeFilters(array $filters): array
    {
        unset($filters['__test_force_failure']);

        return $filters;
    }

    private function trend(int $current, int $previous): string
    {
        if ($current > $previous) {
            return 'up';
        }

        if ($current < $previous) {
            return 'down';
        }

        return 'flat';
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
    private function audit(User $actor, string $action, string $reportKey, array $context = []): void
    {
        $this->audit->record(
            actor: $actor,
            action: $action,
            category: AuditEvent::CATEGORY_BUSINESS,
            module: 'reports',
            branchId: $context['branch_id'] ?? $actor->branch_id,
            subjectType: 'standard_report',
            metadata: array_merge(['report_key' => $reportKey], $context),
        );
    }
}
