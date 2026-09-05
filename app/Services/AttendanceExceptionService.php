<?php

namespace App\Services;

use App\Models\AttendanceException;
use App\Models\AttendanceExceptionRule;
use App\Models\AttendanceExceptionRuleVersion;
use App\Models\AttendanceRecord;
use App\Models\AuditEvent;
use App\Models\Member;
use App\Models\User;
use App\Models\Visitor;
use App\Models\VisitorVisit;
use App\Services\BranchScope;
use App\Services\BranchScopeException;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Story 3.3: configurable attendance exception rules and evaluation.
 */
class AttendanceExceptionService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
        private FollowUpService $followUps,
    ) {
    }

    /**
     * @return Collection<int, AttendanceExceptionRule>
     */
    public function listRules(User $actor): Collection
    {
        $this->assertCan($actor, 'attendance.exceptions.read');

        $query = AttendanceExceptionRule::query()->with('branch:id,name')->orderBy('name');
        $this->applyBranchScope($query, $actor);

        return $query->get();
    }

    /**
     * @return Collection<int, AttendanceException>
     */
    public function listExceptions(User $actor, array $filters = []): Collection
    {
        $this->assertCan($actor, 'attendance.exceptions.read');

        $query = AttendanceException::query()
            ->with(['rule:id,name,rule_type', 'branch:id,name'])
            ->orderByDesc('detected_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $this->applyExceptionBranchScope($query, $actor);

        return $query->limit(200)->get();
    }

    public function showException(User $actor, AttendanceException $exception): AttendanceException
    {
        $this->assertCan($actor, 'attendance.exceptions.read');
        $this->assertExceptionInScope($actor, $exception);

        return $exception->load(['rule:id,name,rule_type', 'branch:id,name', 'ruleVersion']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createRule(User $actor, array $payload): AttendanceExceptionRule
    {
        $this->assertCan($actor, 'attendance.exceptions.manage');

        $validated = $this->validateRulePayload($payload);

        return AttendanceExceptionRule::create([
            'name' => $validated['name'],
            'rule_type' => $validated['rule_type'],
            'branch_id' => $validated['branch_id'] ?? null,
            'service_type' => $validated['service_type'] ?? null,
            'status' => AttendanceExceptionRule::STATUS_DRAFT,
            'current_version' => 0,
            'created_by' => $actor->id,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $parameters
     * @param  array<string, mixed>|null  $exclusions
     */
    public function publishRule(
        User $actor,
        AttendanceExceptionRule $rule,
        ?array $parameters = null,
        ?array $exclusions = null,
        ?string $correctionPolicy = null,
    ): AttendanceExceptionRuleVersion {
        $this->assertCan($actor, 'attendance.exceptions.manage');
        $this->assertRuleInScope($actor, $rule);

        $parameters = $parameters ?? config('attendance_exceptions.default_parameters.' . $rule->rule_type, []);
        $exclusions = $exclusions ?? config('attendance_exceptions.default_exclusions', []);
        $correctionPolicy = $correctionPolicy ?? config('attendance_exceptions.default_correction_policy', 'resolve');

        if (! in_array($correctionPolicy, config('attendance_exceptions.correction_policies', []), true)) {
            throw ValidationException::withMessages(['correction_policy' => ['Invalid correction policy.']]);
        }

        return DB::transaction(function () use ($actor, $rule, $parameters, $exclusions, $correctionPolicy): AttendanceExceptionRuleVersion {
            $version = $rule->current_version + 1;

            $ruleVersion = AttendanceExceptionRuleVersion::create([
                'rule_id' => $rule->id,
                'version' => $version,
                'parameters' => $parameters,
                'exclusions' => $exclusions,
                'correction_policy' => $correctionPolicy,
                'published_by' => $actor->id,
                'published_at' => now(),
            ]);

            $rule->update([
                'status' => AttendanceExceptionRule::STATUS_PUBLISHED,
                'current_version' => $version,
            ]);

            $this->audit->record(
                actor: $actor,
                action: 'attendance.exception_rule.published',
                category: AuditEvent::CATEGORY_BUSINESS,
                module: 'attendance',
                branchId: $rule->branch_id,
                subjectType: AttendanceExceptionRule::class,
                subjectId: $rule->id,
                after: ['version' => $version, 'rule_type' => $rule->rule_type],
            );

            return $ruleVersion;
        });
    }

    public function evaluateForSubject(User $actor, Model $subject, ?AttendanceRecord $triggerRecord = null): void
    {
        $branchId = $this->branchIdForSubject($subject);
        if ($branchId === null) {
            return;
        }

        $rules = AttendanceExceptionRule::query()
            ->where('status', AttendanceExceptionRule::STATUS_PUBLISHED)
            ->where(function (Builder $query) use ($branchId): void {
                $query->whereNull('branch_id')->orWhere('branch_id', $branchId);
            })
            ->get();

        foreach ($rules as $rule) {
            $version = $rule->latestVersion();
            if ($version === null) {
                continue;
            }

            if ($rule->service_type !== null && $triggerRecord !== null && $rule->service_type !== $triggerRecord->service_type) {
                continue;
            }

            $match = $this->evaluateRule($rule, $version, $subject, $triggerRecord);
            $this->syncException($actor, $rule, $version, $subject, $match);
        }
    }

    /**
     * @return array{qualifies: bool, period_key: ?string, summary: ?string, evidence: array<string, mixed>}
     */
    private function evaluateRule(
        AttendanceExceptionRule $rule,
        AttendanceExceptionRuleVersion $version,
        Model $subject,
        ?AttendanceRecord $triggerRecord,
    ): array {
        return match ($rule->rule_type) {
            'consecutive_absence' => $this->evaluateConsecutiveAbsence($rule, $version, $subject),
            'declining_attendance' => $this->evaluateDecliningAttendance($rule, $version, $subject),
            'no_return_after_first_visit' => $this->evaluateNoReturnAfterFirstVisit($rule, $version, $subject),
            'repeated_team_absence' => $this->evaluateRepeatedTeamAbsence($rule, $version, $subject, $triggerRecord),
            default => ['qualifies' => false, 'period_key' => null, 'summary' => null, 'evidence' => []],
        };
    }

    /**
     * @return array{qualifies: bool, period_key: ?string, summary: ?string, evidence: array<string, mixed>}
     */
    private function evaluateConsecutiveAbsence(
        AttendanceExceptionRule $rule,
        AttendanceExceptionRuleVersion $version,
        Model $subject,
    ): array {
        if (! $subject instanceof Member) {
            return ['qualifies' => false, 'period_key' => null, 'summary' => null, 'evidence' => []];
        }

        $params = $version->parameters;
        $required = (int) ($params['consecutive_count'] ?? 3);
        $lookbackDays = (int) ($params['lookback_days'] ?? 90);
        $exclusions = $version->exclusions ?? [];

        $records = $this->scopedRecords($subject, $rule)
            ->where('gathering_date', '>=', now()->subDays($lookbackDays)->toDateString())
            ->orderByDesc('gathering_date')
            ->get();

        if ($this->shouldExcludeAll($records, $exclusions)) {
            return ['qualifies' => false, 'period_key' => null, 'summary' => null, 'evidence' => ['reason' => 'excluded']];
        }

        $eligible = $records->filter(fn (AttendanceRecord $record) => $this->isEligibleRecord($record, $exclusions));

        if ($eligible->count() < $required) {
            if (($exclusions['insufficient_history'] ?? true) && $eligible->count() < $required) {
                return ['qualifies' => false, 'period_key' => null, 'summary' => null, 'evidence' => ['reason' => 'insufficient_history']];
            }
        }

        $streak = 0;
        $streakDates = [];

        foreach ($eligible as $record) {
            if ($this->isAbsentForRule($record, $exclusions)) {
                $streak++;
                $streakDates[] = $record->gathering_date->toDateString();
            } else {
                break;
            }
        }

        if ($streak >= $required) {
            $periodKey = min($streakDates);

            return [
                'qualifies' => true,
                'period_key' => $periodKey,
                'summary' => "{$required} consecutive absences ending {$streakDates[0]}",
                'evidence' => ['absence_dates' => array_slice($streakDates, 0, $streak)],
            ];
        }

        return ['qualifies' => false, 'period_key' => null, 'summary' => null, 'evidence' => []];
    }

    /**
     * @return array{qualifies: bool, period_key: ?string, summary: ?string, evidence: array<string, mixed>}
     */
    private function evaluateDecliningAttendance(
        AttendanceExceptionRule $rule,
        AttendanceExceptionRuleVersion $version,
        Model $subject,
    ): array {
        if (! $subject instanceof Member) {
            return ['qualifies' => false, 'period_key' => null, 'summary' => null, 'evidence' => []];
        }

        $params = $version->parameters;
        $recentWeeks = (int) ($params['recent_weeks'] ?? 4);
        $priorWeeks = (int) ($params['prior_weeks'] ?? 4);
        $minDecline = (int) ($params['min_decline_percent'] ?? 30);
        $minRecords = (int) ($params['min_records'] ?? 4);
        $exclusions = $version->exclusions ?? [];

        $recentStart = now()->subWeeks($recentWeeks);
        $priorStart = $recentStart->copy()->subWeeks($priorWeeks);

        $records = $this->scopedRecords($subject, $rule)
            ->where('gathering_date', '>=', $priorStart->toDateString())
            ->get()
            ->filter(fn (AttendanceRecord $record) => $this->isEligibleRecord($record, $exclusions));

        if ($records->count() < $minRecords) {
            if ($exclusions['insufficient_history'] ?? true) {
                return ['qualifies' => false, 'period_key' => null, 'summary' => null, 'evidence' => ['reason' => 'insufficient_history']];
            }
        }

        $recent = $records->filter(fn (AttendanceRecord $r) => $r->gathering_date->gte($recentStart));
        $prior = $records->filter(fn (AttendanceRecord $r) => $r->gathering_date->lt($recentStart));

        if ($recent->isEmpty() || $prior->isEmpty()) {
            return ['qualifies' => false, 'period_key' => null, 'summary' => null, 'evidence' => ['reason' => 'insufficient_history']];
        }

        $recentRate = $this->attendanceRate($recent, $exclusions);
        $priorRate = $this->attendanceRate($prior, $exclusions);
        $decline = $priorRate > 0 ? (($priorRate - $recentRate) / $priorRate) * 100 : 0;

        if ($decline < $minDecline) {
            return ['qualifies' => false, 'period_key' => null, 'summary' => null, 'evidence' => []];
        }

        $periodKey = now()->format('Y-m');

        return [
            'qualifies' => true,
            'period_key' => $periodKey,
            'summary' => sprintf('Attendance declined %.0f%% (%d%% to %d%%)', $decline, $priorRate, $recentRate),
            'evidence' => [
                'prior_rate' => $priorRate,
                'recent_rate' => $recentRate,
                'decline_percent' => round($decline, 1),
            ],
        ];
    }

    /**
     * @return array{qualifies: bool, period_key: ?string, summary: ?string, evidence: array<string, mixed>}
     */
    private function evaluateNoReturnAfterFirstVisit(
        AttendanceExceptionRule $rule,
        AttendanceExceptionRuleVersion $version,
        Model $subject,
    ): array {
        if (! $subject instanceof Visitor) {
            return ['qualifies' => false, 'period_key' => null, 'summary' => null, 'evidence' => []];
        }

        $daysSinceFirst = (int) ($version->parameters['days_since_first'] ?? 14);
        $exclusions = $version->exclusions ?? [];

        $firstVisit = VisitorVisit::query()
            ->where('visitor_id', $subject->id)
            ->orderBy('visit_date')
            ->first();

        if ($firstVisit === null) {
            return ['qualifies' => false, 'period_key' => null, 'summary' => null, 'evidence' => ['reason' => 'insufficient_history']];
        }

        $firstDate = Carbon::parse($firstVisit->visit_date);
        $deadline = $firstDate->copy()->addDays($daysSinceFirst);

        if (now()->lt($deadline)) {
            return ['qualifies' => false, 'period_key' => null, 'summary' => null, 'evidence' => ['reason' => 'insufficient_history']];
        }

        $returnRecords = AttendanceRecord::query()
            ->where('subject_type', Visitor::class)
            ->where('subject_id', $subject->id)
            ->where('gathering_date', '>', $firstDate->toDateString())
            ->get()
            ->filter(fn (AttendanceRecord $record) => $this->isPresent($record, $exclusions));

        if ($returnRecords->isNotEmpty()) {
            return ['qualifies' => false, 'period_key' => null, 'summary' => null, 'evidence' => []];
        }

        $periodKey = $firstDate->toDateString();

        return [
            'qualifies' => true,
            'period_key' => $periodKey,
            'summary' => "No return within {$daysSinceFirst} days of first visit on {$periodKey}",
            'evidence' => ['first_visit_date' => $periodKey, 'days_elapsed' => $firstDate->diffInDays(now())],
        ];
    }

    /**
     * @return array{qualifies: bool, period_key: ?string, summary: ?string, evidence: array<string, mixed>}
     */
    private function evaluateRepeatedTeamAbsence(
        AttendanceExceptionRule $rule,
        AttendanceExceptionRuleVersion $version,
        Model $subject,
        ?AttendanceRecord $triggerRecord,
    ): array {
        if (! $subject instanceof Member || $triggerRecord === null || $triggerRecord->team_id === null) {
            return ['qualifies' => false, 'period_key' => null, 'summary' => null, 'evidence' => []];
        }

        $params = $version->parameters;
        $required = (int) ($params['absence_count'] ?? 3);
        $lookbackDays = (int) ($params['lookback_days'] ?? 30);
        $exclusions = $version->exclusions ?? [];

        $records = AttendanceRecord::query()
            ->where('subject_type', Member::class)
            ->where('subject_id', $subject->id)
            ->where('team_id', $triggerRecord->team_id)
            ->where('gathering_date', '>=', now()->subDays($lookbackDays)->toDateString())
            ->orderByDesc('gathering_date')
            ->get()
            ->filter(fn (AttendanceRecord $record) => $this->isEligibleRecord($record, $exclusions));

        $absences = $records->filter(fn (AttendanceRecord $record) => $this->isAbsentForRule($record, $exclusions));

        if ($absences->count() < $required) {
            if ($exclusions['insufficient_history'] ?? true) {
                return ['qualifies' => false, 'period_key' => null, 'summary' => null, 'evidence' => ['reason' => 'insufficient_history']];
            }
        }

        if ($absences->count() < $required) {
            return ['qualifies' => false, 'period_key' => null, 'summary' => null, 'evidence' => []];
        }

        $dates = $absences->take($required)->pluck('gathering_date')->map->toDateString()->values()->all();
        $periodKey = 'team:' . $triggerRecord->team_id . ':' . ($dates[0] ?? now()->toDateString());

        return [
            'qualifies' => true,
            'period_key' => $periodKey,
            'summary' => "{$required} team absences in {$lookbackDays} days",
            'evidence' => ['team_id' => $triggerRecord->team_id, 'absence_dates' => $dates],
        ];
    }

    /**
     * @param  array{qualifies: bool, period_key: ?string, summary: ?string, evidence: array<string, mixed>}  $match
     */
    private function syncException(
        User $actor,
        AttendanceExceptionRule $rule,
        AttendanceExceptionRuleVersion $version,
        Model $subject,
        array $match,
    ): void {
        $branchId = $this->branchIdForSubject($subject);
        if ($branchId === null || $match['period_key'] === null) {
            $this->reconcileOpenExceptions($actor, $rule, $subject, $version, $match);

            return;
        }

        $existing = AttendanceException::query()
            ->where('rule_id', $rule->id)
            ->where('subject_type', $subject::class)
            ->where('subject_id', $subject->id)
            ->where('period_key', $match['period_key'])
            ->first();

        if ($match['qualifies']) {
            if ($existing === null) {
                $exception = AttendanceException::create([
                    'rule_id' => $rule->id,
                    'rule_version_id' => $version->id,
                    'rule_version' => $version->version,
                    'rule_type' => $rule->rule_type,
                    'subject_type' => $subject::class,
                    'subject_id' => $subject->id,
                    'branch_id' => $branchId,
                    'service_type' => $rule->service_type,
                    'period_key' => $match['period_key'],
                    'status' => AttendanceException::STATUS_OPEN,
                    'summary' => $match['summary'],
                    'evidence' => $match['evidence'],
                    'detected_at' => now(),
                ]);

                $this->audit->record(
                    actor: $actor,
                    action: 'attendance.exception.detected',
                    category: AuditEvent::CATEGORY_BUSINESS,
                    module: 'attendance',
                    branchId: $branchId,
                    subjectType: $subject::class,
                    subjectId: $subject->id,
                    after: ['exception_id' => $exception->id, 'rule_type' => $rule->rule_type],
                    metadata: ['period_key' => $match['period_key']],
                );

                $assignee = $this->followUps->resolveDefaultAssignee($branchId);
                if ($assignee !== null) {
                    $this->followUps->createFromAttendanceException($actor, $exception, $assignee->id);
                }
            } elseif ($existing->status === AttendanceException::STATUS_OPEN) {
                $existing->update([
                    'summary' => $match['summary'],
                    'evidence' => $match['evidence'],
                    'rule_version_id' => $version->id,
                    'rule_version' => $version->version,
                ]);
            }

            return;
        }

        $this->reconcileOpenExceptions($actor, $rule, $subject, $version, $match, $existing);
    }

    /**
     * @param  array{qualifies: bool, period_key: ?string, summary: ?string, evidence: array<string, mixed>}  $match
     */
    private function reconcileOpenExceptions(
        User $actor,
        AttendanceExceptionRule $rule,
        Model $subject,
        AttendanceExceptionRuleVersion $version,
        array $match,
        ?AttendanceException $specific = null,
    ): void {
        $openExceptions = $specific !== null
            ? collect([$specific])->filter(fn (?AttendanceException $e) => $e !== null && $e->status === AttendanceException::STATUS_OPEN)
            : AttendanceException::query()
                ->where('rule_id', $rule->id)
                ->where('subject_type', $subject::class)
                ->where('subject_id', $subject->id)
                ->where('status', AttendanceException::STATUS_OPEN)
                ->get();

        foreach ($openExceptions as $exception) {
            if ($match['qualifies']) {
                continue;
            }

            $policy = $version->correction_policy ?? config('attendance_exceptions.default_correction_policy', 'resolve');

            if ($policy === 'retain') {
                continue;
            }

            $newStatus = $policy === 'flag_review'
                ? AttendanceException::STATUS_FLAGGED_REVIEW
                : AttendanceException::STATUS_RESOLVED;

            $exception->update([
                'status' => $newStatus,
                'resolution_reason' => $match['evidence']['reason'] ?? 'attendance_corrected',
                'resolved_at' => now(),
            ]);

            $this->audit->record(
                actor: $actor,
                action: 'attendance.exception.reconciled',
                category: AuditEvent::CATEGORY_BUSINESS,
                module: 'attendance',
                branchId: $exception->branch_id,
                subjectType: $subject::class,
                subjectId: $subject->id,
                before: ['status' => AttendanceException::STATUS_OPEN],
                after: ['status' => $newStatus, 'resolution_reason' => $exception->resolution_reason],
                metadata: ['exception_id' => $exception->id],
            );
        }
    }

    /** @param  Collection<int, AttendanceRecord>  $records */
    private function shouldExcludeAll(Collection $records, array $exclusions): bool
    {
        return $records->every(function (AttendanceRecord $record) use ($exclusions): bool {
            if (($exclusions['service_cancellation'] ?? true) && $record->service_cancelled) {
                return true;
            }
            if (($exclusions['branch_transfer'] ?? true) && $record->branch_transfer) {
                return true;
            }

            return false;
        });
    }

    private function isEligibleRecord(AttendanceRecord $record, array $exclusions): bool
    {
        if (($exclusions['service_cancellation'] ?? true) && $record->service_cancelled) {
            return false;
        }
        if (($exclusions['branch_transfer'] ?? true) && $record->branch_transfer) {
            return false;
        }

        return true;
    }

    private function isAbsentForRule(AttendanceRecord $record, array $exclusions): bool
    {
        if (($exclusions['excused_absence'] ?? true) && $record->status === 'excused') {
            return false;
        }

        return $record->status === 'absent';
    }

    private function isPresent(AttendanceRecord $record, array $exclusions): bool
    {
        if (($exclusions['online_attendance'] ?? true) && $record->status === 'online') {
            return true;
        }

        return in_array($record->status, config('attendance_exceptions.present_statuses', ['present', 'late', 'online']), true);
    }

    /** @param  Collection<int, AttendanceRecord>  $records */
    private function attendanceRate(Collection $records, array $exclusions): int
    {
        if ($records->isEmpty()) {
            return 0;
        }

        $present = $records->filter(fn (AttendanceRecord $record) => $this->isPresent($record, $exclusions))->count();

        return (int) round(($present / $records->count()) * 100);
    }

    /** @return Builder<AttendanceRecord> */
    private function scopedRecords(Model $subject, AttendanceExceptionRule $rule): Builder
    {
        $query = AttendanceRecord::query()
            ->where('subject_type', $subject::class)
            ->where('subject_id', $subject->id)
            ->whereNull('team_id');

        if ($rule->branch_id !== null) {
            $query->where('branch_id', $rule->branch_id);
        }

        if ($rule->service_type !== null) {
            $query->where('service_type', $rule->service_type);
        }

        return $query;
    }

    private function branchIdForSubject(Model $subject): ?int
    {
        return match (true) {
            $subject instanceof Member => $subject->branch_id,
            $subject instanceof Visitor => $subject->branch_id,
            default => null,
        };
    }

    public function formatRule(AttendanceExceptionRule $rule): array
    {
        return [
            'id' => $rule->id,
            'name' => $rule->name,
            'rule_type' => $rule->rule_type,
            'branch_id' => $rule->branch_id,
            'service_type' => $rule->service_type,
            'status' => $rule->status,
            'current_version' => $rule->current_version,
            'branch' => $rule->branch,
        ];
    }

    public function formatException(AttendanceException $exception): array
    {
        $subject = $exception->subject;
        $subjectName = null;
        if ($subject instanceof Member || $subject instanceof Visitor) {
            $subjectName = trim(($subject->first_name ?? '') . ' ' . ($subject->last_name ?? ''));
        }

        return [
            'id' => $exception->id,
            'rule_id' => $exception->rule_id,
            'rule_type' => $exception->rule_type,
            'rule_version' => $exception->rule_version,
            'subject_type' => $exception->subject_type,
            'subject_id' => $exception->subject_id,
            'subject_name' => $subjectName,
            'branch_id' => $exception->branch_id,
            'service_type' => $exception->service_type,
            'period_key' => $exception->period_key,
            'status' => $exception->status,
            'summary' => $exception->summary,
            'evidence' => $exception->evidence,
            'resolution_reason' => $exception->resolution_reason,
            'detected_at' => $exception->detected_at?->toIso8601String(),
            'resolved_at' => $exception->resolved_at?->toIso8601String(),
            'rule' => $exception->relationLoaded('rule') ? $exception->rule : null,
            'branch' => $exception->relationLoaded('branch') ? $exception->branch : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateRulePayload(array $payload): array
    {
        return validator($payload, [
            'name' => ['required', 'string', 'max:255'],
            'rule_type' => ['required', 'string', 'in:' . implode(',', array_keys(config('attendance_exceptions.rule_types', [])))],
            'branch_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'service_type' => ['nullable', 'string', 'max:64'],
        ])->validate();
    }

    private function assertCan(User $actor, string $action): void
    {
        if (! $this->authorization->allows($actor, $action)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function assertRuleInScope(User $actor, AttendanceExceptionRule $rule): void
    {
        if ($rule->branch_id === null || $actor->isChurchWide()) {
            return;
        }

        try {
            BranchScope::for($actor)->assertIncludes($rule->branch_id);
        } catch (BranchScopeException) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function assertExceptionInScope(User $actor, AttendanceException $exception): void
    {
        if ($actor->isChurchWide()) {
            return;
        }

        try {
            BranchScope::for($actor)->assertIncludes($exception->branch_id);
        } catch (BranchScopeException) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    /** @param  Builder<AttendanceExceptionRule>  $query */
    private function applyBranchScope(Builder $query, User $actor): void
    {
        if ($actor->isChurchWide()) {
            return;
        }

        try {
            $scope = BranchScope::for($actor);
            $branchIds = $scope->subtreeIds((int) $scope->branchId());
            $query->where(function (Builder $q) use ($branchIds): void {
                $q->whereNull('branch_id')->orWhereIn('branch_id', $branchIds);
            });
        } catch (BranchScopeException) {
            $query->whereRaw('1 = 0');
        }
    }

    /** @param  Builder<AttendanceException>  $query */
    private function applyExceptionBranchScope(Builder $query, User $actor): void
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
