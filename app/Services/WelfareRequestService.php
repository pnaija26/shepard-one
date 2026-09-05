<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Member;
use App\Models\MemberNotification;
use App\Models\User;
use App\Models\WelfareRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Story 7.1: submit welfare requests with governed document handling.
 */
class WelfareRequestService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
    ) {
    }

    /**
     * @return Collection<int, WelfareRequest>
     */
    public function listRequests(User $actor, array $filters = []): Collection
    {
        $this->assertCan($actor, 'welfare.requests.read');

        $query = WelfareRequest::query()
            ->with(['branch:id,name', 'beneficiary:id,first_name,last_name,membership_id'])
            ->orderByDesc('updated_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $this->applyBranchScope($query, $actor);

        return $query->limit(200)->get();
    }

    /**
     * @return Collection<int, WelfareRequest>
     */
    public function listMyRequests(User $actor): Collection
    {
        if (! $this->authorization->allows($actor, 'welfare.requests.read.self')
            && ! $this->authorization->allows($actor, 'welfare.requests.submit.self')) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }

        return WelfareRequest::query()
            ->with(['branch:id,name'])
            ->where('requester_user_id', $actor->id)
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get();
    }

    public function showRequest(User $actor, WelfareRequest $request): WelfareRequest
    {
        $this->assertCanView($actor, $request);

        return $request->load([
            'branch:id,name',
            'beneficiary:id,first_name,last_name,membership_id',
            'requesterMember:id,first_name,last_name,membership_id',
            'requesterUser:id,name,email',
            'assignedOfficer:id,name',
            'assessments.assessor:id,name',
            'caseEvents' => fn ($q) => $q->orderByDesc('created_at')->limit(50),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function saveDraft(User $actor, array $payload, ?WelfareRequest $existing = null): WelfareRequest
    {
        $staff = $this->canSubmitAsStaff($actor);
        $self = $this->canSubmitSelf($actor);

        if (! $staff && ! $self) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }

        $validated = $this->validateDraftPayload($payload, $staff);
        $this->assertBranchWritable($actor, (int) $validated['branch_id']);
        $this->assertBeneficiaryAllowed($actor, $validated, $staff);

        $documentResult = $this->processDocuments(
            $validated['documents'] ?? [],
            (int) ($validated['beneficiary_member_id'] ?? 0) ?: null,
            persistInvalid: true,
            excludeRequestId: $existing?->id,
        );

        return DB::transaction(function () use ($actor, $validated, $existing, $documentResult, $staff): WelfareRequest {
            $requesterMember = Member::query()->where('user_id', $actor->id)->first();

            $attributes = [
                'branch_id' => $validated['branch_id'],
                'beneficiary_member_id' => $validated['beneficiary_member_id'] ?? null,
                'beneficiary_name' => $validated['beneficiary_name'] ?? null,
                'requester_member_id' => $requesterMember?->id,
                'requester_user_id' => $actor->id,
                'request_type' => $validated['request_type'],
                'description' => $validated['description'] ?? null,
                'priority' => $validated['priority'] ?? 'normal',
                'requested_value' => $validated['requested_value'] ?? null,
                'currency' => $validated['currency'] ?? 'NGN',
                'consent_data_processing' => (bool) ($validated['consent_data_processing'] ?? false),
                'consent_welfare_review' => (bool) ($validated['consent_welfare_review'] ?? false),
                'supporting_documents' => $documentResult['documents'],
                'validation_errors' => $documentResult['errors'],
                'status' => WelfareRequest::STATUS_DRAFT,
                'is_restricted' => true,
                'updated_by' => $actor->id,
            ];

            if ($existing !== null) {
                if ($existing->status !== WelfareRequest::STATUS_DRAFT) {
                    throw new WelfareRequestException('Only draft requests can be updated.', 'not_draft', 422);
                }

                $this->assertCanView($actor, $existing);
                $existing->update($attributes);

                $request = $existing->fresh();
            } else {
                $request = WelfareRequest::create([
                    ...$attributes,
                    'case_number' => $this->generateCaseNumber(),
                    'created_by' => $actor->id,
                ]);
            }

            $this->audit($actor, 'welfare_request.draft_saved', $request);

            return $request->fresh(['branch:id,name', 'beneficiary:id,first_name,last_name']);
        });
    }

    public function submitRequest(User $actor, WelfareRequest $request): WelfareRequest
    {
        $staff = $this->canSubmitAsStaff($actor);
        $self = $this->canSubmitSelf($actor);

        if (! $staff && ! $self) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }

        $this->assertCanView($actor, $request);

        if ($request->status !== WelfareRequest::STATUS_DRAFT) {
            throw new WelfareRequestException('Only draft requests can be submitted.', 'not_draft', 422);
        }

        $this->assertSubmissionPayloadValid($request);
        $this->assertNoDuplicateOpenRequest($request);

        $documentResult = $this->processDocuments(
            $request->supporting_documents ?? [],
            (int) $request->beneficiary_member_id ?: null,
            persistInvalid: true,
            requireAccepted: true,
            excludeRequestId: $request->id,
        );

        if ($documentResult['errors'] !== []) {
            $request->update([
                'supporting_documents' => $documentResult['documents'],
                'validation_errors' => $documentResult['errors'],
                'updated_by' => $actor->id,
            ]);

            throw ValidationException::withMessages([
                'documents' => array_map(fn (array $error) => $error['message'], $documentResult['errors']),
                'draft_preserved' => ['Your draft has been saved with valid details. Fix the highlighted documents and submit again.'],
            ]);
        }

        return DB::transaction(function () use ($actor, $request, $documentResult): WelfareRequest {
            $request->update([
                'supporting_documents' => $documentResult['documents'],
                'validation_errors' => [],
                'status' => WelfareRequest::STATUS_SUBMITTED,
                'beneficiary_status_message' => config('welfare_assessments.beneficiary_status_messages.submitted'),
                'submitted_at' => now(),
                'updated_by' => $actor->id,
            ]);

            $this->audit($actor, 'welfare_request.submitted', $request->fresh());

            $this->notifySubmitted($request->fresh());

            return $request->fresh(['branch:id,name', 'beneficiary:id,first_name,last_name']);
        });
    }

    public function formatRequest(WelfareRequest $request, User $actor): array
    {
        $canReadRestricted = $this->authorization->allows($actor, 'welfare.restricted.read')
            || $this->authorization->allows($actor, 'welfare.requests.read');

        $isRequester = (int) $request->requester_user_id === (int) $actor->id;

        return [
            'id' => $request->id,
            'case_number' => $request->case_number,
            'branch_id' => $request->branch_id,
            'branch' => $request->relationLoaded('branch') ? $request->branch : null,
            'beneficiary_member_id' => $request->beneficiary_member_id,
            'beneficiary_name' => $request->beneficiary?->fullName() ?? $request->beneficiary_name,
            'request_type' => $request->request_type,
            'description' => $canReadRestricted || $isRequester ? $request->description : '[Restricted welfare details]',
            'priority' => $request->priority,
            'requested_value' => $canReadRestricted ? $request->requested_value : null,
            'currency' => $request->currency,
            'consent_data_processing' => $request->consent_data_processing,
            'consent_welfare_review' => $request->consent_welfare_review,
            'status' => $request->status,
            'beneficiary_status_message' => $request->beneficiary_status_message
                ?? config('welfare_assessments.beneficiary_status_messages.' . $request->status),
            'supporting_documents' => $this->visibleDocuments($request->supporting_documents ?? [], $canReadRestricted || $isRequester),
            'validation_errors' => $request->validation_errors ?? [],
            'is_restricted' => $request->is_restricted,
            'submitted_at' => $request->submitted_at?->toIso8601String(),
            'created_at' => $request->created_at?->toIso8601String(),
            'updated_at' => $request->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateDraftPayload(array $payload, bool $staff): array
    {
        return validator($payload, [
            'branch_id' => ['required', 'integer', 'exists:organizations,id'],
            'beneficiary_member_id' => ['nullable', 'integer', 'exists:members,id'],
            'beneficiary_name' => ['nullable', 'string', 'max:160'],
            'request_type' => ['required', 'string', 'in:' . implode(',', config('welfare_requests.request_types', []))],
            'description' => ['nullable', 'string', 'max:5000'],
            'priority' => ['nullable', 'string', 'in:' . implode(',', config('welfare_requests.priorities', []))],
            'requested_value' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:8'],
            'consent_data_processing' => ['nullable', 'boolean'],
            'consent_welfare_review' => ['nullable', 'boolean'],
            'documents' => ['nullable', 'array', 'max:' . (int) config('welfare_requests.document_constraints.max_documents', 5)],
            'documents.*.filename' => ['required_with:documents', 'string', 'max:255'],
            'documents.*.mime_type' => ['required_with:documents', 'string', 'max:120'],
            'documents.*.size_bytes' => ['required_with:documents', 'integer', 'min:1'],
            'documents.*.content_hash' => ['required_with:documents', 'string', 'max:128'],
        ])->validate();
    }

    private function assertSubmissionPayloadValid(WelfareRequest $request): void
    {
        $errors = [];

        if ($request->beneficiary_member_id === null && empty($request->beneficiary_name)) {
            $errors['beneficiary_member_id'][] = 'A beneficiary member or beneficiary name is required.';
        }

        if (empty($request->description)) {
            $errors['description'][] = 'A request description is required before submission.';
        }

        if (! $request->consent_data_processing || ! $request->consent_welfare_review) {
            $errors['consent_welfare_review'][] = 'Welfare review consent is required before submission.';
        }

        if (in_array($request->request_type, config('welfare_requests.value_required_types', []), true)
            && $request->requested_value === null) {
            $errors['requested_value'][] = 'A requested value is required for this request type.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $documents
     * @return array{documents: array<int, array<string, mixed>>, errors: array<int, array<string, mixed>>}
     */
    private function processDocuments(
        array $documents,
        ?int $beneficiaryMemberId,
        bool $persistInvalid = true,
        bool $requireAccepted = false,
        ?int $excludeRequestId = null,
    ): array {
        $processed = [];
        $errors = [];
        $seenHashes = [];

        foreach ($documents as $index => $document) {
            if (($document['status'] ?? null) === 'rejected' && ! $persistInvalid) {
                continue;
            }

            $filename = (string) ($document['filename'] ?? '');
            $mimeType = (string) ($document['mime_type'] ?? '');
            $sizeBytes = (int) ($document['size_bytes'] ?? 0);
            $contentHash = (string) ($document['content_hash'] ?? '');

            $rejection = $this->validateDocument(
                $filename,
                $mimeType,
                $sizeBytes,
                $contentHash,
                $seenHashes,
                $beneficiaryMemberId,
                $excludeRequestId,
            );

            if ($rejection !== null) {
                $errors[] = [
                    'index' => $index,
                    'filename' => $filename,
                    'code' => $rejection['code'],
                    'message' => $rejection['message'],
                ];

                if ($persistInvalid) {
                    $processed[] = [
                        'document_id' => $document['document_id'] ?? (string) Str::uuid(),
                        'filename' => $filename,
                        'mime_type' => $mimeType,
                        'size_bytes' => $sizeBytes,
                        'content_hash' => $contentHash,
                        'status' => 'rejected',
                        'rejection_reason' => $rejection['message'],
                        'storage_path' => null,
                    ];
                }

                continue;
            }

            $seenHashes[] = $contentHash;
            $processed[] = [
                'document_id' => $document['document_id'] ?? (string) Str::uuid(),
                'filename' => $filename,
                'mime_type' => $mimeType,
                'size_bytes' => $sizeBytes,
                'content_hash' => $contentHash,
                'status' => 'accepted',
                'rejection_reason' => null,
                'storage_path' => 'welfare/documents/' . $contentHash . '/' . basename($filename),
            ];
        }

        if ($requireAccepted && $processed === []) {
            $errors[] = [
                'index' => null,
                'filename' => null,
                'code' => 'documents_required',
                'message' => 'At least one valid supporting document is required.',
            ];
        }

        if ($requireAccepted && collect($processed)->where('status', 'accepted')->isEmpty()) {
            $errors[] = [
                'index' => null,
                'filename' => null,
                'code' => 'no_valid_documents',
                'message' => 'No valid supporting documents remain. Upload permitted file types within the size limit.',
            ];
        }

        return ['documents' => $processed, 'errors' => $errors];
    }

    /**
     * @param  array<int, string>  $seenHashes
     * @return array{code: string, message: string}|null
     */
    private function validateDocument(
        string $filename,
        string $mimeType,
        int $sizeBytes,
        string $contentHash,
        array $seenHashes,
        ?int $beneficiaryMemberId,
        ?int $excludeRequestId = null,
    ): ?array {
        $constraints = config('welfare_requests.document_constraints', []);

        if ($filename === '' || str_contains($filename, "\0") || str_contains($filename, '../')) {
            return [
                'code' => 'unsafe_filename',
                'message' => 'The filename is not safe. Use a simple name without special path characters.',
            ];
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($extension, $constraints['blocked_extensions'] ?? [], true)) {
            return [
                'code' => 'blocked_extension',
                'message' => 'This file type is not permitted for welfare submissions.',
            ];
        }

        if (! in_array($mimeType, $constraints['allowed_mime_types'] ?? [], true)) {
            return [
                'code' => 'invalid_mime_type',
                'message' => 'Use PDF, JPEG, PNG, or WEBP documents only.',
            ];
        }

        if ($sizeBytes > (int) ($constraints['max_size_bytes'] ?? 0)) {
            $maxMb = ((int) ($constraints['max_size_bytes'] ?? 0)) / 1024 / 1024;

            return [
                'code' => 'file_too_large',
                'message' => "Each document must be {$maxMb}MB or smaller.",
            ];
        }

        if ($contentHash === '') {
            return [
                'code' => 'missing_hash',
                'message' => 'Document fingerprint is required for safe handling.',
            ];
        }

        if (in_array($contentHash, $seenHashes, true)) {
            return [
                'code' => 'duplicate_document',
                'message' => 'This document appears to be a duplicate upload in the current request.',
            ];
        }

        if ($beneficiaryMemberId !== null && $this->isDuplicateDocumentHash($beneficiaryMemberId, $contentHash, $excludeRequestId)) {
            return [
                'code' => 'duplicate_submission',
                'message' => 'This document was already submitted on another open welfare request for this beneficiary.',
            ];
        }

        return null;
    }

    private function isDuplicateDocumentHash(int $beneficiaryMemberId, string $contentHash, ?int $excludeRequestId = null): bool
    {
        $windowDays = (int) config('welfare_requests.duplicate_window_days', 30);

        return WelfareRequest::query()
            ->where('beneficiary_member_id', $beneficiaryMemberId)
            ->when($excludeRequestId !== null, fn ($query) => $query->where('id', '!=', $excludeRequestId))
            ->whereIn('status', [WelfareRequest::STATUS_DRAFT, WelfareRequest::STATUS_SUBMITTED])
            ->where('created_at', '>=', now()->subDays($windowDays))
            ->get(['supporting_documents'])
            ->contains(function (WelfareRequest $request) use ($contentHash): bool {
                foreach ($request->supporting_documents ?? [] as $document) {
                    if (($document['content_hash'] ?? null) === $contentHash && ($document['status'] ?? '') === 'accepted') {
                        return true;
                    }
                }

                return false;
            });
    }

    private function assertNoDuplicateOpenRequest(WelfareRequest $request): void
    {
        if ($request->beneficiary_member_id === null) {
            return;
        }

        $duplicate = WelfareRequest::query()
            ->where('id', '!=', $request->id)
            ->where('beneficiary_member_id', $request->beneficiary_member_id)
            ->where('request_type', $request->request_type)
            ->whereIn('status', [
                WelfareRequest::STATUS_SUBMITTED,
                WelfareRequest::STATUS_UNDER_ASSESSMENT,
                WelfareRequest::STATUS_RETURNED_FOR_INFO,
                WelfareRequest::STATUS_PENDING_REVIEW,
                WelfareRequest::STATUS_ESCALATED,
            ])
            ->exists();

        if ($duplicate) {
            throw new WelfareRequestException(
                'An open submitted request of this type already exists for this beneficiary.',
                'duplicate_request',
                422,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function assertBeneficiaryAllowed(User $actor, array $validated, bool $staff): void
    {
        $beneficiaryId = $validated['beneficiary_member_id'] ?? null;
        if ($beneficiaryId === null) {
            if (! $staff) {
                throw ValidationException::withMessages([
                    'beneficiary_member_id' => ['Members must identify themselves as the beneficiary.'],
                ]);
            }

            return;
        }

        $beneficiary = Member::query()->findOrFail((int) $beneficiaryId);
        $this->assertMemberInScope($actor, $beneficiary);

        if ($staff) {
            return;
        }

        $linked = Member::query()->where('user_id', $actor->id)->first();
        if ($linked === null || (int) $linked->id !== (int) $beneficiaryId) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $documents
     * @return array<int, array<string, mixed>>
     */
    private function visibleDocuments(array $documents, bool $canViewDetails): array
    {
        return array_values(array_map(function (array $document) use ($canViewDetails) {
            if ($canViewDetails) {
                return $document;
            }

            return [
                'document_id' => $document['document_id'] ?? null,
                'filename' => '[Restricted document]',
                'status' => $document['status'] ?? null,
            ];
        }, $documents));
    }

    private function notifySubmitted(WelfareRequest $request): void
    {
        $member = Member::query()->find($request->requester_member_id);
        if ($member === null || $member->user_id === null) {
            return;
        }

        MemberNotification::create([
            'member_id' => $member->id,
            'user_id' => $member->user_id,
            'type' => 'welfare.request.submitted',
            'message' => 'Your welfare request ' . $request->case_number . ' has been submitted for confidential review.',
            'metadata' => [
                'welfare_request_id' => $request->id,
                'case_number' => $request->case_number,
            ],
        ]);
    }

    private function generateCaseNumber(): string
    {
        do {
            $caseNumber = 'WFR-' . strtoupper(Str::random(8));
        } while (WelfareRequest::query()->where('case_number', $caseNumber)->exists());

        return $caseNumber;
    }

    private function canSubmitAsStaff(User $actor): bool
    {
        return $this->authorization->allows($actor, 'welfare.requests.submit');
    }

    private function canSubmitSelf(User $actor): bool
    {
        return $this->authorization->allows($actor, 'welfare.requests.submit.self');
    }

    private function assertCan(User $actor, string $action): void
    {
        if (! $this->authorization->allows($actor, $action)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function assertCanView(User $actor, WelfareRequest $request): void
    {
        if ($this->authorization->allows($actor, 'welfare.requests.read')) {
            $this->assertRequestInScope($actor, $request);

            return;
        }

        if ((int) $request->requester_user_id === (int) $actor->id
            && ($this->authorization->allows($actor, 'welfare.requests.read.self')
                || $this->authorization->allows($actor, 'welfare.requests.submit.self'))) {
            return;
        }

        $linked = Member::query()->where('user_id', $actor->id)->first();
        if ($linked !== null
            && (int) $request->beneficiary_member_id === (int) $linked->id
            && ($this->authorization->allows($actor, 'welfare.requests.read.self')
                || $this->authorization->allows($actor, 'welfare.requests.submit.self'))) {
            return;
        }

        throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
    }

    private function applyBranchScope(Builder $query, User $actor): void
    {
        if ($actor->isChurchWide()) {
            return;
        }

        $scope = BranchScope::for($actor);
        $query->whereIn('branch_id', $scope->subtreeIds((int) $scope->branchId()));
    }

    private function assertBranchWritable(User $actor, int $branchId): void
    {
        if ($actor->isChurchWide()) {
            return;
        }

        try {
            BranchScope::for($actor)->assertIncludes($branchId);
        } catch (BranchScopeException) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function assertMemberInScope(User $actor, Member $member): void
    {
        if ($actor->isChurchWide()) {
            return;
        }

        try {
            BranchScope::for($actor)->assertIncludes((int) $member->branch_id);
        } catch (BranchScopeException) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function assertRequestInScope(User $actor, WelfareRequest $request): void
    {
        if ($actor->isChurchWide()) {
            return;
        }

        try {
            BranchScope::for($actor)->assertIncludes((int) $request->branch_id);
        } catch (BranchScopeException) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function audit(User $actor, string $action, WelfareRequest $request): void
    {
        $this->audit->record(
            actor: $actor,
            action: $action,
            category: AuditEvent::CATEGORY_BUSINESS,
            module: 'welfare',
            branchId: $request->branch_id,
            subjectType: WelfareRequest::class,
            subjectId: $request->id,
            after: [
                'case_number' => $request->case_number,
                'status' => $request->status,
            ],
        );
    }
}
