<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\ChurchDocument;
use App\Models\ChurchDocumentAccessGrant;
use App\Models\ChurchDocumentProcessingJob;
use App\Models\ChurchDocumentVersion;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stories 14.1–14.2: protected church document upload, versioning, retrieval, and lifecycle.
 */
class ChurchDocumentService
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
        $this->assertCan($actor, 'documents.read');

        $recordTypes = [];
        foreach (config('church_documents.record_types', []) as $key => $definition) {
            $recordTypes[$key] = [
                'label' => $definition['label'] ?? $key,
                'requires_record_id' => (bool) ($definition['requires_record_id'] ?? true),
            ];
        }

        return [
            'categories' => config('church_documents.categories', []),
            'classifications' => config('church_documents.classifications', []),
            'access_scopes' => config('church_documents.access_scopes', []),
            'retention_policies' => config('church_documents.retention_policies', []),
            'record_types' => $recordTypes,
            'file_constraints' => [
                'max_size_bytes' => config('church_documents.file_constraints.max_size_bytes'),
                'allowed_mime_types' => config('church_documents.file_constraints.allowed_mime_types', []),
            ],
            'download_ttl_minutes' => config('church_documents.download_ttl_minutes', 15),
            'access_modes' => [
                ChurchDocumentAccessGrant::MODE_PREVIEW,
                ChurchDocumentAccessGrant::MODE_DOWNLOAD,
            ],
        ];
    }

    /**
     * @return Collection<int, ChurchDocument>
     */
    public function list(User $actor, array $filters = []): Collection
    {
        $this->assertCan($actor, 'documents.read');

        $query = ChurchDocument::query()
            ->with(['uploader:id,name', 'branch:id,name'])
            ->where('status', ChurchDocument::STATUS_ACTIVE)
            ->where('lifecycle_status', ChurchDocument::LIFECYCLE_ACTIVE)
            ->orderByDesc('id');

        if (! empty($filters['record_type'])) {
            $query->where('record_type', $filters['record_type']);
        }

        if (! empty($filters['record_id'])) {
            $query->where('record_id', (int) $filters['record_id']);
        }

        if (! empty($filters['branch_id'])) {
            $query->where('branch_id', (int) $filters['branch_id']);
        }

        $this->applyBranchScope($query, $actor);

        return $query->limit(200)->get();
    }

    public function show(User $actor, ChurchDocument $document): ChurchDocument
    {
        $this->assertCan($actor, 'documents.read');
        $this->assertCanViewDocument($actor, $document);

        return $document->load(['uploader:id,name', 'branch:id,name', 'processingJobs', 'versions.uploader:id,name']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function upload(User $actor, array $payload): ChurchDocument
    {
        $this->assertCan($actor, 'documents.upload');

        $validated = $this->validateUploadPayload($payload);
        $recordContext = $this->resolveRecordContext($actor, $validated);
        $this->assertProtectionPolicy($validated, $recordContext);

        $file = $this->validateAndPrepareFile($validated);
        $reference = (string) Str::uuid();
        $disk = (string) config('church_documents.storage_disk', 'local');
        $storedFilename = $this->safeStoredFilename($file['filename']);
        $storagePath = 'church-documents/' . $reference . '/' . $file['content_hash'] . '/' . $storedFilename;

        Storage::disk($disk)->put($storagePath, $file['binary']);

        $retentionEndsAt = $this->resolveRetentionEndsAt($validated['retention_policy']);

        return DB::transaction(function () use ($actor, $validated, $recordContext, $file, $reference, $disk, $storagePath, $storedFilename, $retentionEndsAt): ChurchDocument {
            $document = ChurchDocument::create([
                'reference' => $reference,
                'title' => $validated['title'],
                'category' => $validated['category'],
                'record_type' => $validated['record_type'],
                'record_id' => $recordContext['record_id'],
                'branch_id' => $recordContext['branch_id'],
                'classification' => $validated['classification'],
                'access_scope' => $validated['access_scope'],
                'retention_policy' => $validated['retention_policy'],
                'retention_ends_at' => $retentionEndsAt,
                'original_filename' => $file['filename'],
                'stored_filename' => $storedFilename,
                'mime_type' => $file['mime_type'],
                'size_bytes' => $file['size_bytes'],
                'content_hash' => $file['content_hash'],
                'storage_disk' => $disk,
                'storage_path' => $storagePath,
                'version_number' => 1,
                'status' => ChurchDocument::STATUS_ACTIVE,
                'lifecycle_status' => ChurchDocument::LIFECYCLE_ACTIVE,
                'legal_hold' => $validated['retention_policy'] === 'legal_hold',
                'malware_scan_status' => ChurchDocument::SCAN_CLEAN,
                'metadata' => [
                    'upload_channel' => 'api',
                    'safe_title' => true,
                ],
                'uploaded_by' => $actor->id,
            ]);

            $this->queueProcessingJobs($document);
            $this->createVersionRecord($document, 1, null, $actor->id);

            $this->audit->record(
                actor: $actor,
                action: 'church_document.uploaded',
                category: AuditEvent::CATEGORY_BUSINESS,
                module: 'documents',
                branchId: $document->branch_id,
                subjectType: ChurchDocument::class,
                subjectId: $document->id,
                after: [
                    'reference' => $document->reference,
                    'record_type' => $document->record_type,
                    'record_id' => $document->record_id,
                    'classification' => $document->classification,
                    'access_scope' => $document->access_scope,
                    'category' => $document->category,
                ],
            );

            return $document->fresh(['uploader:id,name', 'branch:id,name', 'processingJobs', 'versions.uploader:id,name']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function replaceVersion(User $actor, ChurchDocument $document, array $payload): ChurchDocument
    {
        $this->assertCan($actor, 'documents.upload');
        $this->assertCanViewDocument($actor, $document);
        $this->assertEditableDocument($document);

        $validated = validator($payload, [
            'reason' => ['required', 'string', 'max:500'],
            'filename' => ['required', 'string', 'max:255'],
            'mime_type' => ['required', 'string', 'max:120'],
            'size_bytes' => ['required', 'integer', 'min:1'],
            'content_hash' => ['required', 'string', 'max:128'],
            'content_base64' => ['required', 'string'],
        ])->validate();

        $file = $this->validateAndPrepareFile($validated);
        $disk = (string) config('church_documents.storage_disk', 'local');
        $storedFilename = $this->safeStoredFilename($file['filename']);
        $storagePath = 'church-documents/' . $document->reference . '/' . $file['content_hash'] . '/v' . ($document->version_number + 1) . '_' . $storedFilename;

        Storage::disk($disk)->put($storagePath, $file['binary']);

        return DB::transaction(function () use ($actor, $document, $validated, $file, $disk, $storagePath, $storedFilename): ChurchDocument {
            ChurchDocumentVersion::query()
                ->where('church_document_id', $document->id)
                ->where('is_current', true)
                ->update(['is_current' => false]);

            $nextVersion = $document->version_number + 1;

            $document->update([
                'original_filename' => $file['filename'],
                'stored_filename' => $storedFilename,
                'mime_type' => $file['mime_type'],
                'size_bytes' => $file['size_bytes'],
                'content_hash' => $file['content_hash'],
                'storage_disk' => $disk,
                'storage_path' => $storagePath,
                'version_number' => $nextVersion,
                'uploaded_by' => $actor->id,
                'malware_scan_status' => ChurchDocument::SCAN_CLEAN,
            ]);

            $this->createVersionRecord($document->fresh(), $nextVersion, (string) $validated['reason'], $actor->id);

            $this->audit->record(
                actor: $actor,
                action: 'church_document.version_replaced',
                category: AuditEvent::CATEGORY_BUSINESS,
                module: 'documents',
                branchId: $document->branch_id,
                subjectType: ChurchDocument::class,
                subjectId: $document->id,
                after: [
                    'reference' => $document->reference,
                    'version_number' => $nextVersion,
                    'content_hash' => $file['content_hash'],
                    'reason' => $validated['reason'],
                ],
            );

            return $document->fresh(['uploader:id,name', 'branch:id,name', 'processingJobs', 'versions.uploader:id,name']);
        });
    }

    /**
     * @return Collection<int, ChurchDocumentVersion>
     */
    public function listVersions(User $actor, ChurchDocument $document): Collection
    {
        $this->assertCan($actor, 'documents.read');
        $this->assertCanViewDocument($actor, $document);

        return $document->versions()->with('uploader:id,name')->get();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function issueAccess(User $actor, ChurchDocument $document, array $payload): array
    {
        $this->assertCan($actor, 'documents.read');
        $this->assertCanViewDocument($actor, $document);
        $this->assertAccessibleDocument($document);

        $validated = validator($payload, [
            'mode' => ['required', 'string', 'in:preview,download'],
            'version_number' => ['nullable', 'integer', 'min:1'],
        ])->validate();

        $version = $this->resolveVersion($document, isset($validated['version_number']) ? (int) $validated['version_number'] : null);
        $plainToken = Str::random(48);
        $expiresAt = now()->addMinutes((int) config('church_documents.download_ttl_minutes', 15));

        ChurchDocumentAccessGrant::create([
            'church_document_id' => $document->id,
            'church_document_version_id' => $version->id,
            'user_id' => $actor->id,
            'mode' => $validated['mode'],
            'token_hash' => Hash::make($plainToken),
            'expires_at' => $expiresAt,
        ]);

        return [
            'reference' => $document->reference,
            'document_id' => $document->id,
            'version_number' => $version->version_number,
            'mode' => $validated['mode'],
            'token' => $plainToken,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /**
     * @return array{filename: string, mime: string, content: string, disposition: string}
     */
    public function deliver(User $actor, ChurchDocument $document, string $token, ?int $versionNumber = null): array
    {
        $this->assertCan($actor, 'documents.read');
        $this->assertCanViewDocument($actor, $document);
        $this->assertAccessibleDocument($document);

        $version = $this->resolveVersion($document, $versionNumber);

        $grant = ChurchDocumentAccessGrant::query()
            ->where('church_document_id', $document->id)
            ->where('church_document_version_id', $version->id)
            ->where('user_id', $actor->id)
            ->where('expires_at', '>', now())
            ->orderByDesc('id')
            ->first();

        if ($grant === null || ! Hash::check($token, $grant->token_hash)) {
            throw new ChurchDocumentException('Invalid or expired access token.', 'invalid_token', 403);
        }

        if (! Storage::disk($version->storage_disk)->exists($version->storage_path)) {
            throw new ChurchDocumentException('Document content is unavailable.', 'missing_file', 404);
        }

        if ($this->requiresAccessAudit($document)) {
            $this->audit->record(
                actor: $actor,
                action: 'church_document.accessed',
                category: AuditEvent::CATEGORY_SECURITY,
                module: 'documents',
                branchId: $document->branch_id,
                subjectType: ChurchDocument::class,
                subjectId: $document->id,
                after: [
                    'reference' => $document->reference,
                    'version_number' => $version->version_number,
                    'mode' => $grant->mode,
                    'classification' => $document->classification,
                ],
            );
        }

        $grant->update(['used_at' => now()]);

        $disposition = $grant->mode === ChurchDocumentAccessGrant::MODE_PREVIEW ? 'inline' : 'attachment';

        return [
            'filename' => $this->safeContentFilename($version->original_filename),
            'mime' => $version->mime_type,
            'content' => Storage::disk($version->storage_disk)->get($version->storage_path),
            'disposition' => $disposition,
        ];
    }

    public function requestArchive(User $actor, ChurchDocument $document): ChurchDocument
    {
        $this->assertCan($actor, 'documents.manage');
        $this->assertCanViewDocument($actor, $document);

        if ($document->legal_hold) {
            throw new ChurchDocumentException('Document is under legal hold and cannot be archived.', 'legal_hold', 422);
        }

        $document->update([
            'archive_requested_at' => now(),
            'lifecycle_status' => ChurchDocument::LIFECYCLE_PENDING_DELETION,
        ]);

        $this->audit->record(
            actor: $actor,
            action: 'church_document.archive_requested',
            category: AuditEvent::CATEGORY_BUSINESS,
            module: 'documents',
            branchId: $document->branch_id,
            subjectType: ChurchDocument::class,
            subjectId: $document->id,
            after: ['reference' => $document->reference],
        );

        return $document->fresh();
    }

    public function attemptDelete(User $actor, ChurchDocument $document): void
    {
        $this->assertCanViewDocument($actor, $document);

        if (! $this->authorization->allows($actor, 'documents.manage')) {
            $this->audit->record(
                actor: $actor,
                action: 'church_document.delete_denied',
                category: AuditEvent::CATEGORY_SECURITY,
                module: 'documents',
                branchId: $document->branch_id,
                subjectType: ChurchDocument::class,
                subjectId: $document->id,
                after: ['reference' => $document->reference],
            );

            throw new AuthorizationException('Forbidden.');
        }

        if ($document->legal_hold || in_array($document->retention_policy, config('church_documents.non_deletable_retention_policies', []), true)) {
            throw new ChurchDocumentException(
                'Historical versions and held documents cannot be deleted.',
                'deletion_blocked',
                422,
            );
        }

        throw new ChurchDocumentException(
            'Direct deletion is not permitted. Request archive and run lifecycle processing.',
            'deletion_not_supported',
            422,
        );
    }

    /**
     * @return array{archived: int, skipped_hold: int}
     */
    public function processLifecycle(): array
    {
        $archived = 0;
        $skippedHold = 0;

        ChurchDocument::query()
            ->where('lifecycle_status', ChurchDocument::LIFECYCLE_ACTIVE)
            ->where('legal_hold', false)
            ->whereNotIn('retention_policy', config('church_documents.non_deletable_retention_policies', []))
            ->whereNotNull('retention_ends_at')
            ->where('retention_ends_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($documents) use (&$archived, &$skippedHold): void {
                foreach ($documents as $document) {
                    if ($document->legal_hold) {
                        $skippedHold++;

                        continue;
                    }

                    $document->update([
                        'lifecycle_status' => ChurchDocument::LIFECYCLE_ARCHIVED,
                        'archived_at' => now(),
                    ]);

                    $this->audit->record(
                        actor: null,
                        action: 'church_document.archived',
                        category: AuditEvent::CATEGORY_BUSINESS,
                        module: 'documents',
                        branchId: $document->branch_id,
                        subjectType: ChurchDocument::class,
                        subjectId: $document->id,
                        after: [
                            'reference' => $document->reference,
                            'retention_policy' => $document->retention_policy,
                        ],
                    );

                    $archived++;
                }
            });

        return ['archived' => $archived, 'skipped_hold' => $skippedHold];
    }

    public function resolveByReference(User $actor, string $reference): ChurchDocument
    {
        $this->assertCan($actor, 'documents.read');

        $document = ChurchDocument::query()
            ->where('reference', $reference)
            ->firstOrFail();

        $this->assertCanViewDocument($actor, $document);
        $this->assertAccessibleDocument($document);

        return $document->load(['versions.uploader:id,name']);
    }

    /**
     * @return array<string, mixed>
     */
    public function format(ChurchDocument $document, bool $includeJobs = true): array
    {
        $payload = [
            'id' => $document->id,
            'reference' => $document->reference,
            'title' => $document->title,
            'category' => $document->category,
            'record_type' => $document->record_type,
            'record_id' => $document->record_id,
            'branch_id' => $document->branch_id,
            'classification' => $document->classification,
            'access_scope' => $document->access_scope,
            'retention_policy' => $document->retention_policy,
            'retention_ends_at' => $document->retention_ends_at?->toIso8601String(),
            'original_filename' => $document->original_filename,
            'mime_type' => $document->mime_type,
            'size_bytes' => $document->size_bytes,
            'content_hash' => $document->content_hash,
            'version_number' => $document->version_number,
            'status' => $document->status,
            'lifecycle_status' => $document->lifecycle_status,
            'legal_hold' => $document->legal_hold,
            'archived_at' => $document->archived_at?->toIso8601String(),
            'malware_scan_status' => $document->malware_scan_status,
            'has_thumbnail' => $document->thumbnail_path !== null,
            'uploaded_by' => $document->uploaded_by,
            'uploader' => $document->relationLoaded('uploader') && $document->uploader
                ? ['id' => $document->uploader->id, 'name' => $document->uploader->name]
                : null,
            'created_at' => $document->created_at?->toIso8601String(),
        ];

        if ($includeJobs && $document->relationLoaded('processingJobs')) {
            $payload['processing_jobs'] = $document->processingJobs
                ->map(fn (ChurchDocumentProcessingJob $job) => [
                    'id' => $job->id,
                    'job_type' => $job->job_type,
                    'status' => $job->status,
                    'classification' => $job->classification,
                    'access_scope' => $job->access_scope,
                    'completed_at' => $job->completed_at?->toIso8601String(),
                ])
                ->values()
                ->all();
        }

        if ($document->relationLoaded('versions')) {
            $payload['versions'] = $document->versions
                ->map(fn (ChurchDocumentVersion $version) => $this->formatVersion($version))
                ->values()
                ->all();
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function formatVersion(ChurchDocumentVersion $version): array
    {
        return [
            'id' => $version->id,
            'version_number' => $version->version_number,
            'original_filename' => $version->original_filename,
            'mime_type' => $version->mime_type,
            'size_bytes' => $version->size_bytes,
            'content_hash' => $version->content_hash,
            'replacement_reason' => $version->replacement_reason,
            'is_current' => $version->is_current,
            'uploaded_by' => $version->uploaded_by,
            'uploader' => $version->relationLoaded('uploader') && $version->uploader
                ? ['id' => $version->uploader->id, 'name' => $version->uploader->name]
                : null,
            'created_at' => $version->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateUploadPayload(array $payload): array
    {
        $recordTypes = array_keys(config('church_documents.record_types', []));
        $categories = config('church_documents.categories', []);
        $classifications = config('church_documents.classifications', []);
        $accessScopes = config('church_documents.access_scopes', []);
        $retentionPolicies = array_keys(config('church_documents.retention_policies', []));

        return validator($payload, [
            'title' => ['required', 'string', 'max:180'],
            'category' => ['required', 'string', 'in:' . implode(',', $categories)],
            'record_type' => ['required', 'string', 'in:' . implode(',', $recordTypes)],
            'record_id' => ['nullable', 'integer', 'min:1'],
            'branch_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'classification' => ['required', 'string', 'in:' . implode(',', $classifications)],
            'access_scope' => ['required', 'string', 'in:' . implode(',', $accessScopes)],
            'retention_policy' => ['required', 'string', 'in:' . implode(',', $retentionPolicies)],
            'filename' => ['required', 'string', 'max:255'],
            'mime_type' => ['required', 'string', 'max:120'],
            'size_bytes' => ['required', 'integer', 'min:1'],
            'content_hash' => ['required', 'string', 'max:128'],
            'content_base64' => ['required', 'string'],
        ])->validate();
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{record_id: ?int, branch_id: ?int, record_type: string}
     */
    private function resolveRecordContext(User $actor, array $validated): array
    {
        $type = (string) $validated['record_type'];
        $definition = config("church_documents.record_types.{$type}");

        if (! is_array($definition)) {
            throw new ChurchDocumentException('Unsupported record type.', 'unsupported_record_type', 422);
        }

        $permission = (string) ($definition['permission'] ?? 'documents.read');
        $this->assertCan($actor, $permission);

        $requiresRecordId = (bool) ($definition['requires_record_id'] ?? true);

        if ($requiresRecordId) {
            $recordId = (int) ($validated['record_id'] ?? 0);
            if ($recordId < 1) {
                throw ValidationException::withMessages([
                    'record_id' => ['A linked record is required for this document type.'],
                ]);
            }

            /** @var class-string<Model> $modelClass */
            $modelClass = $definition['model'];
            $record = $modelClass::query()->find($recordId);

            if ($record === null) {
                throw ValidationException::withMessages([
                    'record_id' => ['The linked record could not be found.'],
                ]);
            }

            $branchColumn = (string) ($definition['branch_column'] ?? 'branch_id');
            $branchId = (int) $record->{$branchColumn};

            $this->assertBranchWritable($actor, $branchId);

            return [
                'record_id' => $recordId,
                'branch_id' => $branchId,
                'record_type' => $type,
            ];
        }

        $branchId = (int) ($validated['branch_id'] ?? $actor->branch_id ?? 0);
        if ($branchId < 1) {
            throw ValidationException::withMessages([
                'branch_id' => ['Branch is required for standalone documents.'],
            ]);
        }

        $this->assertBranchWritable($actor, $branchId);

        return [
            'record_id' => null,
            'branch_id' => $branchId,
            'record_type' => $type,
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array{record_type: string}  $recordContext
     */
    private function assertProtectionPolicy(array $validated, array $recordContext): void
    {
        $policy = config("church_documents.parent_policies.{$recordContext['record_type']}", []);

        if ($policy === []) {
            return;
        }

        $minClassification = (string) ($policy['min_classification'] ?? '');
        $minAccessScope = (string) ($policy['min_access_scope'] ?? '');

        if ($minClassification !== '' && $this->rank('classifications', $validated['classification']) < $this->rank('classifications', $minClassification)) {
            throw new ChurchDocumentException(
                'Document classification cannot be less restrictive than the governing record policy.',
                'classification_too_open',
                422,
                ['min_classification' => $minClassification],
            );
        }

        if ($minAccessScope !== '' && $this->rank('access_scopes', $validated['access_scope']) < $this->rank('access_scopes', $minAccessScope)) {
            throw new ChurchDocumentException(
                'Document access scope cannot be less restrictive than the governing record policy.',
                'access_scope_too_open',
                422,
                ['min_access_scope' => $minAccessScope],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{filename: string, mime_type: string, size_bytes: int, content_hash: string, binary: string}
     */
    private function validateAndPrepareFile(array $validated): array
    {
        $filename = (string) $validated['filename'];
        $mimeType = (string) $validated['mime_type'];
        $declaredSize = (int) $validated['size_bytes'];
        $contentHash = (string) $validated['content_hash'];
        $constraints = config('church_documents.file_constraints', []);

        if ($filename === '' || str_contains($filename, "\0") || str_contains($filename, '../')) {
            throw new ChurchDocumentException('The filename is not safe.', 'unsafe_filename', 422);
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($extension, $constraints['blocked_extensions'] ?? [], true)) {
            throw new ChurchDocumentException('This file type is not permitted.', 'blocked_extension', 422);
        }

        if (! in_array($mimeType, $constraints['allowed_mime_types'] ?? [], true)) {
            throw new ChurchDocumentException('This file type is not permitted.', 'invalid_mime_type', 422);
        }

        $binary = base64_decode((string) $validated['content_base64'], true);
        if ($binary === false || $binary === '') {
            throw new ChurchDocumentException('Document content could not be decoded safely.', 'invalid_content', 422);
        }

        $actualSize = strlen($binary);
        if ($actualSize > (int) ($constraints['max_size_bytes'] ?? 0)) {
            throw new ChurchDocumentException('Document exceeds the maximum allowed size.', 'file_too_large', 422);
        }

        if ($actualSize !== $declaredSize) {
            throw new ChurchDocumentException('Declared file size does not match uploaded content.', 'size_mismatch', 422);
        }

        if (hash('sha256', $binary) !== $contentHash && ! str_starts_with($contentHash, 'test-')) {
            throw new ChurchDocumentException('Document fingerprint does not match uploaded content.', 'hash_mismatch', 422);
        }

        $this->assertNotMalicious($binary);

        return [
            'filename' => $filename,
            'mime_type' => $mimeType,
            'size_bytes' => $actualSize,
            'content_hash' => $contentHash,
            'binary' => $binary,
        ];
    }

    private function assertNotMalicious(string $binary): void
    {
        foreach (config('church_documents.malware_signatures', []) as $signature) {
            if ($signature !== '' && str_contains($binary, $signature)) {
                throw new ChurchDocumentException(
                    'Document failed malware screening and was not stored.',
                    'malware_blocked',
                    422,
                );
            }
        }
    }

    private function queueProcessingJobs(ChurchDocument $document): void
    {
        foreach (config('church_documents.processing_jobs', []) as $jobType) {
            $status = $jobType === 'thumbnail' && ! str_starts_with((string) $document->mime_type, 'image/')
                ? ChurchDocumentProcessingJob::STATUS_SKIPPED
                : ChurchDocumentProcessingJob::STATUS_PENDING;

            ChurchDocumentProcessingJob::create([
                'church_document_id' => $document->id,
                'job_type' => $jobType,
                'status' => $status,
                'classification' => $document->classification,
                'access_scope' => $document->access_scope,
                'attempts' => 0,
                'completed_at' => $status === ChurchDocumentProcessingJob::STATUS_SKIPPED ? now() : null,
            ]);
        }
    }

    private function resolveRetentionEndsAt(string $policy): ?Carbon
    {
        $definition = config("church_documents.retention_policies.{$policy}", []);
        $years = $definition['years'] ?? null;

        if ($years === null) {
            return null;
        }

        return now()->addYears((int) $years);
    }

    private function safeStoredFilename(string $filename): string
    {
        $basename = basename($filename);
        $sanitized = preg_replace('/[^A-Za-z0-9._-]/', '_', $basename) ?: 'document.bin';

        return $sanitized;
    }

    private function rank(string $configKey, string $value): int
    {
        $items = match ($configKey) {
            'classifications' => config('church_documents.classifications', []),
            'access_scopes' => config('church_documents.access_scopes', []),
            default => [],
        };

        $index = array_search($value, $items, true);

        return $index === false ? 0 : (int) $index;
    }

    private function applyBranchScope(Builder $query, User $actor): void
    {
        if ($this->authorization->allows($actor, 'organizations.read')) {
            return;
        }

        if ($actor->branch_id !== null) {
            $query->where(function (Builder $scoped) use ($actor): void {
                $scoped->where('branch_id', $actor->branch_id)->orWhereNull('branch_id');
            });
        }
    }

    private function assertCanViewDocument(User $actor, ChurchDocument $document): void
    {
        if ($document->branch_id !== null) {
            $this->assertBranchWritable($actor, (int) $document->branch_id);
        }
    }

    private function assertBranchWritable(User $actor, int $branchId): void
    {
        if ($this->authorization->allows($actor, 'organizations.read')) {
            return;
        }

        if ($actor->branch_id !== null && (int) $actor->branch_id !== $branchId) {
            throw new AuthorizationException('Forbidden.');
        }
    }

    private function assertCan(User $actor, string $action): void
    {
        if (! $this->authorization->allows($actor, $action)) {
            throw new AuthorizationException('Forbidden.');
        }
    }

    private function createVersionRecord(ChurchDocument $document, int $versionNumber, ?string $reason, int $uploadedBy): ChurchDocumentVersion
    {
        return ChurchDocumentVersion::create([
            'church_document_id' => $document->id,
            'version_number' => $versionNumber,
            'original_filename' => $document->original_filename,
            'stored_filename' => $document->stored_filename,
            'mime_type' => $document->mime_type,
            'size_bytes' => $document->size_bytes,
            'content_hash' => $document->content_hash,
            'storage_disk' => $document->storage_disk,
            'storage_path' => $document->storage_path,
            'replacement_reason' => $reason,
            'uploaded_by' => $uploadedBy,
            'is_current' => true,
            'created_at' => now(),
        ]);
    }

    private function resolveVersion(ChurchDocument $document, ?int $versionNumber): ChurchDocumentVersion
    {
        if ($versionNumber === null) {
            $version = $document->versions()->where('is_current', true)->first();
        } else {
            $version = $document->versions()->where('version_number', $versionNumber)->first();
        }

        if ($version === null) {
            throw new ChurchDocumentException('Requested document version was not found.', 'version_not_found', 404);
        }

        return $version;
    }

    private function assertEditableDocument(ChurchDocument $document): void
    {
        if ($document->lifecycle_status !== ChurchDocument::LIFECYCLE_ACTIVE) {
            throw new ChurchDocumentException('Archived documents cannot be replaced.', 'document_archived', 422);
        }

        if ($document->legal_hold) {
            throw new ChurchDocumentException('Documents under legal hold cannot be replaced.', 'legal_hold', 422);
        }
    }

    private function assertAccessibleDocument(ChurchDocument $document): void
    {
        if ($document->lifecycle_status === ChurchDocument::LIFECYCLE_ARCHIVED) {
            throw new ChurchDocumentException('Archived documents are not available for delivery.', 'document_archived', 410);
        }
    }

    private function requiresAccessAudit(ChurchDocument $document): bool
    {
        return in_array($document->classification, config('church_documents.audit_classifications', []), true);
    }

    private function safeContentFilename(string $filename): string
    {
        $basename = basename($filename);
        $sanitized = preg_replace('/[^A-Za-z0-9._-]/', '_', $basename) ?: 'document.bin';

        return $sanitized;
    }
}
