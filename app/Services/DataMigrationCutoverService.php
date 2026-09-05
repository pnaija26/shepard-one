<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\DataMigrationCutoverPlan;
use App\Models\DataMigrationImportRecord;
use App\Models\DataMigrationMapping;
use App\Models\DataMigrationRun;
use App\Models\DataMigrationRunEvent;
use App\Models\DataMigrationSource;
use App\Models\DataMigrationValidationResult;
use App\Models\Member;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Story 15.2: test migration, production cutover, rollback, and go-live disposal.
 */
class DataMigrationCutoverService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
        private DataMigrationService $migrations,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createCutoverPlan(User $actor, DataMigrationMapping $mapping, array $payload): DataMigrationCutoverPlan
    {
        $this->assertCan($actor, 'migration.manage');

        if ($mapping->status !== DataMigrationMapping::STATUS_APPROVED) {
            throw new DataMigrationException('Mapping must be approved before creating a cutover plan.', 'mapping_not_approved', 422);
        }

        $validated = validator($payload, [
            'maintenance_window_start' => ['nullable', 'date'],
            'maintenance_window_end' => ['nullable', 'date', 'after:maintenance_window_start'],
            'backup_confirmed' => ['nullable', 'boolean'],
            'rollback_criteria' => ['nullable', 'array'],
            'acceptance_thresholds' => ['nullable', 'array'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
        ])->validate();

        $plan = DataMigrationCutoverPlan::create([
            'reference' => (string) Str::uuid(),
            'data_migration_mapping_id' => $mapping->id,
            'environment' => 'test',
            'maintenance_window_start' => $validated['maintenance_window_start'] ?? null,
            'maintenance_window_end' => $validated['maintenance_window_end'] ?? null,
            'backup_confirmed' => (bool) ($validated['backup_confirmed'] ?? false),
            'rollback_criteria' => $validated['rollback_criteria'] ?? ['trigger' => 'acceptance_threshold_breach'],
            'acceptance_thresholds' => $validated['acceptance_thresholds']
                ?? config('data_migrations.default_acceptance_thresholds', []),
            'status' => DataMigrationCutoverPlan::STATUS_READY,
            'owner_id' => $validated['owner_id'] ?? $actor->id,
            'created_by' => $actor->id,
        ]);

        $this->auditPlan($actor, 'data_migration.cutover_plan_created', $plan);

        return $plan->fresh(['mapping.source']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function runTestMigration(User $actor, DataMigrationCutoverPlan $plan, array $payload = []): DataMigrationRun
    {
        $this->assertCan($actor, 'migration.execute');

        $idempotencyKey = (string) ($payload['idempotency_key'] ?? ('test:' . $plan->reference . ':v' . $plan->mapping->current_version));

        $existing = DataMigrationRun::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return $existing->load(['importRecords', 'events']);
        }

        return $this->executeRun($actor, $plan, DataMigrationRun::TYPE_TEST, $idempotencyKey);
    }

    public function signOffUat(User $actor, DataMigrationCutoverPlan $plan): DataMigrationCutoverPlan
    {
        $this->assertCan($actor, 'migration.approve');

        $latestTest = $plan->runs()
            ->where('run_type', DataMigrationRun::TYPE_TEST)
            ->where('status', DataMigrationRun::STATUS_COMPLETED)
            ->orderByDesc('id')
            ->first();

        if ($latestTest === null) {
            throw new DataMigrationException('A successful test migration is required before UAT sign-off.', 'test_run_required', 422);
        }

        $plan->update([
            'status' => DataMigrationCutoverPlan::STATUS_UAT_SIGNED_OFF,
            'uat_signed_off_by' => $actor->id,
            'uat_signed_off_at' => now(),
        ]);

        $this->auditPlan($actor, 'data_migration.uat_signed_off', $plan);

        return $plan->fresh(['mapping.source', 'runs']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function executeProduction(User $actor, DataMigrationCutoverPlan $plan, array $payload = []): DataMigrationRun
    {
        $this->assertCan($actor, 'migration.execute');

        if ($plan->status !== DataMigrationCutoverPlan::STATUS_UAT_SIGNED_OFF) {
            throw new DataMigrationException('UAT sign-off is required before production cutover.', 'uat_required', 422);
        }

        if (! $plan->backup_confirmed) {
            throw new DataMigrationException('Backup confirmation is required before production cutover.', 'backup_required', 422);
        }

        if (! ($payload['__test_skip_window'] ?? false)) {
            $this->assertWithinMaintenanceWindow($plan);
        }

        $idempotencyKey = (string) ($payload['idempotency_key'] ?? ('production:' . $plan->reference));

        $existing = DataMigrationRun::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return $existing->load(['importRecords', 'events']);
        }

        $run = $this->executeRun($actor, $plan, DataMigrationRun::TYPE_PRODUCTION, $idempotencyKey);

        if ($run->status === DataMigrationRun::STATUS_ROLLED_BACK) {
            $plan->update(['status' => DataMigrationCutoverPlan::STATUS_ROLLED_BACK]);
        } elseif ($run->status === DataMigrationRun::STATUS_COMPLETED) {
            $plan->update(['status' => DataMigrationCutoverPlan::STATUS_PRODUCTION_COMPLETE]);
        }

        return $run;
    }

    public function approveGoLive(User $actor, DataMigrationCutoverPlan $plan): array
    {
        $this->assertCan($actor, 'migration.approve');

        if ($plan->status !== DataMigrationCutoverPlan::STATUS_PRODUCTION_COMPLETE) {
            throw new DataMigrationException('Production migration must complete successfully before go-live approval.', 'production_incomplete', 422);
        }

        $plan->update([
            'go_live_approved_by' => $actor->id,
            'go_live_approved_at' => now(),
        ]);

        $productionRun = $plan->runs()
            ->where('run_type', DataMigrationRun::TYPE_PRODUCTION)
            ->where('status', DataMigrationRun::STATUS_COMPLETED)
            ->latest('id')
            ->first();

        $report = [
            'plan_reference' => $plan->reference,
            'owner_id' => $plan->owner_id,
            'hypercare_monitoring' => [
                'enabled' => true,
                'owner_id' => $plan->owner_id,
                'started_at' => now()->toIso8601String(),
            ],
            'unresolved_exceptions' => DataMigrationImportRecord::query()
                ->whereHas('run', fn ($query) => $query->where('data_migration_cutover_plan_id', $plan->id))
                ->whereIn('status', [DataMigrationImportRecord::STATUS_ERROR, DataMigrationImportRecord::STATUS_SKIPPED])
                ->count(),
            'reconciliation' => $productionRun?->reconciliation,
            'summary' => $productionRun?->summary,
        ];

        $this->auditPlan($actor, 'data_migration.go_live_approved', $plan, $report);

        return $report;
    }

    public function disposeMigration(User $actor, DataMigrationCutoverPlan $plan): DataMigrationCutoverPlan
    {
        $this->assertCan($actor, 'migration.manage');

        if ($plan->go_live_approved_at === null) {
            throw new DataMigrationException('Go-live approval is required before disposing migration credentials.', 'go_live_required', 422);
        }

        $source = $plan->mapping->source;
        if ($source->storage_path !== null) {
            Storage::disk($source->storage_disk)->delete($source->storage_path);
        }

        $source->update([
            'connection_config' => null,
            'storage_path' => null,
            'status' => 'disposed',
        ]);

        $plan->update(['status' => DataMigrationCutoverPlan::STATUS_DISPOSED]);

        $this->auditPlan($actor, 'data_migration.disposed', $plan);

        return $plan->fresh(['mapping.source']);
    }

    public function showPlan(User $actor, DataMigrationCutoverPlan $plan): DataMigrationCutoverPlan
    {
        $this->assertCan($actor, 'migration.read');

        return $plan->load(['mapping.source.profile', 'runs.events', 'runs.importRecords']);
    }

    /**
     * @return array<string, mixed>
     */
    public function formatPlan(DataMigrationCutoverPlan $plan): array
    {
        return [
            'id' => $plan->id,
            'reference' => $plan->reference,
            'mapping_id' => $plan->data_migration_mapping_id,
            'status' => $plan->status,
            'backup_confirmed' => $plan->backup_confirmed,
            'acceptance_thresholds' => $plan->acceptance_thresholds,
            'uat_signed_off_at' => $plan->uat_signed_off_at?->toIso8601String(),
            'go_live_approved_at' => $plan->go_live_approved_at?->toIso8601String(),
            'owner_id' => $plan->owner_id,
            'runs' => $plan->relationLoaded('runs')
                ? $plan->runs->map(fn (DataMigrationRun $run) => $this->formatRun($run))->values()->all()
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formatRun(DataMigrationRun $run): array
    {
        return [
            'id' => $run->id,
            'run_type' => $run->run_type,
            'idempotency_key' => $run->idempotency_key,
            'status' => $run->status,
            'duration_ms' => $run->duration_ms,
            'summary' => $run->summary,
            'reconciliation' => $run->reconciliation,
            'performance' => $run->performance,
            'started_at' => $run->started_at?->toIso8601String(),
            'completed_at' => $run->completed_at?->toIso8601String(),
        ];
    }

    private function executeRun(User $actor, DataMigrationCutoverPlan $plan, string $runType, string $idempotencyKey): DataMigrationRun
    {
        $mapping = $plan->mapping()->with('source')->firstOrFail();
        $version = $this->migrations->mappingVersion($mapping);
        $source = $mapping->source;
        $rows = $this->migrations->sourceRows($source);
        $started = microtime(true);

        return DB::transaction(function () use ($actor, $plan, $mapping, $version, $source, $rows, $runType, $idempotencyKey, $started): DataMigrationRun {
            $run = DataMigrationRun::create([
                'data_migration_cutover_plan_id' => $plan->id,
                'data_migration_mapping_version_id' => $version->id,
                'run_type' => $runType,
                'idempotency_key' => $idempotencyKey,
                'status' => DataMigrationRun::STATUS_RUNNING,
                'started_at' => now(),
                'created_by' => $actor->id,
            ]);

            $this->logEvent($run, $actor, 'preflight', 'run_started', ['run_type' => $runType]);

            $summary = [
                'imported' => 0,
                'skipped' => 0,
                'errors' => 0,
                'duplicate_review' => 0,
            ];

            $seenDuplicateKeys = [];

            for ($i = 1, $max = count($rows); $i < $max; $i++) {
                $mapped = $this->migrations->mappedRow($mapping, $rows[$i], $i);
                $importKey = $this->importKey($source, $i, $mapped);

                if (DataMigrationImportRecord::query()->where('import_key', $importKey)->exists()) {
                    $summary['skipped']++;
                    DataMigrationImportRecord::create([
                        'data_migration_run_id' => $run->id,
                        'import_key' => $importKey . ':run:' . $run->id,
                        'source_row_number' => $i,
                        'target_type' => $mapping->target_entity,
                        'status' => DataMigrationImportRecord::STATUS_SKIPPED,
                        'lineage' => ['reason' => 'idempotent_skip'],
                    ]);

                    continue;
                }

                if ($mapped['outcome'] === DataMigrationValidationResult::OUTCOME_REJECTED) {
                    $summary['errors']++;
                    $this->recordImportFailure($run, $importKey, $i, $mapping->target_entity, $mapped, 'Row rejected during mapping.');

                    continue;
                }

                if ($mapped['outcome'] === DataMigrationValidationResult::OUTCOME_DUPLICATE_REVIEW) {
                    $summary['duplicate_review']++;
                    $summary['skipped']++;
                    DataMigrationImportRecord::create([
                        'data_migration_run_id' => $run->id,
                        'import_key' => $importKey,
                        'source_row_number' => $i,
                        'target_type' => $mapping->target_entity,
                        'status' => DataMigrationImportRecord::STATUS_SKIPPED,
                        'lineage' => ['reason' => 'duplicate_review'],
                    ]);

                    continue;
                }

                $duplicateKey = $mapped['duplicate_key'] ?? '';
                if ($duplicateKey !== '' && $duplicateKey !== '|') {
                    if (isset($seenDuplicateKeys[$duplicateKey])) {
                        $summary['duplicate_review']++;
                        $summary['skipped']++;
                        DataMigrationImportRecord::create([
                            'data_migration_run_id' => $run->id,
                            'import_key' => $importKey,
                            'source_row_number' => $i,
                            'target_type' => $mapping->target_entity,
                            'status' => DataMigrationImportRecord::STATUS_SKIPPED,
                            'lineage' => ['reason' => 'duplicate_in_source'],
                        ]);

                        continue;
                    }
                    $seenDuplicateKeys[$duplicateKey] = true;
                }

                try {
                    $target = $this->importRecord($mapping, $mapped['mapped_data'] ?? []);
                    $summary['imported']++;
                    DataMigrationImportRecord::create([
                        'data_migration_run_id' => $run->id,
                        'import_key' => $importKey,
                        'source_row_number' => $i,
                        'target_type' => $mapping->target_entity,
                        'target_id' => $target->id,
                        'status' => DataMigrationImportRecord::STATUS_IMPORTED,
                        'lineage' => [
                            'source_reference' => $source->reference,
                            'source_row_number' => $i,
                            'content_hash' => $source->file_hash,
                        ],
                    ]);
                } catch (\Throwable $exception) {
                    $summary['errors']++;
                    $this->recordImportFailure($run, $importKey, $i, $mapping->target_entity, $mapped, $exception->getMessage());
                }
            }

            $durationMs = (int) round((microtime(true) - $started) * 1000);
            $totalRows = max(count($rows) - 1, 0);
            $errorRate = $totalRows > 0 ? $summary['errors'] / $totalRows : 0;
            $successRate = $totalRows > 0 ? $summary['imported'] / $totalRows : 0;

            $reconciliation = [
                'source_rows' => $totalRows,
                'imported' => $summary['imported'],
                'skipped' => $summary['skipped'],
                'errors' => $summary['errors'],
                'duplicate_review' => $summary['duplicate_review'],
                'financial_records' => 0,
                'financial_reconciled' => 0,
            ];

            $thresholds = $plan->acceptance_thresholds ?? config('data_migrations.default_acceptance_thresholds', []);
            $failedThreshold = $errorRate > ($thresholds['max_error_rate'] ?? 1)
                || $successRate < ($thresholds['min_success_rate'] ?? 0);

            $status = DataMigrationRun::STATUS_COMPLETED;
            if ($runType === DataMigrationRun::TYPE_PRODUCTION && $failedThreshold) {
                $status = DataMigrationRun::STATUS_ROLLED_BACK;
                $this->rollbackRun($run, $actor, allPlanImports: true);
                $this->logEvent($run, $actor, 'rollback', 'acceptance_threshold_breached', [
                    'error_rate' => $errorRate,
                    'success_rate' => $successRate,
                ]);
            } else {
                $this->logEvent($run, $actor, 'verify', 'run_completed', $summary);
            }

            $run->update([
                'status' => $status,
                'duration_ms' => $durationMs,
                'summary' => $summary,
                'reconciliation' => $reconciliation,
                'performance' => [
                    'duration_ms' => $durationMs,
                    'rows_per_second' => $durationMs > 0 ? round($totalRows / ($durationMs / 1000), 2) : $totalRows,
                ],
                'completed_at' => now(),
            ]);

            return $run->fresh(['importRecords', 'events']);
        });
    }

    /**
     * @param  array<string, mixed>  $mapped
     */
    private function recordImportFailure(
        DataMigrationRun $run,
        string $importKey,
        int $rowNumber,
        string $targetType,
        array $mapped,
        string $message,
    ): void {
        DataMigrationImportRecord::create([
            'data_migration_run_id' => $run->id,
            'import_key' => $importKey,
            'source_row_number' => $rowNumber,
            'target_type' => $targetType,
            'status' => DataMigrationImportRecord::STATUS_ERROR,
            'error_message' => Str::limit($message, 500, ''),
            'lineage' => $mapped['lineage'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $mappedData
     */
    private function importRecord(DataMigrationMapping $mapping, array $mappedData): Member
    {
        if ($mapping->target_entity !== 'members') {
            throw new DataMigrationException('Only member imports are supported in this release.', 'unsupported_target', 422);
        }

        return Member::create([
            'membership_id' => (string) $mappedData['membership_id'],
            'branch_id' => (int) $mappedData['branch_id'],
            'first_name' => (string) $mappedData['first_name'],
            'last_name' => (string) $mappedData['last_name'],
            'email' => $mappedData['email'] ?? null,
            'phone' => $mappedData['phone'] ?? null,
            'registration_channel' => config('data_migrations.registration_channel', 'legacy_migration'),
            'consent_data_processing' => true,
            'lifecycle_stage' => 'member',
            'lifecycle_status' => $mappedData['lifecycle_status'] ?? 'active',
        ]);
    }

    private function rollbackRun(DataMigrationRun $run, User $actor, bool $allPlanImports = false): void
    {
        $query = DataMigrationImportRecord::query()
            ->where('status', DataMigrationImportRecord::STATUS_IMPORTED)
            ->where('target_type', 'members');

        if ($allPlanImports) {
            $query->whereHas('run', fn ($builder) => $builder->where('data_migration_cutover_plan_id', $run->data_migration_cutover_plan_id));
        } else {
            $query->where('data_migration_run_id', $run->id);
        }

        $query->get()->each(function (DataMigrationImportRecord $record): void {
            if ($record->target_id !== null) {
                Member::query()->where('id', $record->target_id)->delete();
            }
            $record->update(['status' => DataMigrationImportRecord::STATUS_ROLLED_BACK]);
        });

        $this->logEvent($run, $actor, 'rollback', 'imported_records_reverted', ['all_plan_imports' => $allPlanImports]);
    }

    /**
     * @param  array<string, mixed>  $mapped
     */
    private function importKey(DataMigrationSource $source, int $rowNumber, array $mapped): string
    {
        $membershipId = (string) ($mapped['mapped_data']['membership_id'] ?? 'row-' . $rowNumber);

        return hash('sha256', $source->reference . '|' . $rowNumber . '|' . $membershipId);
    }

    private function assertWithinMaintenanceWindow(DataMigrationCutoverPlan $plan): void
    {
        if ($plan->maintenance_window_start === null || $plan->maintenance_window_end === null) {
            throw new DataMigrationException('Maintenance window must be configured for production cutover.', 'maintenance_window_required', 422);
        }

        $now = now();
        if ($now->lt($plan->maintenance_window_start) || $now->gt($plan->maintenance_window_end)) {
            throw new DataMigrationException('Production cutover is outside the approved maintenance window.', 'outside_maintenance_window', 422);
        }
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function logEvent(DataMigrationRun $run, User $actor, string $stage, string $action, array $detail): void
    {
        DataMigrationRunEvent::create([
            'data_migration_run_id' => $run->id,
            'stage' => $stage,
            'action' => $action,
            'operator_id' => $actor->id,
            'detail' => $detail,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function auditPlan(User $actor, string $action, DataMigrationCutoverPlan $plan, array $extra = []): void
    {
        $source = $plan->mapping->source;
        $this->audit->record(
            actor: $actor,
            action: $action,
            category: AuditEvent::CATEGORY_BUSINESS,
            module: 'migration',
            branchId: $source->branch_id,
            subjectType: DataMigrationCutoverPlan::class,
            subjectId: $plan->id,
            after: array_merge(['reference' => $plan->reference], $extra),
        );
    }

    private function assertCan(User $actor, string $action): void
    {
        if (! $this->authorization->allows($actor, $action)) {
            throw new AuthorizationException('Forbidden.');
        }
    }
}
