<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Member;
use App\Models\MemberNotification;
use App\Models\User;
use App\Models\WelfareAssistanceConfirmation;
use App\Models\WelfareAssistanceDelivery;
use App\Models\WelfareAssessmentVersion;
use App\Models\WelfareCaseEvent;
use App\Models\WelfareRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Story 7.4: record assistance delivery and beneficiary confirmation.
 */
class WelfareDeliveryService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function recordDelivery(User $actor, WelfareRequest $request, array $payload): WelfareAssistanceDelivery
    {
        $this->assertCanDeliver($actor, $request);

        if ($request->status !== WelfareRequest::STATUS_APPROVED
            && $request->status !== WelfareRequest::STATUS_DISBURSED) {
            throw new WelfareRequestException(
                'Assistance can only be recorded for fully approved cases.',
                'not_approved',
                422,
            );
        }

        $validated = validator($payload, [
            'delivery_type' => ['required', 'string', 'in:disbursement,in_kind'],
            'method' => ['required', 'string', 'in:' . implode(',', config('welfare_deliveries.delivery_methods', []))],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['nullable', 'string', 'max:8'],
            'delivered_on' => ['required', 'date'],
            'reference' => ['required', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'evidence' => ['nullable', 'array', 'max:' . (int) config('welfare_deliveries.evidence_constraints.max_items', 5)],
            'evidence.*.filename' => ['required_with:evidence', 'string', 'max:255'],
            'evidence.*.mime_type' => ['required_with:evidence', 'string', 'max:120'],
            'evidence.*.size_bytes' => ['required_with:evidence', 'integer', 'min:1'],
            'evidence.*.content_hash' => ['required_with:evidence', 'string', 'max:128'],
        ])->validate();

        $approvedValue = $this->resolveApprovedValue($request);
        $alreadyDelivered = (float) WelfareAssistanceDelivery::query()
            ->where('welfare_request_id', $request->id)
            ->sum('amount');

        $newTotal = $alreadyDelivered + (float) $validated['amount'];
        if ($newTotal > $approvedValue + 0.0001) {
            throw new WelfareRequestException(
                'Delivery amount would exceed the approved value. Submit a new approval path before recording additional assistance.',
                'exceeds_approval',
                422,
                [
                    'approved_value' => $approvedValue,
                    'already_delivered' => $alreadyDelivered,
                    'attempted_amount' => (float) $validated['amount'],
                ],
            );
        }

        $evidence = $this->processEvidence($validated['evidence'] ?? []);

        return DB::transaction(function () use ($actor, $request, $validated, $approvedValue, $evidence): WelfareAssistanceDelivery {
            $delivery = WelfareAssistanceDelivery::create([
                'welfare_request_id' => $request->id,
                'branch_id' => $request->branch_id,
                'delivery_type' => $validated['delivery_type'],
                'method' => $validated['method'],
                'amount' => $validated['amount'],
                'currency' => $validated['currency'] ?? $request->currency ?? 'NGN',
                'delivered_on' => $validated['delivered_on'],
                'reference' => $validated['reference'],
                'notes' => $validated['notes'] ?? null,
                'evidence' => $evidence,
                'approved_value_snapshot' => $approvedValue,
                'recorded_by' => $actor->id,
            ]);

            WelfareAssistanceConfirmation::create([
                'welfare_assistance_delivery_id' => $delivery->id,
                'welfare_request_id' => $request->id,
                'status' => WelfareAssistanceConfirmation::STATUS_PENDING,
                'recorded_by' => $actor->id,
            ]);

            $request->update([
                'status' => WelfareRequest::STATUS_DISBURSED,
                'approved_value' => $approvedValue,
                'disbursed_at' => now(),
                'beneficiary_status_message' => config('welfare_deliveries.beneficiary_status_messages.disbursed'),
                'updated_by' => $actor->id,
            ]);

            WelfareCaseEvent::create([
                'welfare_request_id' => $request->id,
                'event_type' => 'assistance_delivered',
                'notes' => 'Assistance recorded: ' . $validated['reference'],
                'beneficiary_message' => config('welfare_deliveries.beneficiary_status_messages.disbursed'),
                'actor_id' => $actor->id,
                'metadata' => [
                    'delivery_id' => $delivery->id,
                    'amount' => $validated['amount'],
                    'method' => $validated['method'],
                ],
                'created_at' => now(),
            ]);

            $this->audit($actor, 'welfare_request.assistance_delivered', $request, [
                'delivery_id' => $delivery->id,
                'amount' => $validated['amount'],
            ]);

            $this->notifyBeneficiary($request->fresh(), 'welfare.request.disbursed', config('welfare_deliveries.beneficiary_status_messages.disbursed'));

            return $delivery->fresh(['confirmation']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function confirmDelivery(User $actor, WelfareAssistanceDelivery $delivery, array $payload): WelfareAssistanceConfirmation
    {
        $request = $delivery->request ?? WelfareRequest::query()->findOrFail($delivery->welfare_request_id);
        $this->assertCanConfirm($actor, $request);

        $confirmation = $delivery->confirmation
            ?? WelfareAssistanceConfirmation::query()->where('welfare_assistance_delivery_id', $delivery->id)->firstOrFail();

        if ($confirmation->status !== WelfareAssistanceConfirmation::STATUS_PENDING) {
            throw new WelfareRequestException('Confirmation has already been recorded for this delivery.', 'already_confirmed', 422);
        }

        $validated = validator($payload, [
            'status' => ['required', 'string', 'in:confirmed,waived'],
            'waiver_reason' => ['required_if:status,waived', 'nullable', 'string', 'max:1000'],
            'evidence' => ['nullable', 'array', 'max:' . (int) config('welfare_deliveries.evidence_constraints.max_items', 5)],
            'evidence.*.filename' => ['required_with:evidence', 'string', 'max:255'],
            'evidence.*.mime_type' => ['required_with:evidence', 'string', 'max:120'],
            'evidence.*.size_bytes' => ['required_with:evidence', 'integer', 'min:1'],
            'evidence.*.content_hash' => ['required_with:evidence', 'string', 'max:128'],
            'confirmed_by_member_id' => ['nullable', 'integer', 'exists:members,id'],
        ])->validate();

        if ($validated['status'] === 'waived' && empty($validated['waiver_reason'])) {
            throw ValidationException::withMessages([
                'waiver_reason' => ['A reason is required when confirmation is formally waived.'],
            ]);
        }

        $evidence = $this->processEvidence($validated['evidence'] ?? []);

        return DB::transaction(function () use ($actor, $request, $confirmation, $validated, $evidence): WelfareAssistanceConfirmation {
            $memberId = $validated['confirmed_by_member_id'] ?? $request->beneficiary_member_id;

            $confirmation->update([
                'status' => $validated['status'],
                'confirmed_at' => now(),
                'waiver_reason' => $validated['status'] === 'waived' ? $validated['waiver_reason'] : null,
                'evidence' => $evidence,
                'confirmed_by_member_id' => $memberId,
                'recorded_by' => $actor->id,
            ]);

            $request->update([
                'status' => WelfareRequest::STATUS_FOLLOW_UP,
                'follow_up_at' => now(),
                'follow_up_due_on' => now()->addDays((int) config('welfare_follow_ups.default_due_days', 7))->toDateString(),
                'beneficiary_status_message' => config('welfare_deliveries.beneficiary_status_messages.follow_up'),
                'updated_by' => $actor->id,
            ]);

            WelfareCaseEvent::create([
                'welfare_request_id' => $request->id,
                'event_type' => 'assistance_confirmation_' . $validated['status'],
                'notes' => $validated['status'] === 'waived'
                    ? 'Confirmation waived: ' . $validated['waiver_reason']
                    : 'Beneficiary confirmation captured.',
                'beneficiary_message' => config('welfare_deliveries.beneficiary_status_messages.follow_up'),
                'actor_id' => $actor->id,
                'metadata' => [
                    'confirmation_id' => $confirmation->id,
                    'status' => $validated['status'],
                ],
                'created_at' => now(),
            ]);

            $this->audit($actor, 'welfare_request.assistance_confirmation_' . $validated['status'], $request, [
                'confirmation_id' => $confirmation->id,
            ]);

            $this->notifyBeneficiary(
                $request->fresh(),
                'welfare.request.follow_up',
                config('welfare_deliveries.beneficiary_status_messages.follow_up'),
            );

            return $confirmation->fresh();
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function formatDelivery(WelfareAssistanceDelivery $delivery, User $actor): array
    {
        $canReadFinance = $this->authorization->allows($actor, 'welfare.finance.read')
            || $this->authorization->allows($actor, 'welfare.deliveries.manage');

        return [
            'id' => $delivery->id,
            'welfare_request_id' => $delivery->welfare_request_id,
            'delivery_type' => $delivery->delivery_type,
            'method' => $delivery->method,
            'amount' => $canReadFinance ? $delivery->amount : null,
            'currency' => $canReadFinance ? $delivery->currency : null,
            'delivered_on' => $delivery->delivered_on?->toDateString(),
            'reference' => $canReadFinance ? $delivery->reference : '[Restricted]',
            'notes' => $canReadFinance ? $delivery->notes : null,
            'evidence' => $canReadFinance ? ($delivery->evidence ?? []) : [],
            'approved_value_snapshot' => $canReadFinance ? $delivery->approved_value_snapshot : null,
            'financial_restricted' => ! $canReadFinance,
            'confirmation' => $delivery->relationLoaded('confirmation') && $delivery->confirmation
                ? [
                    'id' => $delivery->confirmation->id,
                    'status' => $delivery->confirmation->status,
                    'confirmed_at' => $delivery->confirmation->confirmed_at?->toIso8601String(),
                    'waiver_reason' => $delivery->confirmation->waiver_reason,
                ]
                : null,
        ];
    }

    private function resolveApprovedValue(WelfareRequest $request): float
    {
        if ($request->approved_value !== null) {
            return (float) $request->approved_value;
        }

        $assessment = WelfareAssessmentVersion::query()
            ->where('welfare_request_id', $request->id)
            ->where('complete', true)
            ->orderByDesc('version')
            ->first();

        if ($assessment === null || $assessment->proposed_value === null) {
            throw new WelfareRequestException('Approved value is missing for this case.', 'missing_approved_value', 422);
        }

        return (float) $assessment->proposed_value;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function processEvidence(array $items): array
    {
        $constraints = config('welfare_deliveries.evidence_constraints', []);
        $processed = [];

        foreach ($items as $index => $item) {
            $filename = (string) ($item['filename'] ?? '');
            $mime = (string) ($item['mime_type'] ?? '');
            $size = (int) ($item['size_bytes'] ?? 0);
            $hash = (string) ($item['content_hash'] ?? '');

            if ($filename === '' || str_contains($filename, "\0") || str_contains($filename, '../')) {
                throw ValidationException::withMessages([
                    "evidence.{$index}" => ['Evidence filename is not safe.'],
                ]);
            }

            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (in_array($extension, $constraints['blocked_extensions'] ?? [], true)) {
                throw ValidationException::withMessages([
                    "evidence.{$index}" => ['This evidence file type is not permitted.'],
                ]);
            }

            if (! in_array($mime, $constraints['allowed_mime_types'] ?? [], true)) {
                throw ValidationException::withMessages([
                    "evidence.{$index}" => ['Use PDF, JPEG, PNG, or WEBP evidence only.'],
                ]);
            }

            if ($size > (int) ($constraints['max_size_bytes'] ?? 0)) {
                throw ValidationException::withMessages([
                    "evidence.{$index}" => ['Evidence file exceeds the maximum allowed size.'],
                ]);
            }

            $processed[] = [
                'document_id' => (string) Str::uuid(),
                'filename' => $filename,
                'mime_type' => $mime,
                'size_bytes' => $size,
                'content_hash' => $hash,
                'status' => 'accepted',
                'storage_path' => 'welfare/deliveries/' . $hash . '/' . basename($filename),
            ];
        }

        return $processed;
    }

    private function assertCanDeliver(User $actor, WelfareRequest $request): void
    {
        if (! $this->authorization->allows($actor, 'welfare.deliveries.manage')) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }

        $this->assertInScope($actor, $request);
    }

    private function assertCanConfirm(User $actor, WelfareRequest $request): void
    {
        $canManage = $this->authorization->allows($actor, 'welfare.deliveries.manage')
            || $this->authorization->allows($actor, 'welfare.deliveries.confirm');

        if (! $canManage) {
            // Beneficiary self-confirm
            $linked = Member::query()->where('user_id', $actor->id)->first();
            if ($linked === null || (int) $linked->id !== (int) $request->beneficiary_member_id) {
                throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
            }
            if (! $this->authorization->allows($actor, 'welfare.requests.read.self')
                && ! $this->authorization->allows($actor, 'welfare.requests.submit.self')) {
                throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
            }

            return;
        }

        $this->assertInScope($actor, $request);
    }

    private function assertInScope(User $actor, WelfareRequest $request): void
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

    private function notifyBeneficiary(WelfareRequest $request, string $type, string $message): void
    {
        $member = Member::query()->find($request->requester_member_id)
            ?? Member::query()->find($request->beneficiary_member_id);

        if ($member === null || $member->user_id === null) {
            return;
        }

        MemberNotification::create([
            'member_id' => $member->id,
            'user_id' => $member->user_id,
            'type' => $type,
            'message' => $message,
            'metadata' => [
                'welfare_request_id' => $request->id,
                'case_number' => $request->case_number,
                'status' => $request->status,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function audit(User $actor, string $action, WelfareRequest $request, ?array $metadata = null): void
    {
        $this->audit->record(
            actor: $actor,
            action: $action,
            category: AuditEvent::CATEGORY_BUSINESS,
            module: 'welfare',
            branchId: $request->branch_id,
            subjectType: WelfareRequest::class,
            subjectId: $request->id,
            after: array_filter(['metadata' => $metadata]),
        );
    }
}
