<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\AuditEvent;
use App\Models\CareCase;
use App\Models\ChurchDocument;
use App\Models\ChurchEvent;
use App\Models\ChurchGroup;
use App\Models\ChurchSearchEntry;
use App\Models\ChurchSearchSyncFailure;
use App\Models\CustomReport;
use App\Models\Household;
use App\Models\Member;
use App\Models\Organization;
use App\Models\ServiceTeam;
use App\Models\User;
use App\Models\WelfareRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Story 14.3: permission-filtered global church record search.
 */
class GlobalSearchService
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
        $this->assertCan($actor, 'search.global');

        $types = [];
        foreach ($this->allowedRecordTypes($actor) as $type => $definition) {
            $types[$type] = [
                'label' => $definition['label'] ?? $type,
                'route' => $definition['route'] ?? null,
            ];
        }

        return [
            'record_types' => $types,
            'query_min_length' => config('global_search.query_min_length', 2),
            'target_duration_ms' => config('global_search.target_duration_ms', 2000),
            'max_results_total' => config('global_search.max_results_total', 50),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function search(User $actor, string $query, array $filters = []): array
    {
        $this->assertCan($actor, 'search.global');

        $term = trim($query);
        $minLength = (int) config('global_search.query_min_length', 2);
        if (mb_strlen($term) < $minLength) {
            throw new GlobalSearchException('Search query must be at least ' . $minLength . ' characters.', 'invalid_query', 422);
        }

        $started = microtime(true);
        $allowedTypes = array_keys($this->allowedRecordTypes($actor));
        $typeFilter = $filters['record_type'] ?? null;
        if ($typeFilter !== null) {
            $allowedTypes = array_values(array_intersect($allowedTypes, [(string) $typeFilter]));
        }

        if ($allowedTypes === []) {
            return $this->emptySearchResponse($term, $started);
        }

        $maxTotal = (int) config('global_search.max_results_total', 50);
        $maxPerType = (int) config('global_search.max_results_per_type', 10);
        $targetMs = (int) config('global_search.target_duration_ms', 2000);

        $entries = ChurchSearchEntry::query()
            ->where('status', ChurchSearchEntry::STATUS_ACTIVE)
            ->whereIn('record_type', $allowedTypes)
            ->where(function (Builder $builder) use ($term): void {
                $builder->where('title', 'like', '%' . $term . '%')
                    ->orWhere('keywords', 'like', '%' . $term . '%');
            })
            ->when(
                ! $this->authorization->allows($actor, 'organizations.read') && $actor->branch_id !== null,
                fn (Builder $query) => $query->where(function (Builder $scoped) use ($actor): void {
                    $scoped->where('branch_id', $actor->branch_id)->orWhereNull('branch_id');
                }),
            )
            ->orderByDesc('source_updated_at')
            ->limit($maxTotal * 2)
            ->get();

        $grouped = [];
        $included = 0;

        foreach ($entries as $entry) {
            if ((microtime(true) - $started) * 1000 > $targetMs) {
                break;
            }

            if ($included >= $maxTotal) {
                break;
            }

            if (($grouped[$entry->record_type]['items'] ?? []) !== []
                && count($grouped[$entry->record_type]['items']) >= $maxPerType) {
                continue;
            }

            if (! $this->liveAuthorize($actor, $entry)) {
                continue;
            }

            $grouped[$entry->record_type]['label'] = config("global_search.record_types.{$entry->record_type}.label", $entry->record_type);
            $grouped[$entry->record_type]['items'][] = $this->formatResult($entry);
            $included++;
        }

        $durationMs = (int) round((microtime(true) - $started) * 1000);

        return [
            'query' => $term,
            'duration_ms' => $durationMs,
            'within_target' => $durationMs <= $targetMs,
            'total_results' => $included,
            'groups' => collect($grouped)
                ->map(fn (array $group, string $type) => [
                    'record_type' => $type,
                    'label' => $group['label'],
                    'items' => $group['items'],
                    'count' => count($group['items']),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function resolve(User $actor, string $recordType, int $recordId): array
    {
        $this->assertCan($actor, 'search.global');

        $definition = config("global_search.record_types.{$recordType}");
        if (! is_array($definition)) {
            throw new GlobalSearchException('Unsupported record type.', 'unsupported_record_type', 404);
        }

        if (! $this->authorization->allows($actor, (string) $definition['permission'])) {
            $this->auditDenied($actor, $recordType, $recordId);

            throw new AuthorizationException('Forbidden.');
        }

        $entry = ChurchSearchEntry::query()
            ->where('record_type', $recordType)
            ->where('record_id', $recordId)
            ->first();

        if ($entry !== null && ! $this->liveAuthorize($actor, $entry)) {
            $this->auditDenied($actor, $recordType, $recordId);

            throw new AuthorizationException('Forbidden.');
        }

        if ($entry === null) {
            $entry = $this->buildEntryForRecord($recordType, $recordId);
            if ($entry === null || ! $this->liveAuthorize($actor, $entry)) {
                $this->auditDenied($actor, $recordType, $recordId);

                throw new AuthorizationException('Forbidden.');
            }
        }

        return [
            'record_type' => $recordType,
            'record_id' => $recordId,
            'title' => $entry->title,
            'route' => (string) ($definition['route'] ?? '/dashboard'),
            'authorized' => true,
        ];
    }

    /**
     * @return Collection<int, ChurchSearchSyncFailure>
     */
    public function listSyncFailures(User $actor): Collection
    {
        $this->assertCan($actor, 'search.sync');

        return ChurchSearchSyncFailure::query()
            ->whereIn('status', [ChurchSearchSyncFailure::STATUS_PENDING, ChurchSearchSyncFailure::STATUS_FAILED])
            ->orderByDesc('id')
            ->limit(100)
            ->get();
    }

    /**
     * @return array{processed: int, resolved: int, failed: int}
     */
    public function processRetriesFor(User $actor): array
    {
        $this->assertCan($actor, 'search.sync');

        return $this->processRetries();
    }

    /**
     * @return array{processed: int, resolved: int, failed: int}
     */
    public function processRetries(): array
    {
        $processed = 0;
        $resolved = 0;
        $failed = 0;
        $maxAttempts = (int) config('global_search.sync_max_attempts', 3);

        ChurchSearchSyncFailure::query()
            ->where('status', ChurchSearchSyncFailure::STATUS_PENDING)
            ->where(function (Builder $query): void {
                $query->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now());
            })
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->each(function (ChurchSearchSyncFailure $failure) use (&$processed, &$resolved, &$failed, $maxAttempts): void {
                $processed++;
                $failure->update([
                    'attempts' => $failure->attempts + 1,
                    'last_attempted_at' => now(),
                ]);

                try {
                    if ($failure->operation === ChurchSearchSyncFailure::OPERATION_DELETE) {
                        $this->deleteIndexedRecord((string) $failure->record_type, (int) $failure->record_id);
                    } else {
                        $this->syncRecord((string) $failure->record_type, (int) $failure->record_id);
                    }

                    $failure->update([
                        'status' => ChurchSearchSyncFailure::STATUS_RESOLVED,
                        'resolved_at' => now(),
                    ]);
                    $resolved++;
                } catch (\Throwable $exception) {
                    if ($failure->attempts >= $maxAttempts) {
                        $failure->update(['status' => ChurchSearchSyncFailure::STATUS_FAILED]);
                        $failed++;
                    } else {
                        $failure->update([
                            'next_retry_at' => now()->addMinutes((int) config('global_search.sync_retry_minutes', 15)),
                            'error_message' => $exception->getMessage(),
                        ]);
                    }
                }
            });

        return compact('processed', 'resolved', 'failed');
    }

    /**
     * @return array{indexed: int, removed: int, failures: int}
     */
    public function rebuildIndex(): array
    {
        $indexed = 0;
        $removed = 0;
        $failures = 0;

        foreach (array_keys(config('global_search.record_types', [])) as $type) {
            foreach ($this->sourceRecords($type) as $record) {
                try {
                    $this->upsertFromModel($type, $record);
                    $indexed++;
                } catch (\Throwable $exception) {
                    $this->recordSyncFailure($type, (int) $record->getKey(), ChurchSearchSyncFailure::OPERATION_UPSERT, $exception->getMessage());
                    $failures++;
                }
            }
        }

        ChurchSearchEntry::query()
            ->where('status', ChurchSearchEntry::STATUS_ACTIVE)
            ->chunkById(200, function ($entries) use (&$removed): void {
                foreach ($entries as $entry) {
                    if ($this->buildEntryForRecord($entry->record_type, (int) $entry->record_id) === null) {
                        $entry->update([
                            'status' => ChurchSearchEntry::STATUS_DELETED,
                            'indexed_at' => now(),
                        ]);
                        $removed++;
                    }
                }
            });

        return compact('indexed', 'removed', 'failures');
    }

    public function syncRecord(string $recordType, int $recordId): void
    {
        $model = $this->findSourceModel($recordType, $recordId);
        if ($model === null) {
            $this->deleteIndexedRecord($recordType, $recordId);

            return;
        }

        $this->upsertFromModel($recordType, $model);
    }

    /**
     * @return array<string, mixed>
     */
    public function formatSyncFailure(ChurchSearchSyncFailure $failure): array
    {
        return [
            'id' => $failure->id,
            'record_type' => $failure->record_type,
            'record_id' => $failure->record_id,
            'operation' => $failure->operation,
            'error_message' => $failure->error_message,
            'attempts' => $failure->attempts,
            'status' => $failure->status,
            'next_retry_at' => $failure->next_retry_at?->toIso8601String(),
            'last_attempted_at' => $failure->last_attempted_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function allowedRecordTypes(User $actor): array
    {
        $allowed = [];
        foreach (config('global_search.record_types', []) as $type => $definition) {
            if ($this->authorization->allows($actor, (string) ($definition['permission'] ?? 'search.global'))) {
                $allowed[$type] = $definition;
            }
        }

        return $allowed;
    }

    private function upsertFromModel(string $recordType, Model $model): ChurchSearchEntry
    {
        $payload = $this->mapRecordToIndex($recordType, $model);
        if ($payload === null) {
            $this->deleteIndexedRecord($recordType, (int) $model->getKey());
            throw new \RuntimeException('Record is not eligible for indexing.');
        }

        return ChurchSearchEntry::query()->updateOrCreate(
            [
                'record_type' => $recordType,
                'record_id' => (int) $model->getKey(),
            ],
            array_merge($payload, [
                'indexed_at' => now(),
            ]),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mapRecordToIndex(string $recordType, Model $model): ?array
    {
        $definition = config("global_search.record_types.{$recordType}", []);

        return match ($recordType) {
            'member' => $this->mapMember($model, $definition),
            'household' => $this->mapHousehold($model, $definition),
            'branch' => $this->mapBranch($model, $definition),
            'service_team' => $this->mapServiceTeam($model, $definition),
            'church_group' => $this->mapChurchGroup($model, $definition),
            'church_event' => $this->mapChurchEvent($model, $definition),
            'attendance_record' => $this->mapAttendanceRecord($model, $definition),
            'welfare_request' => $this->mapWelfareRequest($model, $definition),
            'care_case' => $this->mapCareCase($model, $definition),
            'custom_report' => $this->mapCustomReport($model, $definition),
            'church_document' => $this->mapChurchDocument($model, $definition),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>|null
     */
    private function mapMember(Model $model, array $definition): ?array
    {
        /** @var Member $member */
        $member = $model;
        if ($member->archived_at !== null || $member->merged_into_id !== null) {
            return null;
        }

        $title = trim($member->first_name . ' ' . $member->last_name);
        $snippet = $member->membership_id . ' · ' . ($member->lifecycle_status ?? 'active');

        return $this->basePayload($definition, 'member', $member->id, $member->branch_id, $title, $snippet, [
            $member->membership_id,
            $member->first_name,
            $member->last_name,
            $member->preferred_name,
            $member->email,
        ], 'internal', ChurchSearchEntry::STATUS_ACTIVE, $member->updated_at);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>|null
     */
    private function mapHousehold(Model $model, array $definition): ?array
    {
        /** @var Household $household */
        $household = $model;

        return $this->basePayload($definition, 'household', $household->id, $household->branch_id, $household->name, 'Household record', [
            $household->name,
            $household->shared_email,
            $household->shared_phone,
        ], 'internal', ChurchSearchEntry::STATUS_ACTIVE, $household->updated_at);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>|null
     */
    private function mapBranch(Model $model, array $definition): ?array
    {
        /** @var Organization $organization */
        $organization = $model;

        return $this->basePayload($definition, 'branch', $organization->id, $organization->id, $organization->name, ucfirst((string) $organization->type), [
            $organization->name,
            $organization->identifier,
            $organization->type,
        ], 'internal', ChurchSearchEntry::STATUS_ACTIVE, $organization->updated_at);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>|null
     */
    private function mapServiceTeam(Model $model, array $definition): ?array
    {
        /** @var ServiceTeam $team */
        $team = $model;
        if ($team->status === ServiceTeam::STATUS_ARCHIVED) {
            return null;
        }

        return $this->basePayload($definition, 'service_team', $team->id, $team->branch_id, $team->name, $team->category ?? 'Service team', [
            $team->name,
            $team->category,
            $team->description,
        ], 'internal', ChurchSearchEntry::STATUS_ACTIVE, $team->updated_at);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>|null
     */
    private function mapChurchGroup(Model $model, array $definition): ?array
    {
        /** @var ChurchGroup $group */
        $group = $model;
        if ($group->status === ChurchGroup::STATUS_ARCHIVED) {
            return null;
        }

        return $this->basePayload($definition, 'church_group', $group->id, $group->branch_id, $group->name, $group->group_type ?? 'Group', [
            $group->name,
            $group->group_type,
            $group->description,
        ], 'internal', ChurchSearchEntry::STATUS_ACTIVE, $group->updated_at);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>|null
     */
    private function mapChurchEvent(Model $model, array $definition): ?array
    {
        /** @var ChurchEvent $event */
        $event = $model;
        if (! in_array($event->status, [ChurchEvent::STATUS_PUBLISHED, ChurchEvent::STATUS_COMPLETED, ChurchEvent::STATUS_CLOSED], true)) {
            return null;
        }

        $snippet = ($event->event_date?->format('Y-m-d') ?? 'Scheduled') . ' · ' . ($event->venue ?? 'Venue TBC');

        return $this->basePayload($definition, 'church_event', $event->id, $event->branch_id, $event->title, $snippet, [
            $event->title,
            $event->description,
            $event->venue,
        ], 'internal', ChurchSearchEntry::STATUS_ACTIVE, $event->updated_at);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>|null
     */
    private function mapAttendanceRecord(Model $model, array $definition): ?array
    {
        /** @var AttendanceRecord $record */
        $record = $model;
        $title = 'Attendance · ' . ($record->gathering_date?->format('Y-m-d') ?? 'Unknown date');
        $snippet = ucfirst((string) $record->status) . ' · ' . ($record->service_type ?? 'gathering');

        return $this->basePayload($definition, 'attendance_record', $record->id, $record->branch_id, $title, $snippet, [
            $record->service_type,
            $record->status,
            $record->gathering_date?->format('Y-m-d'),
        ], 'internal', ChurchSearchEntry::STATUS_ACTIVE, $record->updated_at);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>|null
     */
    private function mapWelfareRequest(Model $model, array $definition): ?array
    {
        /** @var WelfareRequest $request */
        $request = $model;
        if ($request->status === WelfareRequest::STATUS_CLOSED && $request->updated_at?->lt(now()->subYears(2))) {
            return null;
        }

        $title = $request->case_number ?? ('Welfare request #' . $request->id);
        $snippet = ucfirst((string) $request->request_type) . ' · ' . ucfirst((string) $request->status);

        return $this->basePayload($definition, 'welfare_request', $request->id, $request->branch_id, $title, $snippet, [
            $request->case_number,
            $request->request_type,
            $request->status,
        ], (string) ($definition['sensitivity'] ?? 'restricted'), ChurchSearchEntry::STATUS_ACTIVE, $request->updated_at);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>|null
     */
    private function mapCareCase(Model $model, array $definition): ?array
    {
        /** @var CareCase $case */
        $case = $model;
        if ($case->status === CareCase::STATUS_CLOSED && $case->closed_at?->lt(now()->subYears(2))) {
            return null;
        }

        $title = $case->case_number ?? ('Care case #' . $case->id);
        $snippet = ucfirst((string) $case->category) . ' · ' . ucfirst((string) $case->status);

        return $this->basePayload($definition, 'care_case', $case->id, $case->branch_id, $title, $snippet, [
            $case->case_number,
            $case->category,
            $case->status,
        ], (string) ($definition['sensitivity'] ?? 'restricted'), ChurchSearchEntry::STATUS_ACTIVE, $case->updated_at);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>|null
     */
    private function mapCustomReport(Model $model, array $definition): ?array
    {
        /** @var CustomReport $report */
        $report = $model;
        if ($report->status !== CustomReport::STATUS_PUBLISHED) {
            return null;
        }

        return $this->basePayload($definition, 'custom_report', $report->id, $report->branch_id, $report->name, 'Published custom report', [
            $report->name,
        ], 'internal', ChurchSearchEntry::STATUS_ACTIVE, $report->updated_at);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>|null
     */
    private function mapChurchDocument(Model $model, array $definition): ?array
    {
        /** @var ChurchDocument $document */
        $document = $model;
        if ($document->lifecycle_status !== ChurchDocument::LIFECYCLE_ACTIVE || $document->status !== ChurchDocument::STATUS_ACTIVE) {
            return null;
        }

        $snippet = $document->category . ' · ' . $document->classification;

        return $this->basePayload($definition, 'church_document', $document->id, $document->branch_id, $document->title, $snippet, [
            $document->title,
            $document->category,
            $document->record_type,
        ], $document->classification, ChurchSearchEntry::STATUS_ACTIVE, $document->updated_at);
    }

    /**
     * @param  list<string|null>  $keywords
     * @return array<string, mixed>
     */
    private function basePayload(
        array $definition,
        string $recordType,
        int $recordId,
        ?int $branchId,
        string $title,
        string $snippet,
        array $keywords,
        string $sensitivity,
        string $status,
        mixed $sourceUpdatedAt,
    ): array {
        $normalizedKeywords = collect($keywords)
            ->filter()
            ->map(fn ($value) => Str::lower((string) $value))
            ->implode(' ');

        return [
            'branch_id' => $branchId,
            'title' => Str::limit($title, 180, ''),
            'snippet' => Str::limit($snippet, 500, ''),
            'keywords' => trim($normalizedKeywords . ' ' . Str::lower($title) . ' ' . Str::lower($snippet)),
            'required_permission' => (string) ($definition['permission'] ?? 'search.global'),
            'sensitivity' => $sensitivity,
            'status' => $status,
            'source_updated_at' => $sourceUpdatedAt,
        ];
    }

  private function liveAuthorize(User $actor, ChurchSearchEntry $entry): bool
    {
        if ($entry->status !== ChurchSearchEntry::STATUS_ACTIVE) {
            return false;
        }

        if (! $this->authorization->allows($actor, $entry->required_permission)) {
            return false;
        }

        if ($entry->branch_id !== null && ! $this->branchReadable($actor, (int) $entry->branch_id)) {
            return false;
        }

        $model = $this->findSourceModel($entry->record_type, (int) $entry->record_id);
        if ($model === null) {
            return false;
        }

        $fresh = $this->mapRecordToIndex($entry->record_type, $model);

        return $fresh !== null;
    }

    private function buildEntryForRecord(string $recordType, int $recordId): ?ChurchSearchEntry
    {
        $model = $this->findSourceModel($recordType, $recordId);
        if ($model === null) {
            return null;
        }

        $payload = $this->mapRecordToIndex($recordType, $model);
        if ($payload === null) {
            return null;
        }

        return new ChurchSearchEntry(array_merge($payload, [
            'record_type' => $recordType,
            'record_id' => $recordId,
        ]));
    }

    private function findSourceModel(string $recordType, int $recordId): ?Model
    {
        return match ($recordType) {
            'member' => Member::query()->find($recordId),
            'household' => Household::query()->find($recordId),
            'branch' => Organization::query()->find($recordId),
            'service_team' => ServiceTeam::query()->find($recordId),
            'church_group' => ChurchGroup::query()->find($recordId),
            'church_event' => ChurchEvent::query()->find($recordId),
            'attendance_record' => AttendanceRecord::query()->find($recordId),
            'welfare_request' => WelfareRequest::query()->find($recordId),
            'care_case' => CareCase::query()->find($recordId),
            'custom_report' => CustomReport::query()->find($recordId),
            'church_document' => ChurchDocument::query()->find($recordId),
            default => null,
        };
    }

    /**
     * @return Collection<int, Model>
     */
    private function sourceRecords(string $recordType): Collection
    {
        return match ($recordType) {
            'member' => Member::query()->whereNull('archived_at')->whereNull('merged_into_id')->get(),
            'household' => Household::query()->get(),
            'branch' => Organization::query()->get(),
            'service_team' => ServiceTeam::query()->where('status', '!=', ServiceTeam::STATUS_ARCHIVED)->get(),
            'church_group' => ChurchGroup::query()->where('status', '!=', ChurchGroup::STATUS_ARCHIVED)->get(),
            'church_event' => ChurchEvent::query()->whereIn('status', [ChurchEvent::STATUS_PUBLISHED, ChurchEvent::STATUS_COMPLETED, ChurchEvent::STATUS_CLOSED])->get(),
            'attendance_record' => AttendanceRecord::query()->limit(500)->get(),
            'welfare_request' => WelfareRequest::query()->limit(500)->get(),
            'care_case' => CareCase::query()->limit(500)->get(),
            'custom_report' => CustomReport::query()->where('status', CustomReport::STATUS_PUBLISHED)->get(),
            'church_document' => ChurchDocument::query()->where('lifecycle_status', ChurchDocument::LIFECYCLE_ACTIVE)->where('status', ChurchDocument::STATUS_ACTIVE)->get(),
            default => collect(),
        };
    }

    private function deleteIndexedRecord(string $recordType, int $recordId): void
    {
        ChurchSearchEntry::query()
            ->where('record_type', $recordType)
            ->where('record_id', $recordId)
            ->update([
                'status' => ChurchSearchEntry::STATUS_DELETED,
                'indexed_at' => now(),
            ]);
    }

    private function recordSyncFailure(string $recordType, ?int $recordId, string $operation, string $message): void
    {
        ChurchSearchSyncFailure::create([
            'record_type' => $recordType,
            'record_id' => $recordId,
            'operation' => $operation,
            'error_message' => Str::limit($message, 1000, ''),
            'status' => ChurchSearchSyncFailure::STATUS_PENDING,
            'next_retry_at' => now()->addMinutes((int) config('global_search.sync_retry_minutes', 15)),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatResult(ChurchSearchEntry $entry): array
    {
        $definition = config("global_search.record_types.{$entry->record_type}", []);

        return [
            'record_type' => $entry->record_type,
            'record_id' => $entry->record_id,
            'title' => $entry->title,
            'snippet' => $entry->snippet,
            'branch_id' => $entry->branch_id,
            'sensitivity' => $entry->sensitivity,
            'route' => $definition['route'] ?? null,
        ];
    }

    private function branchReadable(User $actor, int $branchId): bool
    {
        if ($this->authorization->allows($actor, 'organizations.read')) {
            return true;
        }

        return $actor->branch_id !== null && (int) $actor->branch_id === $branchId;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySearchResponse(string $term, float $started): array
    {
        return [
            'query' => $term,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'within_target' => true,
            'total_results' => 0,
            'groups' => [],
        ];
    }

    private function auditDenied(User $actor, string $recordType, int $recordId): void
    {
        $this->audit->record(
            actor: $actor,
            action: 'global_search.access_denied',
            category: AuditEvent::CATEGORY_SECURITY,
            module: 'search',
            branchId: $actor->branch_id,
            subjectType: ChurchSearchEntry::class,
            subjectId: null,
            after: [
                'record_type' => $recordType,
                'record_id' => $recordId,
            ],
        );
    }

    private function assertCan(User $actor, string $action): void
    {
        if (! $this->authorization->allows($actor, $action)) {
            throw new AuthorizationException('Forbidden.');
        }
    }
}
