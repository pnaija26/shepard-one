<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Member;
use App\Models\MemberNotification;
use App\Models\ServiceTeam;
use App\Models\TeamReport;
use App\Models\TeamReportVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Story 5.6: submit and approve versioned team reports.
 */
class TeamReportService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
        private TeamReportFormService $reportForms,
    ) {
    }

    /**
     * @return Collection<int, TeamReport>
     */
    public function listReports(User $actor, ServiceTeam $team, array $filters = []): Collection
    {
        $this->assertCan($actor, 'teams.reports.read');
        $this->assertTeamInScope($actor, $team);

        $query = TeamReport::query()
            ->where('service_team_id', $team->id)
            ->orderByDesc('reporting_period_start');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->limit(100)->get();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createDraft(User $actor, ServiceTeam $team, array $payload): TeamReport
    {
        $this->assertCan($actor, 'teams.reports.submit');
        $this->assertTeamInScope($actor, $team);
        $this->assertTeamReportable($team);

        $validated = $this->validatePeriodPayload($payload);

        $existing = TeamReport::query()
            ->where('service_team_id', $team->id)
            ->where('reporting_period_start', $validated['reporting_period_start'])
            ->where('reporting_period_end', $validated['reporting_period_end'])
            ->whereIn('status', [TeamReport::STATUS_SUBMITTED, TeamReport::STATUS_APPROVED])
            ->exists();

        if ($existing) {
            throw ValidationException::withMessages([
                'reporting_period_start' => ['A report has already been submitted for this reporting period.'],
            ]);
        }

        return DB::transaction(function () use ($actor, $team, $validated): TeamReport {
            $formVersion = $this->reportForms->activeFormForTeam($team);
            $templateSnapshot = $formVersion !== null
                ? [
                    'source' => 'team_report_form',
                    'form_id' => $formVersion->team_report_form_id,
                    'form_version' => $formVersion->version,
                    'fields' => $formVersion->fields ?? [],
                ]
                : array_merge($team->reporting_template ?? [], ['source' => 'legacy_team_template']);

            $report = TeamReport::create([
                'service_team_id' => $team->id,
                'branch_id' => $team->branch_id,
                'team_report_form_id' => $formVersion?->team_report_form_id,
                'team_report_form_version' => $formVersion?->version,
                'reporting_period_start' => $validated['reporting_period_start'],
                'reporting_period_end' => $validated['reporting_period_end'],
                'template_version' => $team->current_config_version,
                'template_snapshot' => $templateSnapshot,
                'status' => TeamReport::STATUS_DRAFT,
                'version' => 1,
                'field_values' => [],
                'attachments' => [],
                'incidents' => [],
                'concerns' => null,
                'results' => [],
                'recommendations' => [],
                'is_locked' => false,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->recordVersion($actor, $report, TeamReportVersion::CHANGE_DRAFT_SAVED, 'Draft created.');
            $this->audit($actor, 'team_report.created', $report);

            return $report;
        });
    }

    public function showReport(User $actor, TeamReport $report): TeamReport
    {
        $this->assertCan($actor, 'teams.reports.read');
        $this->assertReportInScope($actor, $report);

        return $report->load(['team:id,name', 'versions' => fn ($q) => $q->orderByDesc('version')->limit(10)]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function saveDraft(User $actor, TeamReport $report, array $payload): TeamReport
    {
        $this->assertCan($actor, 'teams.reports.submit');
        $this->assertReportInScope($actor, $report);
        $this->assertEditableByAuthor($actor, $report);

        $validated = $this->validateContentPayload($payload, false);
        $this->validateReportFieldValues($report, $validated['field_values'] ?? [], false);

        return DB::transaction(function () use ($actor, $report, $validated): TeamReport {
            $report->fill(array_merge($validated, [
                'version' => $report->version + 1,
                'updated_by' => $actor->id,
            ]));
            $report->save();

            $this->recordVersion($actor, $report, TeamReportVersion::CHANGE_DRAFT_SAVED, 'Draft saved.');
            $this->audit($actor, 'team_report.draft_saved', $report);

            return $report->fresh(['team:id,name']);
        });
    }

    public function submitReport(User $actor, TeamReport $report): TeamReport
    {
        $this->assertCan($actor, 'teams.reports.submit');
        $this->assertReportInScope($actor, $report);
        $this->assertEditableByAuthor($actor, $report);

        if ($report->status === TeamReport::STATUS_SUBMITTED) {
            throw ValidationException::withMessages(['status' => ['This report has already been submitted.']]);
        }

        $this->assertRequiredFieldsPresent($report);

        return DB::transaction(function () use ($actor, $report): TeamReport {
            $report->update([
                'status' => TeamReport::STATUS_SUBMITTED,
                'is_locked' => true,
                'submitted_by' => $actor->id,
                'submitted_at' => now(),
                'version' => $report->version + 1,
                'updated_by' => $actor->id,
                'review_decision' => null,
                'review_comments' => null,
                'reviewed_by' => null,
                'reviewed_at' => null,
            ]);

            $this->recordVersion($actor, $report, TeamReportVersion::CHANGE_SUBMITTED, 'Report submitted for review.');
            $this->notifyReviewers($report);
            $this->audit($actor, 'team_report.submitted', $report);

            return $report->fresh(['team:id,name']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function reviewReport(User $actor, TeamReport $report, array $payload): TeamReport
    {
        $this->assertCan($actor, 'teams.reports.review');
        $this->assertReportInScope($actor, $report);

        if ($report->status !== TeamReport::STATUS_SUBMITTED) {
            throw ValidationException::withMessages(['status' => ['Only submitted reports can be reviewed.']]);
        }

        $validated = validator($payload, [
            'decision' => ['required', 'string', 'in:' . implode(',', config('team_reports.review_decisions', []))],
            'comments' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        if ($validated['decision'] === TeamReport::DECISION_RETURNED && empty($validated['comments'])) {
            throw ValidationException::withMessages(['comments' => ['Comments are required when returning a report.']]);
        }

        return DB::transaction(function () use ($actor, $report, $validated): TeamReport {
            $status = $validated['decision'] === TeamReport::DECISION_APPROVED
                ? TeamReport::STATUS_APPROVED
                : TeamReport::STATUS_RETURNED;

            $report->update([
                'status' => $status,
                'is_locked' => $status === TeamReport::STATUS_APPROVED,
                'review_decision' => $validated['decision'],
                'review_comments' => $validated['comments'] ?? null,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'version' => $report->version + 1,
                'updated_by' => $actor->id,
            ]);

            $changeType = $status === TeamReport::STATUS_APPROVED
                ? TeamReportVersion::CHANGE_APPROVED
                : TeamReportVersion::CHANGE_RETURNED;

            $this->recordVersion($actor, $report, $changeType, $validated['comments'] ?? null);
            $this->notifyAuthor($report, $validated['decision'], $validated['comments'] ?? null);
            $this->audit($actor, 'team_report.' . $validated['decision'], $report);

            return $report->fresh(['team:id,name']);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function consolidatedMetrics(User $actor, ServiceTeam $team): array
    {
        $this->assertCan($actor, 'teams.reports.read');
        $this->assertTeamInScope($actor, $team);

        $approved = TeamReport::query()
            ->where('service_team_id', $team->id)
            ->where('status', TeamReport::STATUS_APPROVED)
            ->orderByDesc('reporting_period_end')
            ->get();

        $metrics = [
            'approved_reports' => $approved->count(),
            'attendance_totals' => 0,
            'incident_totals' => 0,
            'reports' => [],
        ];

        foreach ($approved as $report) {
            $attendance = (int) ($report->results['attendance_count'] ?? $report->field_values['attendance'] ?? 0);
            $incidents = is_array($report->incidents) ? count($report->incidents) : 0;

            $metrics['attendance_totals'] += $attendance;
            $metrics['incident_totals'] += $incidents;
            $metrics['reports'][] = [
                'id' => $report->id,
                'period_start' => $report->reporting_period_start?->toDateString(),
                'period_end' => $report->reporting_period_end?->toDateString(),
                'attendance_count' => $attendance,
                'incident_count' => $incidents,
            ];
        }

        $allReports = TeamReport::query()->where('service_team_id', $team->id)->count();
        $metrics['pending_in_consolidated_metrics'] = $allReports - $approved->count();

        return $metrics;
    }

    public function formatReport(TeamReport $report): array
    {
        return [
            'id' => $report->id,
            'service_team_id' => $report->service_team_id,
            'status' => $report->status,
            'version' => $report->version,
            'template_version' => $report->template_version,
            'team_report_form_id' => $report->team_report_form_id,
            'team_report_form_version' => $report->team_report_form_version,
            'template_snapshot' => $report->template_snapshot ?? [],
            'reporting_period_start' => $report->reporting_period_start?->toDateString(),
            'reporting_period_end' => $report->reporting_period_end?->toDateString(),
            'field_values' => $report->field_values ?? [],
            'attachments' => $report->attachments ?? [],
            'incidents' => $report->incidents ?? [],
            'concerns' => $report->concerns,
            'results' => $report->results ?? [],
            'recommendations' => $report->recommendations ?? [],
            'is_locked' => $report->is_locked,
            'is_editable' => in_array($report->status, [TeamReport::STATUS_DRAFT, TeamReport::STATUS_RETURNED], true),
            'submitted_at' => $report->submitted_at?->toIso8601String(),
            'reviewed_at' => $report->reviewed_at?->toIso8601String(),
            'review_decision' => $report->review_decision,
            'review_comments' => $report->review_comments,
            'team' => $report->relationLoaded('team') && $report->team
                ? ['id' => $report->team->id, 'name' => $report->team->name]
                : null,
        ];
    }

    private function assertRequiredFieldsPresent(TeamReport $report): void
    {
        $values = $report->field_values ?? [];
        $fields = $report->template_snapshot['fields'] ?? [];

        if ($this->usesConfiguredForm($report)) {
            $this->reportForms->validateFieldValues($fields, $values, true);

            return;
        }

        foreach ($fields as $field) {
            if (! is_string($field)) {
                continue;
            }

            if (! array_key_exists($field, $values) || $values[$field] === null || $values[$field] === '') {
                throw ValidationException::withMessages([
                    "field_values.{$field}" => ["The {$field} field is required before submission."],
                ]);
            }
        }

        if (($report->results ?? []) === []) {
            throw ValidationException::withMessages(['results' => ['Results are required before submission.']]);
        }
    }

    /**
     * @param  array<string, mixed>  $fieldValues
     */
    private function validateReportFieldValues(TeamReport $report, array $fieldValues, bool $requireAll): void
    {
        if (! $this->usesConfiguredForm($report)) {
            return;
        }

        $this->reportForms->validateFieldValues(
            $report->template_snapshot['fields'] ?? [],
            $fieldValues,
            $requireAll,
        );
    }

    private function usesConfiguredForm(TeamReport $report): bool
    {
        return ($report->template_snapshot['source'] ?? null) === 'team_report_form';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateContentPayload(array $payload, bool $requireAll): array
    {
        return validator($payload, [
            'field_values' => [$requireAll ? 'required' : 'nullable', 'array'],
            'attachments' => ['nullable', 'array', 'max:' . (int) config('team_reports.attachment_constraints.max_count', 10)],
            'attachments.*.label' => ['required_with:attachments', 'string', 'max:160'],
            'attachments.*.type' => ['required_with:attachments', 'string', 'in:' . implode(',', config('team_reports.attachment_constraints.allowed_types', []))],
            'attachments.*.reference' => ['required_with:attachments', 'string', 'max:500'],
            'incidents' => ['nullable', 'array'],
            'incidents.*.summary' => ['required_with:incidents', 'string', 'max:500'],
            'concerns' => ['nullable', 'string', 'max:5000'],
            'results' => ['nullable', 'array'],
            'recommendations' => ['nullable', 'array'],
            'recommendations.*' => ['string', 'max:500'],
        ])->validate();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validatePeriodPayload(array $payload): array
    {
        return validator($payload, [
            'reporting_period_start' => ['required', 'date'],
            'reporting_period_end' => ['required', 'date', 'after_or_equal:reporting_period_start'],
        ])->validate();
    }

    private function recordVersion(User $actor, TeamReport $report, string $changeType, ?string $comments = null): void
    {
        TeamReportVersion::create([
            'team_report_id' => $report->id,
            'version' => $report->version,
            'change_type' => $changeType,
            'snapshot' => [
                'status' => $report->status,
                'field_values' => $report->field_values,
                'attachments' => $report->attachments,
                'incidents' => $report->incidents,
                'concerns' => $report->concerns,
                'results' => $report->results,
                'recommendations' => $report->recommendations,
            ],
            'comments' => $comments,
            'actor_id' => $actor->id,
            'created_at' => now(),
        ]);
    }

    private function notifyReviewers(TeamReport $report): void
    {
        $team = $report->team ?? ServiceTeam::query()->find($report->service_team_id);
        if ($team === null) {
            return;
        }

        foreach ($team->approval_hierarchy['levels'] ?? [] as $level) {
            $userId = $level['user_id'] ?? null;
            if ($userId === null) {
                continue;
            }

            $member = Member::query()->where('user_id', $userId)->first();
            if ($member === null) {
                continue;
            }

            MemberNotification::create([
                'member_id' => $member->id,
                'user_id' => $userId,
                'type' => 'team_report.submitted',
                'message' => 'A team report for "' . $team->name . '" was submitted for your review.',
                'metadata' => [
                    'team_report_id' => $report->id,
                    'service_team_id' => $team->id,
                ],
            ]);
        }
    }

    private function notifyAuthor(TeamReport $report, string $decision, ?string $comments): void
    {
        $submitterId = $report->submitted_by ?? $report->created_by;
        if ($submitterId === null) {
            return;
        }

        $member = Member::query()->where('user_id', $submitterId)->first();
        if ($member === null) {
            return;
        }

        MemberNotification::create([
            'member_id' => $member->id,
            'user_id' => $submitterId,
            'type' => 'team_report.reviewed',
            'message' => 'Your team report was ' . $decision . ($comments ? ': ' . $comments : '.'),
            'metadata' => [
                'team_report_id' => $report->id,
                'decision' => $decision,
            ],
        ]);
    }

    private function assertTeamReportable(ServiceTeam $team): void
    {
        if ($team->status !== ServiceTeam::STATUS_ACTIVE) {
            throw ValidationException::withMessages(['team' => ['Reports can only be created for active teams.']]);
        }
    }

    private function assertEditableByAuthor(User $actor, TeamReport $report): void
    {
        if (! in_array($report->status, [TeamReport::STATUS_DRAFT, TeamReport::STATUS_RETURNED], true)) {
            throw ValidationException::withMessages(['status' => ['Submitted reports are read-only unless returned by a reviewer.']]);
        }

        if ($report->is_locked) {
            throw ValidationException::withMessages(['status' => ['This report is locked and cannot be edited.']]);
        }
    }

    private function assertCan(User $actor, string $action): void
    {
        if (! $this->authorization->allows($actor, $action)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function assertTeamInScope(User $actor, ServiceTeam $team): void
    {
        if ($actor->isChurchWide()) {
            return;
        }

        try {
            BranchScope::for($actor)->assertIncludes((int) $team->branch_id);
        } catch (BranchScopeException) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function assertReportInScope(User $actor, TeamReport $report): void
    {
        if ($actor->isChurchWide()) {
            return;
        }

        try {
            BranchScope::for($actor)->assertIncludes((int) $report->branch_id);
        } catch (BranchScopeException) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function audit(User $actor, string $action, TeamReport $report): void
    {
        $this->audit->record(
            actor: $actor,
            action: $action,
            category: AuditEvent::CATEGORY_BUSINESS,
            module: 'teams',
            branchId: $report->branch_id,
            subjectType: TeamReport::class,
            subjectId: $report->id,
            before: null,
            after: [
                'service_team_id' => $report->service_team_id,
                'status' => $report->status,
                'version' => $report->version,
            ],
        );
    }
}
