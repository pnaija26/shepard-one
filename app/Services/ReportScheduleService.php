<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\CustomReport;
use App\Models\Organization;
use App\Models\ReportSchedule;
use App\Models\ReportScheduleDelivery;
use App\Models\ReportScheduleRun;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Story 13.5: schedule and distribute authorized report results.
 */
class ReportScheduleService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
        private ReportExportService $exports,
        private StandardReportService $standardReports,
        private CustomReportService $customReports,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function catalog(User $actor): array
    {
        $this->assertCan($actor, 'reports.schedule.read');

        return [
            'recurrences' => config('report_schedules.recurrences', []),
            'delivery_channels' => config('report_schedules.delivery_channels', []),
            'formats' => array_keys(config('report_exports.formats', [])),
            'classifications' => config('report_exports.classifications', []),
            'max_recipients' => config('report_schedules.max_recipients', 10),
            'default_timezone' => config('report_schedules.default_timezone', 'UTC'),
        ];
    }

    /**
     * @return Collection<int, ReportSchedule>
     */
    public function list(User $actor): Collection
    {
        $this->assertCan($actor, 'reports.schedule.read');

        $query = ReportSchedule::query()->orderBy('name');
        $this->applyScheduleScope($query, $actor);

        return $query->limit(100)->get();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(User $actor, array $payload): ReportSchedule
    {
        $this->assertCan($actor, 'reports.schedule.manage');

        $validated = $this->validateSchedulePayload($payload);
        $this->assertOwnerCanRunReport($actor, $validated);
        $this->assertRecipientPolicy($validated);
        $this->assertRecipientsEligible($actor, $validated);

        $nextRun = $this->calculateNextRun(
            now(),
            $validated['recurrence'],
            $validated['recurrence_params'] ?? [],
            $validated['timezone'],
        );

        $schedule = ReportSchedule::create([
            'reference' => (string) Str::uuid(),
            'name' => $validated['name'],
            'owner_id' => $actor->id,
            'branch_id' => $validated['branch_id'] ?? $actor->branch_id,
            'report_type' => $validated['report_type'],
            'report_key' => $validated['report_key'] ?? null,
            'custom_report_id' => $validated['custom_report_id'] ?? null,
            'format' => $validated['format'],
            'delivery_channel' => $validated['delivery_channel'],
            'timezone' => $validated['timezone'],
            'recurrence' => $validated['recurrence'],
            'recurrence_params' => $validated['recurrence_params'] ?? [],
            'filters' => $validated['filters'] ?? [],
            'classification' => $validated['classification'] ?? 'internal',
            'recipient_user_ids' => array_values(array_unique($validated['recipient_user_ids'])),
            'status' => ReportSchedule::STATUS_ACTIVE,
            'next_run_at' => $nextRun,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        $this->audit($actor, 'report_schedule.created', $schedule, [
            'next_run_at' => $nextRun->toIso8601String(),
            'recipient_count' => count($schedule->recipient_user_ids ?? []),
        ]);

        return $schedule;
    }

    public function show(User $actor, ReportSchedule $schedule): ReportSchedule
    {
        $this->assertCan($actor, 'reports.schedule.read');
        $this->assertInScope($actor, $schedule);

        return $schedule->load([
            'runs' => fn ($q) => $q->orderByDesc('scheduled_for')->limit(10),
            'runs.deliveries',
        ]);
    }

    /**
     * @return array{processed: int, completed: int, failed: int, skipped: int}
     */
    public function processDueSchedules(): array
    {
        $processed = 0;
        $completed = 0;
        $failed = 0;
        $skipped = 0;

        $due = ReportSchedule::query()
            ->where('status', ReportSchedule::STATUS_ACTIVE)
            ->where('next_run_at', '<=', now())
            ->orderBy('next_run_at')
            ->limit(50)
            ->get();

        foreach ($due as $schedule) {
            $processed++;
            $result = $this->executeDueRun($schedule);
            match ($result) {
                'completed' => $completed++,
                'failed' => $failed++,
                default => $skipped++,
            };
        }

        return compact('processed', 'completed', 'failed', 'skipped');
    }

    public function executeDueRun(ReportSchedule $schedule): string
    {
        $scheduledFor = $schedule->next_run_at?->copy() ?? now();
        $runKey = $schedule->id . ':' . $scheduledFor->timestamp;

        $existing = ReportScheduleRun::query()->where('run_key', $runKey)->first();
        if ($existing !== null) {
            if ($existing->status === ReportScheduleRun::STATUS_COMPLETED) {
                $this->advanceSchedule($schedule, $scheduledFor);

                return 'skipped';
            }

            if (in_array($existing->status, [ReportScheduleRun::STATUS_FAILED, ReportScheduleRun::STATUS_BLOCKED], true)) {
                $this->advanceSchedule($schedule, $scheduledFor);

                return 'failed';
            }

            return 'skipped';
        }

        $owner = User::query()->find($schedule->owner_id);
        if ($owner === null) {
            return 'failed';
        }

        $run = ReportScheduleRun::create([
            'report_schedule_id' => $schedule->id,
            'run_key' => $runKey,
            'scheduled_for' => $scheduledFor,
            'status' => ReportScheduleRun::STATUS_GENERATING,
            'recipient_snapshot' => $schedule->recipient_user_ids,
        ]);

        if (! $this->ownerCanRunReport($owner, $schedule)) {
            $run->update([
                'status' => ReportScheduleRun::STATUS_BLOCKED,
                'failure_reason' => 'Schedule owner no longer has permission to generate this report.',
                'generation_checked_at' => now(),
                'failed_at' => now(),
            ]);
            $this->audit($owner, 'report_schedule.run_blocked', $schedule, ['run_id' => $run->id]);
            $this->advanceSchedule($schedule, $scheduledFor);

            return 'failed';
        }

        try {
            $generated = $this->exports->generateDistributionExport($owner, $this->scheduleExportPayload($schedule));
            $run->update([
                'report_export_id' => $generated['export']->id,
                'generation_checked_at' => now(),
                'status' => ReportScheduleRun::STATUS_DELIVERING,
            ]);
        } catch (\Throwable $exception) {
            $run->update([
                'status' => ReportScheduleRun::STATUS_FAILED,
                'failure_reason' => 'Report generation failed.',
                'generation_checked_at' => now(),
                'failed_at' => now(),
            ]);
            $this->audit($owner, 'report_schedule.generation_failed', $schedule, ['run_id' => $run->id]);
            $this->advanceSchedule($schedule, $scheduledFor);

            return 'failed';
        }

        $deliveredCount = 0;
        $blockedCount = 0;

        foreach ($schedule->recipient_user_ids ?? [] as $recipientId) {
            $recipient = User::query()->find((int) $recipientId);
            if ($recipient === null) {
                ReportScheduleDelivery::create([
                    'report_schedule_run_id' => $run->id,
                    'recipient_user_id' => (int) $recipientId,
                    'status' => ReportScheduleDelivery::STATUS_BLOCKED,
                    'channel' => $schedule->delivery_channel,
                    'failure_reason' => 'Recipient account not found.',
                ]);
                $blockedCount++;

                continue;
            }

            if (! $this->recipientCanReceive($recipient, $schedule)) {
                ReportScheduleDelivery::create([
                    'report_schedule_run_id' => $run->id,
                    'recipient_user_id' => $recipient->id,
                    'status' => ReportScheduleDelivery::STATUS_BLOCKED,
                    'channel' => $schedule->delivery_channel,
                    'failure_reason' => 'Recipient no longer has permission to receive this report.',
                ]);
                $blockedCount++;

                continue;
            }

            ReportScheduleDelivery::create([
                'report_schedule_run_id' => $run->id,
                'recipient_user_id' => $recipient->id,
                'status' => ReportScheduleDelivery::STATUS_DELIVERED,
                'channel' => $schedule->delivery_channel,
                'delivered_at' => now(),
                'metadata' => [
                    'export_reference' => $generated['export']->reference,
                    'classification' => $schedule->classification,
                    'filters' => $schedule->filters,
                    'download_expires_at' => $generated['export']->download_expires_at?->toIso8601String(),
                    'delivery_hint' => $schedule->delivery_channel === 'email'
                        ? 'Secure download link prepared for email delivery.'
                        : 'Report available in scheduled distribution inbox.',
                ],
            ]);
            $deliveredCount++;
        }

        $run->update([
            'delivery_checked_at' => now(),
            'status' => $deliveredCount > 0 ? ReportScheduleRun::STATUS_COMPLETED : ReportScheduleRun::STATUS_BLOCKED,
            'completed_at' => $deliveredCount > 0 ? now() : null,
            'failed_at' => $deliveredCount > 0 ? null : now(),
            'failure_reason' => $deliveredCount > 0 ? null : 'All recipients were blocked or ineligible.',
        ]);

        $this->audit($owner, 'report_schedule.run_completed', $schedule, [
            'run_id' => $run->id,
            'delivered' => $deliveredCount,
            'blocked' => $blockedCount,
            'recipient_ids' => $schedule->recipient_user_ids,
        ]);

        $this->advanceSchedule($schedule, $scheduledFor);

        return $deliveredCount > 0 ? 'completed' : 'failed';
    }

    public function format(ReportSchedule $schedule): array
    {
        return [
            'id' => $schedule->id,
            'reference' => $schedule->reference,
            'name' => $schedule->name,
            'owner_id' => $schedule->owner_id,
            'branch_id' => $schedule->branch_id,
            'report_type' => $schedule->report_type,
            'report_key' => $schedule->report_key,
            'custom_report_id' => $schedule->custom_report_id,
            'format' => $schedule->format,
            'delivery_channel' => $schedule->delivery_channel,
            'timezone' => $schedule->timezone,
            'recurrence' => $schedule->recurrence,
            'recurrence_params' => $schedule->recurrence_params,
            'filters' => $schedule->filters,
            'classification' => $schedule->classification,
            'recipient_user_ids' => $schedule->recipient_user_ids,
            'status' => $schedule->status,
            'next_run_at' => $schedule->next_run_at?->toIso8601String(),
            'last_run_at' => $schedule->last_run_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateSchedulePayload(array $payload): array
    {
        return validator($payload, [
            'name' => ['required', 'string', 'max:120'],
            'branch_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'report_type' => ['required', 'string', 'in:standard,custom'],
            'report_key' => ['required_if:report_type,standard', 'nullable', 'string'],
            'custom_report_id' => ['required_if:report_type,custom', 'nullable', 'integer', 'exists:custom_reports,id'],
            'format' => ['required', 'string', 'in:' . implode(',', array_keys(config('report_exports.formats', [])))],
            'delivery_channel' => ['required', 'string', 'in:' . implode(',', config('report_schedules.delivery_channels', []))],
            'timezone' => ['required', 'string', 'max:64'],
            'recurrence' => ['required', 'string', 'in:' . implode(',', config('report_schedules.recurrences', []))],
            'recurrence_params' => ['nullable', 'array'],
            'recurrence_params.hour' => ['nullable', 'integer', 'min:0', 'max:23'],
            'recurrence_params.minute' => ['nullable', 'integer', 'min:0', 'max:59'],
            'recurrence_params.day_of_week' => ['nullable', 'integer', 'min:0', 'max:6'],
            'recurrence_params.day_of_month' => ['nullable', 'integer', 'min:1', 'max:28'],
            'filters' => ['nullable', 'array'],
            'classification' => ['nullable', 'string', 'in:' . implode(',', config('report_exports.classifications', []))],
            'recipient_user_ids' => ['required', 'array', 'min:1'],
            'recipient_user_ids.*' => ['integer', 'exists:users,id'],
        ])->validate();
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function assertOwnerCanRunReport(User $actor, array $validated): void
    {
        if (! $this->ownerPayloadCanRunReport($actor, $validated)) {
            throw new ReportScheduleException(
                'You do not have permission to schedule this report.',
                'owner_permission_denied',
                403,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function assertRecipientPolicy(array $validated): void
    {
        $count = count($validated['recipient_user_ids'] ?? []);
        $max = (int) config('report_schedules.max_recipients', 10);

        if ($count > $max) {
            throw new ReportScheduleException(
                'Recipient list is too broad for scheduled distribution.',
                'distribution_too_broad',
                422,
                ['max_recipients' => $max, 'requested' => $count],
            );
        }

        $classification = $validated['classification'] ?? 'internal';
        $limit = match ($classification) {
            'confidential' => (int) config('report_schedules.confidential_max_recipients', 3),
            'restricted' => (int) config('report_schedules.restricted_max_recipients', 5),
            default => $max,
        };

        if ($count > $limit) {
            throw new ReportScheduleException(
                'Recipient list exceeds the allowed limit for this data classification.',
                'classification_recipient_limit',
                422,
                ['classification' => $classification, 'max_recipients' => $limit],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function assertRecipientsEligible(User $actor, array $validated): void
    {
        $errors = [];

        foreach ($validated['recipient_user_ids'] as $index => $recipientId) {
            $recipient = User::query()->find((int) $recipientId);
            if ($recipient === null) {
                $errors[] = ['field' => "recipient_user_ids.{$index}", 'message' => 'Recipient not found.'];

                continue;
            }

            if ((int) $recipient->id === (int) $actor->id) {
                continue;
            }

            if (! $this->recipientCanReceiveForPayload($recipient, $validated)) {
                $errors[] = [
                    'field' => "recipient_user_ids.{$index}",
                    'message' => 'Recipient is not eligible to receive this scheduled report.',
                ];
            }
        }

        if ($errors !== []) {
            throw new ReportScheduleException(
                'One or more recipients are not eligible for this schedule.',
                'invalid_recipients',
                422,
                ['errors' => $errors],
            );
        }
    }

    private function ownerCanRunReport(User $owner, ReportSchedule $schedule): bool
    {
        return $this->ownerPayloadCanRunReport($owner, [
            'report_type' => $schedule->report_type,
            'report_key' => $schedule->report_key,
            'custom_report_id' => $schedule->custom_report_id,
            'filters' => $schedule->filters ?? [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function ownerPayloadCanRunReport(User $owner, array $payload): bool
    {
        foreach (config('report_schedules.recipient_required_permissions.' . $payload['report_type'], []) as $permission) {
            if (! $this->allows($owner, $permission)) {
                return false;
            }
        }

        try {
            if ($payload['report_type'] === 'standard') {
                $this->standardReports->run($owner, (string) $payload['report_key'], $payload['filters'] ?? []);
            } else {
                $report = CustomReport::query()->findOrFail((int) $payload['custom_report_id']);
                $this->customReports->run($owner, $report, $payload['filters'] ?? []);
            }
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    private function recipientCanReceive(User $recipient, ReportSchedule $schedule): bool
    {
        return $this->recipientCanReceiveForPayload($recipient, [
            'report_type' => $schedule->report_type,
            'report_key' => $schedule->report_key,
            'custom_report_id' => $schedule->custom_report_id,
            'filters' => $schedule->filters ?? [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recipientCanReceiveForPayload(User $recipient, array $payload): bool
    {
        foreach (config('report_schedules.recipient_required_permissions.' . $payload['report_type'], []) as $permission) {
            if (! $this->allows($recipient, $permission)) {
                return false;
            }
        }

        if ($payload['report_type'] === 'standard') {
            $meta = config('standard_reports.reports.' . ($payload['report_key'] ?? ''));
            if ($meta === null) {
                return false;
            }

            foreach ($meta['required_permissions'] ?? [] as $permission) {
                if (! $this->allows($recipient, $permission)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function scheduleExportPayload(ReportSchedule $schedule): array
    {
        return [
            'report_type' => $schedule->report_type,
            'report_key' => $schedule->report_key,
            'custom_report_id' => $schedule->custom_report_id,
            'format' => $schedule->format === 'email' ? 'csv' : $schedule->format,
            'classification' => $schedule->classification,
            'filters' => $schedule->filters ?? [],
        ];
    }

    private function advanceSchedule(ReportSchedule $schedule, Carbon $scheduledFor): void
    {
        $schedule->update([
            'last_run_at' => $scheduledFor,
            'next_run_at' => $this->calculateNextRun(
                $scheduledFor,
                $schedule->recurrence,
                $schedule->recurrence_params ?? [],
                $schedule->timezone,
            ),
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function calculateNextRun(Carbon $from, string $recurrence, array $params, string $timezone): Carbon
    {
        $hour = (int) ($params['hour'] ?? 8);
        $minute = (int) ($params['minute'] ?? 0);
        $base = $from->copy()->timezone($timezone);

        return match ($recurrence) {
            'weekly' => $base->addWeek()->setTime($hour, $minute),
            'monthly' => $base->addMonthNoOverflow()
                ->day(min((int) ($params['day_of_month'] ?? $base->day), 28))
                ->setTime($hour, $minute),
            default => $base->addDay()->setTime($hour, $minute),
        };
    }

    private function applyScheduleScope(Builder $query, User $actor): void
    {
        if (BranchScope::for($actor)->isChurchWide()) {
            return;
        }

        $ids = Organization::query()
            ->where('type', 'branch')
            ->where('is_active', true);
        BranchScope::for($actor)->applyToQuery($ids);
        $branchIds = $ids->pluck('id')->all();

        $query->where(function (Builder $inner) use ($actor, $branchIds): void {
            $inner->where('owner_id', $actor->id)
                ->orWhereIn('branch_id', $branchIds ?: [0]);
        });
    }

    private function assertInScope(User $actor, ReportSchedule $schedule): void
    {
        if ((int) $schedule->owner_id === (int) $actor->id) {
            return;
        }

        if ($schedule->branch_id === null) {
            throw new AuthorizationException('Forbidden.');
        }

        BranchScope::for($actor)->assertIncludes($schedule->branch_id);
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
    private function audit(User $actor, string $action, ReportSchedule $schedule, array $context = []): void
    {
        $this->audit->record(
            actor: $actor,
            action: $action,
            category: AuditEvent::CATEGORY_BUSINESS,
            module: 'reports',
            branchId: $schedule->branch_id,
            subjectType: ReportSchedule::class,
            subjectId: $schedule->id,
            metadata: array_merge(['reference' => $schedule->reference], $context),
        );
    }
}
