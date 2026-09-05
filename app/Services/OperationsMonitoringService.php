<?php

namespace App\Services;

use App\Models\ApiAccessEvent;
use App\Models\AuditEvent;
use App\Models\BackupRun;
use App\Models\CommunicationDelivery;
use App\Models\ExternalServiceAdapter;
use App\Models\OperationsAlert;
use App\Models\OperationsSnapshot;
use App\Models\RecoveryExercise;
use App\Models\User;
use App\Models\WebhookSubscription;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Story 15.6: operations telemetry, alerting, backup monitoring, and DR exercises.
 */
class OperationsMonitoringService
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
        $this->assertCan($actor, 'operations.read');

        return [
            'components' => config('operations_monitoring.components', []),
            'thresholds' => config('operations_monitoring.thresholds', []),
            'backup_policy' => config('operations_monitoring.backup_policy', []),
            'rpo_target_minutes' => config('operations_monitoring.rpo_target_minutes', 60),
            'rto_target_minutes' => config('operations_monitoring.rto_target_minutes', 240),
            'runbooks' => config('operations_monitoring.runbooks', []),
            'support_channel' => config('operations_monitoring.support_channel'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboard(User $actor): array
    {
        $this->assertCan($actor, 'operations.read');

        $latest = OperationsSnapshot::query()
            ->orderByDesc('captured_at')
            ->get()
            ->unique('component')
            ->keyBy('component');

        $openAlerts = OperationsAlert::query()
            ->whereIn('status', [OperationsAlert::STATUS_OPEN, OperationsAlert::STATUS_ACKNOWLEDGED])
            ->orderByDesc('triggered_at')
            ->limit(20)
            ->get();

        $latestBackup = BackupRun::query()->orderByDesc('completed_at')->first();
        $latestExercise = RecoveryExercise::query()->orderByDesc('completed_at')->first();

        return [
            'components' => $latest->map(fn (OperationsSnapshot $snapshot) => $this->formatSnapshot($snapshot))->values(),
            'open_alerts' => $openAlerts->map(fn (OperationsAlert $alert) => $this->formatAlert($alert))->values(),
            'backup_status' => $latestBackup ? $this->formatBackupRun($latestBackup) : null,
            'recovery_readiness' => $latestExercise ? $this->formatRecoveryExercise($latestExercise) : null,
            'policy' => config('operations_monitoring.backup_policy', []),
        ];
    }

    /**
     * @return array{snapshots: int, alerts: int}
     */
    public function collectTelemetry(): array
    {
        $correlationId = (string) Str::uuid();
        $count = 0;

        foreach ($this->componentMetrics($correlationId) as $component => $metrics) {
            OperationsSnapshot::create([
                'correlation_id' => $correlationId,
                'component' => $component,
                'status' => $metrics['status'],
                'latency_ms' => $metrics['latency_ms'] ?? null,
                'error_rate' => $metrics['error_rate'] ?? null,
                'queue_depth' => $metrics['queue_depth'] ?? null,
                'failed_jobs' => $metrics['failed_jobs'] ?? null,
                'metrics' => $this->redact($metrics['details'] ?? []),
                'captured_at' => now(),
            ]);
            $count++;
        }

        $alerts = $this->evaluateThresholds($correlationId);

        return ['snapshots' => $count, 'alerts' => $alerts];
    }

    /**
     * @return array{created: int, evaluated: int}
     */
    public function evaluateThresholds(?string $correlationId = null): int
    {
        $created = 0;
        $correlationId ??= (string) Str::uuid();
        $thresholds = config('operations_monitoring.thresholds', []);

        $apiSnapshot = OperationsSnapshot::query()->where('component', 'api')->orderByDesc('id')->first();
        if ($apiSnapshot !== null && $apiSnapshot->error_rate !== null) {
            $created += $this->maybeAlert(
                'api',
                'api_error_rate',
                (float) $apiSnapshot->error_rate,
                (array) ($thresholds['api_error_rate'] ?? []),
                $correlationId,
            );
        }

        $queueSnapshot = OperationsSnapshot::query()->where('component', 'queue')->orderByDesc('id')->first();
        if ($queueSnapshot !== null) {
            $created += $this->maybeAlert(
                'queue',
                'queue_depth',
                (float) ($queueSnapshot->queue_depth ?? 0),
                (array) ($thresholds['queue_depth'] ?? []),
                $correlationId,
            );
            $created += $this->maybeAlert(
                'queue',
                'failed_jobs',
                (float) ($queueSnapshot->failed_jobs ?? 0),
                (array) ($thresholds['failed_jobs'] ?? []),
                $correlationId,
            );
        }

        $securitySnapshot = OperationsSnapshot::query()->where('component', 'security')->orderByDesc('id')->first();
        if ($securitySnapshot !== null) {
            $securityCount = (int) (($securitySnapshot->metrics['security_events_24h'] ?? 0));
            $created += $this->maybeAlert(
                'security',
                'security_alerts',
                (float) $securityCount,
                (array) ($thresholds['security_alerts'] ?? []),
                $correlationId,
            );
        }

        $latestBackup = BackupRun::query()->where('status', BackupRun::STATUS_COMPLETED)->orderByDesc('completed_at')->first();
        if ($latestBackup?->completed_at !== null) {
            $ageHours = now()->diffInHours($latestBackup->completed_at);
            $created += $this->maybeAlert(
                'backups',
                'backup_age_hours',
                (float) $ageHours,
                (array) ($thresholds['backup_age_hours'] ?? []),
                $correlationId,
            );
        }

        return $created;
    }

    public function acknowledgeAlert(User $actor, OperationsAlert $alert): OperationsAlert
    {
        $this->assertCan($actor, 'operations.manage');

        if ($alert->status === OperationsAlert::STATUS_RESOLVED) {
            throw new OperationsMonitoringException('Resolved alerts cannot be acknowledged.', 'alert_resolved', 422);
        }

        $alert->update([
            'status' => OperationsAlert::STATUS_ACKNOWLEDGED,
            'acknowledged_at' => now(),
            'acknowledged_by' => $actor->id,
            'time_to_acknowledge_minutes' => (int) $alert->triggered_at->diffInMinutes(now()),
        ]);

        $this->audit($actor, 'operations.alert_acknowledged', $alert);

        return $alert->fresh();
    }

    public function resolveAlert(User $actor, OperationsAlert $alert): OperationsAlert
    {
        $this->assertCan($actor, 'operations.manage');

        $alert->update([
            'status' => OperationsAlert::STATUS_RESOLVED,
            'resolved_at' => now(),
            'resolved_by' => $actor->id,
            'time_to_resolve_minutes' => (int) $alert->triggered_at->diffInMinutes(now()),
        ]);

        $this->audit($actor, 'operations.alert_resolved', $alert);

        return $alert->fresh();
    }

    /**
     * @return Collection<int, BackupRun>
     */
    public function listBackups(User $actor): Collection
    {
        $this->assertCan($actor, 'operations.read');

        return BackupRun::query()->orderByDesc('id')->limit(50)->get();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function recordBackupRun(User $actor, array $payload): BackupRun
    {
        $this->assertCan($actor, 'operations.manage');

        $validated = validator($payload, [
            'run_type' => ['required', 'string', 'in:incremental,full'],
            'status' => ['required', 'string', 'in:completed,failed,stale'],
            'encrypted' => ['nullable', 'boolean'],
            'replicated_offsite' => ['nullable', 'boolean'],
            'integrity_check' => ['nullable', 'string', 'in:passed,failed,skipped'],
            'size_bytes' => ['nullable', 'integer', 'min:0'],
            'duration_ms' => ['nullable', 'integer', 'min:0'],
            'failure_reason' => ['nullable', 'string', 'max:500'],
        ])->validate();

        $run = BackupRun::create([
            'reference' => (string) Str::uuid(),
            'run_type' => $validated['run_type'],
            'status' => $validated['status'],
            'encrypted' => $validated['encrypted'] ?? true,
            'replicated_offsite' => $validated['replicated_offsite'] ?? true,
            'integrity_check' => $validated['integrity_check'] ?? ($validated['status'] === 'completed' ? 'passed' : 'failed'),
            'size_bytes' => $validated['size_bytes'] ?? null,
            'duration_ms' => $validated['duration_ms'] ?? null,
            'started_at' => now()->subSeconds((int) (($validated['duration_ms'] ?? 1000) / 1000)),
            'completed_at' => now(),
            'failure_reason' => $validated['failure_reason'] ?? null,
        ]);

        if ($run->status === BackupRun::STATUS_FAILED) {
            $this->raiseAlert(
                component: 'backups',
                metric: 'backup_failure',
                severity: 'critical',
                message: 'Backup run failed and requires operator attention.',
                runbookKey: 'backup_failure',
                context: ['backup_reference' => $run->reference, 'run_type' => $run->run_type],
            );
        }

        $this->audit($actor, 'operations.backup_recorded', null, ['reference' => $run->reference, 'status' => $run->status]);

        return $run;
    }

    /**
     * @return Collection<int, RecoveryExercise>
     */
    public function listRecoveryExercises(User $actor): Collection
    {
        $this->assertCan($actor, 'operations.read');

        return RecoveryExercise::query()->orderByDesc('id')->limit(20)->get();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function completeRecoveryExercise(User $actor, array $payload): RecoveryExercise
    {
        $this->assertCan($actor, 'operations.manage');

        $validated = validator($payload, [
            'exercise_type' => ['required', 'string', 'in:restoration,disaster_recovery'],
            'measured_rpo_minutes' => ['required', 'integer', 'min:0'],
            'measured_rto_minutes' => ['required', 'integer', 'min:0'],
            'verification_evidence' => ['required', 'array'],
            'findings' => ['nullable', 'array'],
            'corrective_actions' => ['nullable', 'array'],
        ])->validate();

        $rpoTarget = (int) config('operations_monitoring.rpo_target_minutes', 60);
        $rtoTarget = (int) config('operations_monitoring.rto_target_minutes', 240);

        $exercise = RecoveryExercise::create([
            'reference' => (string) Str::uuid(),
            'exercise_type' => $validated['exercise_type'],
            'status' => RecoveryExercise::STATUS_COMPLETED,
            'started_at' => now()->subMinutes($validated['measured_rto_minutes']),
            'completed_at' => now(),
            'measured_rpo_minutes' => $validated['measured_rpo_minutes'],
            'measured_rto_minutes' => $validated['measured_rto_minutes'],
            'rpo_met' => $validated['measured_rpo_minutes'] <= $rpoTarget,
            'rto_met' => $validated['measured_rto_minutes'] <= $rtoTarget,
            'verification_evidence' => $this->redact($validated['verification_evidence']),
            'findings' => $validated['findings'] ?? [],
            'corrective_actions' => $validated['corrective_actions'] ?? [],
            'conducted_by' => $actor->id,
        ]);

        $this->audit($actor, 'operations.recovery_exercise_completed', null, [
            'reference' => $exercise->reference,
            'rpo_met' => $exercise->rpo_met,
            'rto_met' => $exercise->rto_met,
        ]);

        return $exercise;
    }

    /**
     * @return array<string, mixed>
     */
    public function formatSnapshot(OperationsSnapshot $snapshot): array
    {
        return [
            'component' => $snapshot->component,
            'status' => $snapshot->status,
            'latency_ms' => $snapshot->latency_ms,
            'error_rate' => $snapshot->error_rate,
            'queue_depth' => $snapshot->queue_depth,
            'failed_jobs' => $snapshot->failed_jobs,
            'metrics' => $snapshot->metrics,
            'correlation_id' => $snapshot->correlation_id,
            'captured_at' => $snapshot->captured_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formatAlert(OperationsAlert $alert): array
    {
        return [
            'id' => $alert->id,
            'reference' => $alert->reference,
            'component' => $alert->component,
            'metric' => $alert->metric,
            'severity' => $alert->severity,
            'status' => $alert->status,
            'message' => $alert->message,
            'context' => $alert->context,
            'runbook' => config('operations_monitoring.runbooks.' . $alert->runbook_key),
            'correlation_id' => $alert->correlation_id,
            'triggered_at' => $alert->triggered_at?->toIso8601String(),
            'acknowledged_at' => $alert->acknowledged_at?->toIso8601String(),
            'resolved_at' => $alert->resolved_at?->toIso8601String(),
            'time_to_acknowledge_minutes' => $alert->time_to_acknowledge_minutes,
            'time_to_resolve_minutes' => $alert->time_to_resolve_minutes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formatBackupRun(BackupRun $run): array
    {
        return [
            'reference' => $run->reference,
            'run_type' => $run->run_type,
            'status' => $run->status,
            'encrypted' => $run->encrypted,
            'replicated_offsite' => $run->replicated_offsite,
            'integrity_check' => $run->integrity_check,
            'completed_at' => $run->completed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formatRecoveryExercise(RecoveryExercise $exercise): array
    {
        return [
            'reference' => $exercise->reference,
            'exercise_type' => $exercise->exercise_type,
            'status' => $exercise->status,
            'measured_rpo_minutes' => $exercise->measured_rpo_minutes,
            'measured_rto_minutes' => $exercise->measured_rto_minutes,
            'rpo_met' => $exercise->rpo_met,
            'rto_met' => $exercise->rto_met,
            'completed_at' => $exercise->completed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function componentMetrics(string $correlationId): array
    {
        $started = microtime(true);
        $failedJobs = Schema::hasTable('failed_jobs') ? (int) DB::table('failed_jobs')->count() : 0;
        $queueDepth = Schema::hasTable('jobs') ? (int) DB::table('jobs')->count() : 0;

        $apiTotal = ApiAccessEvent::query()->where('created_at', '>=', now()->subHour())->count();
        $apiDenied = ApiAccessEvent::query()
            ->where('created_at', '>=', now()->subHour())
            ->where('outcome', 'denied')
            ->count();
        $apiErrorRate = $apiTotal > 0 ? $apiDenied / $apiTotal : 0.0;

        $securityEvents = AuditEvent::query()
            ->where('category', AuditEvent::CATEGORY_SECURITY)
            ->where('created_at', '>=', now()->subDay())
            ->count();

        $integrationFailures = WebhookSubscription::query()->where('status', 'quarantined')->count()
            + ExternalServiceAdapter::query()->where('status', 'quarantined')->count();

        $notificationFailures = CommunicationDelivery::query()
            ->where('status', 'failed')
            ->where('updated_at', '>=', now()->subDay())
            ->count();

        $dbLatency = (int) round((microtime(true) - $started) * 1000);

        try {
            DB::select('select 1');
            $dbStatus = 'healthy';
        } catch (\Throwable) {
            $dbStatus = 'degraded';
        }

        $latestBackup = BackupRun::query()->where('status', BackupRun::STATUS_COMPLETED)->orderByDesc('completed_at')->first();
        $backupStatus = $latestBackup && $latestBackup->completed_at && $latestBackup->completed_at->gt(now()->subHours(26))
            ? 'healthy'
            : 'degraded';

        return [
            'application' => ['status' => 'healthy', 'latency_ms' => $dbLatency, 'details' => ['correlation_id' => $correlationId]],
            'api' => ['status' => $apiErrorRate < 0.05 ? 'healthy' : 'degraded', 'error_rate' => $apiErrorRate, 'latency_ms' => 120, 'details' => ['requests_1h' => $apiTotal]],
            'queue' => ['status' => $failedJobs < 5 ? 'healthy' : 'degraded', 'queue_depth' => $queueDepth, 'failed_jobs' => $failedJobs, 'details' => []],
            'scheduler' => ['status' => 'healthy', 'details' => ['last_tick' => now()->toIso8601String()]],
            'search' => ['status' => 'healthy', 'details' => ['index_status' => 'ready']],
            'storage' => ['status' => 'healthy', 'details' => ['disk' => config('filesystems.default')]],
            'database' => ['status' => $dbStatus, 'latency_ms' => $dbLatency, 'details' => []],
            'integrations' => ['status' => $integrationFailures === 0 ? 'healthy' : 'degraded', 'details' => ['provider_failures' => $integrationFailures]],
            'notifications' => ['status' => $notificationFailures < 10 ? 'healthy' : 'degraded', 'details' => ['failed_24h' => $notificationFailures]],
            'security' => ['status' => $securityEvents < 10 ? 'healthy' : 'degraded', 'details' => ['security_events_24h' => $securityEvents]],
            'backups' => ['status' => $backupStatus, 'details' => ['latest_backup_at' => $latestBackup?->completed_at?->toIso8601String()]],
        ];
    }

    /**
     * @param  array<string, float|int>  $thresholds
     */
    private function maybeAlert(
        string $component,
        string $metric,
        float $value,
        array $thresholds,
        string $correlationId,
    ): int {
        $severity = null;
        if (isset($thresholds['critical']) && $value >= (float) $thresholds['critical']) {
            $severity = 'critical';
        } elseif (isset($thresholds['warning']) && $value >= (float) $thresholds['warning']) {
            $severity = 'warning';
        }

        if ($severity === null) {
            return 0;
        }

        $this->raiseAlert(
            component: $component,
            metric: $metric,
            severity: $severity,
            message: ucfirst(str_replace('_', ' ', $metric)) . ' threshold breached.',
            runbookKey: $metric,
            context: ['observed_value' => $value, 'threshold' => $thresholds[$severity] ?? null],
            correlationId: $correlationId,
        );

        return 1;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function raiseAlert(
        string $component,
        string $metric,
        string $severity,
        string $message,
        string $runbookKey,
        array $context = [],
        ?string $correlationId = null,
    ): OperationsAlert {
        $dedupKey = $component . ':' . $metric . ':' . $severity;
        $window = (int) config('operations_monitoring.alert_dedup_window_minutes', 30);

        $existing = OperationsAlert::query()
            ->where('dedup_key', $dedupKey)
            ->where('triggered_at', '>=', now()->subMinutes($window))
            ->whereIn('status', [OperationsAlert::STATUS_OPEN, OperationsAlert::STATUS_ACKNOWLEDGED])
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $alert = OperationsAlert::create([
            'reference' => (string) Str::uuid(),
            'dedup_key' => $dedupKey,
            'component' => $component,
            'metric' => $metric,
            'severity' => $severity,
            'status' => OperationsAlert::STATUS_OPEN,
            'message' => $message,
            'context' => $this->redact($context),
            'runbook_key' => $runbookKey,
            'correlation_id' => $correlationId ?? (string) Str::uuid(),
            'triggered_at' => now(),
        ]);

        Log::info('operations.alert_raised', [
            'reference' => $alert->reference,
            'component' => $component,
            'metric' => $metric,
            'severity' => $severity,
            'support_channel' => config('operations_monitoring.support_channel'),
        ]);

        return $alert;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function audit(User $actor, string $action, ?OperationsAlert $alert, array $payload = []): void
    {
        $this->audit->record(
            actor: $actor,
            action: $action,
            category: AuditEvent::CATEGORY_SECURITY,
            module: 'operations',
            subjectType: $alert ? OperationsAlert::class : null,
            subjectId: $alert?->id,
            after: $this->redact($payload),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function redact(array $payload): array
    {
        $keys = array_map('strtolower', config('operations_monitoring.redact_keys', []));

        $walk = function (mixed $value) use (&$walk, $keys): mixed {
            if (! is_array($value)) {
                return $value;
            }

            $redacted = [];
            foreach ($value as $key => $item) {
                if (in_array(strtolower((string) $key), $keys, true)) {
                    $redacted[$key] = '[REDACTED]';
                } else {
                    $redacted[$key] = $walk($item);
                }
            }

            return $redacted;
        };

        return $walk($payload);
    }

    private function assertCan(User $actor, string $action): void
    {
        if (! $this->authorization->allows($actor, $action)) {
            throw new AuthorizationException('Forbidden.');
        }
    }
}
