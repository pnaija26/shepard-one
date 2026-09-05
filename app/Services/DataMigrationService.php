<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\DataMigrationMapping;
use App\Models\DataMigrationMappingTest;
use App\Models\DataMigrationMappingVersion;
use App\Models\DataMigrationProfile;
use App\Models\DataMigrationSource;
use App\Models\DataMigrationValidationResult;
use App\Models\DataMigrationValidationRun;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Story 15.1: profile, map, cleanse, and validate legacy migration data.
 */
class DataMigrationService
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
        $this->assertCan($actor, 'migration.read');

        return [
            'source_types' => config('data_migrations.source_types', []),
            'target_entities' => config('data_migrations.target_entities', []),
            'transformations' => config('data_migrations.transformations', []),
            'duplicate_strategies' => config('data_migrations.duplicate_strategies', []),
            'membership_systems' => config('data_migrations.membership_systems', []),
            'retention_days' => config('data_migrations.retention_days', 90),
        ];
    }

    /**
     * @return Collection<int, DataMigrationSource>
     */
    public function listSources(User $actor): Collection
    {
        $this->assertCan($actor, 'migration.read');

        return DataMigrationSource::query()
            ->with('profile')
            ->orderByDesc('id')
            ->limit(100)
            ->get();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createSource(User $actor, array $payload): DataMigrationSource
    {
        $this->assertCan($actor, 'migration.manage');

        $validated = validator($payload, [
            'name' => ['required', 'string', 'max:180'],
            'source_type' => ['required', 'string', 'in:' . implode(',', config('data_migrations.source_types', []))],
            'branch_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'classification' => ['nullable', 'string', 'in:internal,restricted,confidential'],
            'filename' => ['required_if:source_type,csv,excel', 'nullable', 'string', 'max:255'],
            'content_base64' => ['required_if:source_type,csv,excel', 'nullable', 'string'],
            'connection_config' => ['required_if:source_type,database,membership_system', 'nullable', 'array'],
        ])->validate();

        $reference = (string) Str::uuid();
        $disk = (string) config('data_migrations.storage_disk', 'local');
        $storagePath = null;
        $fileHash = null;
        $rowCount = 0;

        if (in_array($validated['source_type'], ['csv', 'excel'], true)) {
            $binary = base64_decode((string) ($validated['content_base64'] ?? ''), true);
            if ($binary === false || $binary === '') {
                throw new DataMigrationException('Source file could not be decoded.', 'invalid_content', 422);
            }

            $fileHash = hash('sha256', $binary);
            $filename = $this->safeFilename((string) ($validated['filename'] ?? 'source.csv'));
            $storagePath = 'data-migrations/' . $reference . '/' . $filename;
            Storage::disk($disk)->put($storagePath, $binary);
            $rowCount = max(0, count($this->parseTabular($binary)) - 1);
        }

        if ($validated['source_type'] === 'membership_system') {
            $system = (string) ($validated['connection_config']['system'] ?? '');
            if (! array_key_exists($system, config('data_migrations.membership_systems', []))) {
                throw ValidationException::withMessages([
                    'connection_config.system' => ['Unsupported membership system.'],
                ]);
            }
        }

        $source = DataMigrationSource::create([
            'reference' => $reference,
            'name' => $validated['name'],
            'source_type' => $validated['source_type'],
            'branch_id' => $validated['branch_id'] ?? $actor->branch_id,
            'status' => in_array($validated['source_type'], ['database', 'membership_system'], true)
                ? DataMigrationSource::STATUS_CONNECTED
                : DataMigrationSource::STATUS_UPLOADED,
            'storage_disk' => $disk,
            'storage_path' => $storagePath,
            'file_hash' => $fileHash,
            'row_count' => $rowCount,
            'classification' => $validated['classification'] ?? 'restricted',
            'retention_ends_at' => now()->addDays((int) config('data_migrations.retention_days', 90)),
            'connection_config' => $validated['connection_config'] ?? null,
            'created_by' => $actor->id,
        ]);

        $this->audit($actor, 'data_migration.source_created', $source);

        return $source->fresh('profile');
    }

    public function profile(User $actor, DataMigrationSource $source): DataMigrationProfile
    {
        $this->assertCan($actor, 'migration.manage');

        $rows = $this->loadSourceRows($source);
        $profileData = $this->buildProfile($rows);

        return DB::transaction(function () use ($source, $profileData): DataMigrationProfile {
            $profile = DataMigrationProfile::query()->updateOrCreate(
                ['data_migration_source_id' => $source->id],
                [
                    'columns' => $profileData['columns'],
                    'summary' => $profileData['summary'],
                    'sensitive_fields' => $profileData['sensitive_fields'],
                    'duplicate_keys' => $profileData['duplicate_keys'],
                    'profiled_at' => now(),
                ],
            );

            $source->update([
                'status' => DataMigrationSource::STATUS_PROFILED,
                'row_count' => $profileData['summary']['row_count'] ?? 0,
            ]);

            return $profile;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createMapping(User $actor, DataMigrationSource $source, array $payload): DataMigrationMapping
    {
        $this->assertCan($actor, 'migration.manage');
        $this->assertProfiled($source);

        $entities = array_keys(config('data_migrations.target_entities', []));
        $validated = validator($payload, [
            'name' => ['required', 'string', 'max:180'],
            'target_entity' => ['required', 'string', 'in:' . implode(',', $entities)],
            'branch_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'field_mappings' => ['required', 'array'],
            'transformations' => ['nullable', 'array'],
            'defaults' => ['nullable', 'array'],
            'duplicate_rules' => ['nullable', 'array'],
        ])->validate();

        return DB::transaction(function () use ($actor, $source, $validated): DataMigrationMapping {
            $mapping = DataMigrationMapping::create([
                'data_migration_source_id' => $source->id,
                'name' => $validated['name'],
                'target_entity' => $validated['target_entity'],
                'branch_id' => $validated['branch_id'] ?? $source->branch_id,
                'current_version' => 1,
                'status' => DataMigrationMapping::STATUS_DRAFT,
                'created_by' => $actor->id,
            ]);

            $errors = $this->validateMappingDefinition($mapping, $validated);

            DataMigrationMappingVersion::create([
                'data_migration_mapping_id' => $mapping->id,
                'version_number' => 1,
                'field_mappings' => $validated['field_mappings'],
                'transformations' => $validated['transformations'] ?? [],
                'defaults' => $validated['defaults'] ?? [],
                'duplicate_rules' => $validated['duplicate_rules'] ?? [],
                'validation_errors' => $errors,
                'status' => $errors === [] ? DataMigrationMappingVersion::STATUS_DRAFT : DataMigrationMappingVersion::STATUS_BLOCKED,
                'created_by' => $actor->id,
            ]);

            if ($errors !== []) {
                $mapping->update(['status' => DataMigrationMapping::STATUS_BLOCKED]);
            }

            return $mapping->fresh(['versions', 'source.profile']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateMappingDraft(User $actor, DataMigrationMapping $mapping, array $payload): DataMigrationMappingVersion
    {
        $this->assertCan($actor, 'migration.manage');

        $validated = validator($payload, [
            'field_mappings' => ['required', 'array'],
            'transformations' => ['nullable', 'array'],
            'defaults' => ['nullable', 'array'],
            'duplicate_rules' => ['nullable', 'array'],
        ])->validate();

        $current = $this->currentVersion($mapping);
        $errors = $this->validateMappingDefinition($mapping, $validated);

        $version = DataMigrationMappingVersion::create([
            'data_migration_mapping_id' => $mapping->id,
            'version_number' => $mapping->current_version + 1,
            'field_mappings' => $validated['field_mappings'],
            'transformations' => $validated['transformations'] ?? [],
            'defaults' => $validated['defaults'] ?? [],
            'duplicate_rules' => $validated['duplicate_rules'] ?? [],
            'validation_errors' => $errors,
            'status' => $errors === [] ? DataMigrationMappingVersion::STATUS_DRAFT : DataMigrationMappingVersion::STATUS_BLOCKED,
            'created_by' => $actor->id,
        ]);

        $mapping->update([
            'current_version' => $version->version_number,
            'status' => $errors === [] ? DataMigrationMapping::STATUS_DRAFT : DataMigrationMapping::STATUS_BLOCKED,
        ]);

        return $version->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function validateMapping(User $actor, DataMigrationMapping $mapping): array
    {
        $this->assertCan($actor, 'migration.manage');

        $mapping->load('source');
        $version = $this->currentVersion($mapping);
        $errors = $this->validateMappingDefinition($mapping, [
            'field_mappings' => $version->field_mappings ?? [],
            'transformations' => $version->transformations ?? [],
            'defaults' => $version->defaults ?? [],
            'duplicate_rules' => $version->duplicate_rules ?? [],
        ]);

        $version->update([
            'validation_errors' => $errors,
            'status' => $errors === [] ? DataMigrationMappingVersion::STATUS_DRAFT : DataMigrationMappingVersion::STATUS_BLOCKED,
        ]);

        $mapping->update([
            'status' => $errors === [] ? DataMigrationMapping::STATUS_DRAFT : DataMigrationMapping::STATUS_BLOCKED,
        ]);

        return [
            'valid' => $errors === [],
            'errors' => $errors,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function testSample(User $actor, DataMigrationMapping $mapping): array
    {
        $this->assertCan($actor, 'migration.manage');

        $mapping->load('source');
        $version = $this->currentVersion($mapping);
        $errors = $version->validation_errors ?? $this->validateMappingDefinition($mapping, [
            'field_mappings' => $version->field_mappings ?? [],
            'transformations' => $version->transformations ?? [],
            'defaults' => $version->defaults ?? [],
            'duplicate_rules' => $version->duplicate_rules ?? [],
        ]);

        if ($errors !== []) {
            throw new DataMigrationException('Mapping must pass validation before sample testing.', 'mapping_blocked', 422, ['errors' => $errors]);
        }

        $rows = $this->loadSourceRows($mapping->source);
        $sampleSize = min((int) config('data_migrations.sample_test_rows', 5), max(count($rows) - 1, 0));
        $results = [];

        for ($i = 1; $i <= $sampleSize; $i++) {
            $results[] = $this->mapRow($mapping, $version, $rows[$i], $i);
        }

        $passed = collect($results)->every(fn (array $row) => ($row['outcome'] ?? '') !== DataMigrationValidationResult::OUTCOME_REJECTED);

        DataMigrationMappingTest::create([
            'data_migration_mapping_version_id' => $version->id,
            'sample_size' => $sampleSize,
            'results' => $results,
            'passed' => $passed,
            'run_at' => now(),
        ]);

        return [
            'sample_size' => $sampleSize,
            'passed' => $passed,
            'results' => $results,
        ];
    }

    public function runValidation(User $actor, DataMigrationMapping $mapping): DataMigrationValidationRun
    {
        $this->assertCan($actor, 'migration.manage');

        $mapping->load('source');
        $version = $this->currentVersion($mapping);
        $errors = $version->validation_errors ?? [];
        if ($errors !== [] || $version->status === DataMigrationMappingVersion::STATUS_BLOCKED) {
            throw new DataMigrationException('Blocked mapping cannot be validated.', 'mapping_blocked', 422, ['errors' => $errors]);
        }

        $rows = $this->loadSourceRows($mapping->source);

        return DB::transaction(function () use ($actor, $mapping, $version, $rows): DataMigrationValidationRun {
            $run = DataMigrationValidationRun::create([
                'data_migration_mapping_version_id' => $version->id,
                'status' => DataMigrationValidationRun::STATUS_RUNNING,
                'started_at' => now(),
            ]);

            $summary = [
                'accepted' => 0,
                'corrected' => 0,
                'rejected' => 0,
                'duplicate_review' => 0,
            ];

            $seenKeys = [];

            for ($i = 1, $iMax = count($rows); $i < $iMax; $i++) {
                $mapped = $this->mapRow($mapping, $version, $rows[$i], $i);
                $outcome = $mapped['outcome'];

                $duplicateKey = $mapped['duplicate_key'] ?? '';
                if ($duplicateKey !== '' && $duplicateKey !== '|') {
                    if (isset($seenKeys[$duplicateKey])) {
                        $outcome = DataMigrationValidationResult::OUTCOME_DUPLICATE_REVIEW;
                        $mapped['reasons'][] = 'Duplicate identity match within source file.';
                    } else {
                        $seenKeys[$duplicateKey] = true;
                    }
                }

                $summary[$outcome] = ($summary[$outcome] ?? 0) + 1;

                DataMigrationValidationResult::create([
                    'data_migration_validation_run_id' => $run->id,
                    'source_row_number' => $i,
                    'outcome' => $outcome,
                    'reasons' => $mapped['reasons'],
                    'original_data' => $mapped['original_data'],
                    'mapped_data' => $mapped['mapped_data'],
                ]);
            }

            $run->update([
                'status' => DataMigrationValidationRun::STATUS_COMPLETED,
                'summary' => $summary,
                'completed_at' => now(),
            ]);

            $this->audit($actor, 'data_migration.validation_completed', $mapping->source, [
                'mapping_id' => $mapping->id,
                'run_id' => $run->id,
                'summary' => $summary,
            ]);

            return $run->fresh('results');
        });
    }

    public function showValidationRun(User $actor, DataMigrationValidationRun $run): DataMigrationValidationRun
    {
        $this->assertCan($actor, 'migration.read');

        return $run->load(['results', 'version.mapping.source']);
    }

    public function approveMapping(User $actor, DataMigrationMapping $mapping): DataMigrationMapping
    {
        $this->assertCan($actor, 'migration.manage');

        $version = $this->currentVersion($mapping);
        if ($version->status === DataMigrationMappingVersion::STATUS_BLOCKED) {
            throw new DataMigrationException('Blocked mapping cannot be approved.', 'mapping_blocked', 422);
        }

        $hasValidation = DataMigrationValidationRun::query()
            ->where('data_migration_mapping_version_id', $version->id)
            ->where('status', DataMigrationValidationRun::STATUS_COMPLETED)
            ->exists();

        if (! $hasValidation) {
            throw new DataMigrationException('Complete a validation run before approving the mapping.', 'validation_required', 422);
        }

        $version->update([
            'status' => DataMigrationMappingVersion::STATUS_APPROVED,
            'approved_at' => now(),
        ]);

        $mapping->update(['status' => DataMigrationMapping::STATUS_APPROVED]);

        return $mapping->fresh(['versions', 'source.profile']);
    }

    /**
     * @return list<array<string, string>>
     */
    public function sourceRows(DataMigrationSource $source): array
    {
        return $this->loadSourceRows($source);
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    public function mappedRow(DataMigrationMapping $mapping, array $row, int $rowNumber): array
    {
        return $this->mapRow($mapping, $this->currentVersion($mapping), $row, $rowNumber);
    }

    public function mappingVersion(DataMigrationMapping $mapping): DataMigrationMappingVersion
    {
        return $this->currentVersion($mapping);
    }

    /**
     * @return array<string, mixed>
     */
    public function formatSource(DataMigrationSource $source): array
    {
        return [
            'id' => $source->id,
            'reference' => $source->reference,
            'name' => $source->name,
            'source_type' => $source->source_type,
            'branch_id' => $source->branch_id,
            'status' => $source->status,
            'row_count' => $source->row_count,
            'classification' => $source->classification,
            'retention_ends_at' => $source->retention_ends_at?->toIso8601String(),
            'has_profile' => $source->relationLoaded('profile') ? $source->profile !== null : null,
            'profile' => $source->relationLoaded('profile') && $source->profile
                ? $this->formatProfile($source->profile)
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formatProfile(DataMigrationProfile $profile): array
    {
        return [
            'columns' => $profile->columns,
            'summary' => $profile->summary,
            'sensitive_fields' => $profile->sensitive_fields,
            'duplicate_keys' => $profile->duplicate_keys,
            'profiled_at' => $profile->profiled_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formatMapping(DataMigrationMapping $mapping): array
    {
        $version = $mapping->relationLoaded('versions')
            ? $mapping->versions->firstWhere('version_number', $mapping->current_version)
            : $this->currentVersion($mapping);

        return [
            'id' => $mapping->id,
            'name' => $mapping->name,
            'target_entity' => $mapping->target_entity,
            'branch_id' => $mapping->branch_id,
            'status' => $mapping->status,
            'current_version' => $mapping->current_version,
            'version' => $version ? [
                'version_number' => $version->version_number,
                'field_mappings' => $version->field_mappings,
                'transformations' => $version->transformations,
                'defaults' => $version->defaults,
                'duplicate_rules' => $version->duplicate_rules,
                'validation_errors' => $version->validation_errors,
                'status' => $version->status,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formatValidationRun(DataMigrationValidationRun $run): array
    {
        return [
            'id' => $run->id,
            'status' => $run->status,
            'summary' => $run->summary,
            'started_at' => $run->started_at?->toIso8601String(),
            'completed_at' => $run->completed_at?->toIso8601String(),
            'results' => $run->relationLoaded('results')
                ? $run->results->map(fn (DataMigrationValidationResult $result) => [
                    'source_row_number' => $result->source_row_number,
                    'outcome' => $result->outcome,
                    'reasons' => $result->reasons,
                ])->values()->all()
                : null,
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    private function loadSourceRows(DataMigrationSource $source): array
    {
        if ($source->source_type === 'membership_system') {
            return $this->membershipSystemRows($source);
        }

        if ($source->source_type === 'database') {
            $columns = $source->connection_config['columns'] ?? [];
            $sampleRows = $source->connection_config['sample_rows'] ?? [];

            return array_merge([array_combine($columns, $columns) ?: []], $sampleRows);
        }

        if ($source->storage_path === null) {
            return [];
        }

        $binary = Storage::disk($source->storage_disk)->get($source->storage_path);

        return $this->parseTabular($binary);
    }

    /**
     * @return list<array<string, string>>
     */
    private function parseTabular(string $binary): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($binary)) ?: [];
        $rows = [];
        $headers = null;

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $cells = str_getcsv($line);
            if ($headers === null) {
                $headers = array_map(fn ($header) => trim((string) $header), $cells);
                $rows[] = array_combine($headers, $headers) ?: [];

                continue;
            }

            $row = [];
            foreach ($headers as $index => $header) {
                $row[$header] = trim((string) ($cells[$index] ?? ''));
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return array<string, mixed>
     */
    private function buildProfile(array $rows): array
    {
        if (count($rows) <= 1) {
            return [
                'columns' => [],
                'summary' => ['row_count' => 0],
                'sensitive_fields' => [],
                'duplicate_keys' => [],
            ];
        }

        $headers = array_values(array_filter(array_keys($rows[1] ?? [])));
        $dataRows = array_slice($rows, 1);
        $columns = [];
        $sensitive = [];
        $duplicateKeys = [];

        foreach ($headers as $header) {
            $values = array_map(fn (array $row) => $row[$header] ?? '', $dataRows);
            $missing = count(array_filter($values, fn (string $value) => $value === ''));
            $invalid = count(array_filter($values, fn (string $value) => $this->isInvalidEmailColumn($header, $value)));
            $inferredType = $this->inferType($values);
            $sensitivity = $this->columnSensitivity($header);
            if ($sensitivity !== 'none') {
                $sensitive[] = ['column' => $header, 'classification' => $sensitivity];
            }

            $columns[] = [
                'name' => $header,
                'inferred_type' => $inferredType,
                'missing_count' => $missing,
                'invalid_count' => $invalid,
                'sample_values' => array_values(array_slice(array_unique(array_filter($values)), 0, 3)),
                'sensitivity' => $sensitivity,
            ];
        }

        foreach ([['email'], ['membership_id'], ['phone']] as $keyColumns) {
            if (array_intersect($keyColumns, $headers) === $keyColumns) {
                $counts = [];
                foreach ($dataRows as $row) {
                    $key = implode('|', array_map(fn (string $col) => strtolower($row[$col] ?? ''), $keyColumns));
                    if ($key !== '|' && $key !== '') {
                        $counts[$key] = ($counts[$key] ?? 0) + 1;
                    }
                }
                $groups = count(array_filter($counts, fn (int $count) => $count > 1));
                if ($groups > 0) {
                    $duplicateKeys[] = ['columns' => $keyColumns, 'duplicate_groups' => $groups];
                }
            }
        }

        return [
            'columns' => $columns,
            'summary' => [
                'row_count' => count($dataRows),
                'column_count' => count($columns),
                'missing_cells' => array_sum(array_column($columns, 'missing_count')),
                'invalid_cells' => array_sum(array_column($columns, 'invalid_count')),
            ],
            'sensitive_fields' => $sensitive,
            'duplicate_keys' => $duplicateKeys,
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return list<array<string, mixed>>
     */
    private function validateMappingDefinition(DataMigrationMapping $mapping, array $definition): array
    {
        $errors = [];
        $entity = config('data_migrations.target_entities.' . $mapping->target_entity, []);
        $required = $entity['required_fields'] ?? [];
        $fieldMappings = $definition['field_mappings'] ?? [];
        $defaults = $definition['defaults'] ?? [];
        $transformations = $definition['transformations'] ?? [];
        $duplicateRules = $definition['duplicate_rules'] ?? [];

        foreach ($required as $field) {
            if (! array_key_exists($field, $fieldMappings) && ! array_key_exists($field, $defaults)) {
                $errors[] = [
                    'code' => 'unmapped_required_field',
                    'field' => $field,
                    'message' => "Required target field '{$field}' is not mapped or defaulted.",
                ];
            }
        }

        foreach ($transformations as $field => $transform) {
            if (! in_array($transform, config('data_migrations.transformations', []), true)) {
                $errors[] = [
                    'code' => 'invalid_transformation',
                    'field' => $field,
                    'message' => "Transformation '{$transform}' is not permitted.",
                ];
            }
        }

        $matchOn = $duplicateRules['match_on'] ?? [];
        if ($matchOn === []) {
            $errors[] = [
                'code' => 'missing_duplicate_rules',
                'message' => 'Duplicate detection rules are required.',
            ];
        } elseif (count($matchOn) > 1 && ($duplicateRules['strategy'] ?? '') !== 'review') {
            $errors[] = [
                'code' => 'ambiguous_identity_match',
                'message' => 'Multiple match columns require duplicate review strategy.',
            ];
        }

        $strategy = $duplicateRules['strategy'] ?? '';
        if ($strategy !== '' && ! in_array($strategy, config('data_migrations.duplicate_strategies', []), true)) {
            $errors[] = [
                'code' => 'invalid_duplicate_strategy',
                'message' => "Duplicate strategy '{$strategy}' is not permitted.",
            ];
        }

        return $errors;
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    private function mapRow(DataMigrationMapping $mapping, DataMigrationMappingVersion $version, array $row, int $rowNumber): array
    {
        $mapped = $version->defaults ?? [];
        $reasons = [];
        $corrected = false;

        foreach ($version->field_mappings ?? [] as $targetField => $sourceColumn) {
            $value = $row[$sourceColumn] ?? '';
            $transform = $version->transformations[$targetField] ?? null;
            $transformed = $this->applyTransform((string) $value, $transform);
            if ($transformed !== $value && $value !== '') {
                $corrected = true;
            }
            $mapped[$targetField] = $transformed;
        }

        foreach (config('data_migrations.target_entities.' . $mapping->target_entity . '.required_fields', []) as $requiredField) {
            if (($mapped[$requiredField] ?? '') === '') {
                $reasons[] = "Missing required value for {$requiredField}.";
            }
        }

        if (isset($mapped['email']) && $mapped['email'] !== '' && ! filter_var($mapped['email'], FILTER_VALIDATE_EMAIL)) {
            $reasons[] = 'Invalid email format.';
        }

        $outcome = $reasons === []
            ? ($corrected ? DataMigrationValidationResult::OUTCOME_CORRECTED : DataMigrationValidationResult::OUTCOME_ACCEPTED)
            : DataMigrationValidationResult::OUTCOME_REJECTED;

        $duplicateKey = null;
        $matchOn = $version->duplicate_rules['match_on'] ?? [];
        if ($matchOn !== []) {
            $duplicateKey = implode('|', array_map(fn (string $field) => strtolower((string) ($mapped[$field] ?? '')), $matchOn));
        }

        return [
            'outcome' => $outcome,
            'reasons' => $reasons,
            'original_data' => $this->safeRowPreview($row),
            'mapped_data' => $this->safeMappedPreview($mapped),
            'duplicate_key' => $duplicateKey,
        ];
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, string>
     */
    private function safeRowPreview(array $row): array
    {
        $preview = [];
        foreach ($row as $key => $value) {
            $preview[$key] = $this->columnSensitivity((string) $key) === 'none'
                ? Str::limit($value, 80, '')
                : '[redacted]';
        }

        return $preview;
    }

    /**
     * @param  array<string, mixed>  $mapped
     * @return array<string, mixed>
     */
    private function safeMappedPreview(array $mapped): array
    {
        $preview = [];
        foreach ($mapped as $key => $value) {
            if (in_array($key, ['email', 'phone'], true) && $value !== '') {
                $preview[$key] = '[redacted]';
            } else {
                $preview[$key] = $value;
            }
        }

        return $preview;
    }

    private function applyTransform(string $value, ?string $transform): string
    {
        return match ($transform) {
            'trim' => trim($value),
            'lowercase' => strtolower($value),
            'uppercase' => strtoupper($value),
            'title_case' => ucwords(strtolower($value)),
            'phone_normalize' => preg_replace('/[^0-9+]/', '', $value) ?? $value,
            default => $value,
        };
    }

    /**
     * @param  list<string>  $values
     */
    private function inferType(array $values): string
    {
        $nonEmpty = array_values(array_filter($values, fn (string $value) => $value !== ''));
        if ($nonEmpty === []) {
            return 'empty';
        }

        if (count(array_filter($nonEmpty, fn (string $value) => filter_var($value, FILTER_VALIDATE_EMAIL))) === count($nonEmpty)) {
            return 'email';
        }

        if (count(array_filter($nonEmpty, fn (string $value) => is_numeric($value))) === count($nonEmpty)) {
            return 'number';
        }

        return 'string';
    }

    private function columnSensitivity(string $column): string
    {
        $lower = strtolower($column);
        foreach (config('data_migrations.sensitive_column_patterns', []) as $pattern) {
            if (str_contains($lower, $pattern)) {
                return 'restricted';
            }
        }

        if (in_array($lower, ['email', 'phone', 'date_of_birth', 'dob'], true)) {
            return 'internal';
        }

        return 'none';
    }

    private function isInvalidEmailColumn(string $header, string $value): bool
    {
        if ($value === '') {
            return false;
        }

        return str_contains(strtolower($header), 'email') && ! filter_var($value, FILTER_VALIDATE_EMAIL);
    }

    /**
     * @return list<array<string, string>>
     */
    private function membershipSystemRows(DataMigrationSource $source): array
    {
        $system = (string) ($source->connection_config['system'] ?? '');
        $definition = config('data_migrations.membership_systems.' . $system, []);
        $columns = $definition['columns'] ?? [];
        $header = array_combine($columns, $columns) ?: [];
        $sample = $source->connection_config['sample_rows'] ?? [
            array_combine($columns, ['Ada', 'Member', 'ada@example.com', '08012345678', 'M-001']) ?: [],
        ];

        return array_merge([$header], $sample);
    }

    private function currentVersion(DataMigrationMapping $mapping): DataMigrationMappingVersion
    {
        return DataMigrationMappingVersion::query()
            ->where('data_migration_mapping_id', $mapping->id)
            ->where('version_number', $mapping->current_version)
            ->firstOrFail();
    }

    private function assertProfiled(DataMigrationSource $source): void
    {
        if ($source->status !== DataMigrationSource::STATUS_PROFILED) {
            throw new DataMigrationException('Source must be profiled before mapping.', 'source_not_profiled', 422);
        }
    }

    private function safeFilename(string $filename): string
    {
        $basename = basename($filename);
        $sanitized = preg_replace('/[^A-Za-z0-9._-]/', '_', $basename) ?: 'source.csv';

        return $sanitized;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function audit(User $actor, string $action, DataMigrationSource $source, array $extra = []): void
    {
        $this->audit->record(
            actor: $actor,
            action: $action,
            category: AuditEvent::CATEGORY_BUSINESS,
            module: 'migration',
            branchId: $source->branch_id,
            subjectType: DataMigrationSource::class,
            subjectId: $source->id,
            after: array_merge(['reference' => $source->reference], $extra),
        );
    }

    private function assertCan(User $actor, string $action): void
    {
        if (! $this->authorization->allows($actor, $action)) {
            throw new AuthorizationException('Forbidden.');
        }
    }
}
