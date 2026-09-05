<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Contribution;
use App\Models\ContributionAdjustment;
use App\Models\ContributionReceipt;
use App\Models\ContributionReconciliationEvent;
use App\Models\GivingCampaign;
use App\Models\Member;
use App\Models\MemberNotification;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Story 11.2: reconcile contributions and issue verifiable receipts with adjustments.
 */
class ContributionReconciliationService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
    ) {
    }

    /**
     * @return Collection<int, Contribution>
     */
    public function list(User $actor, array $filters = []): Collection
    {
        $this->assertCan($actor, 'payments.contributions.read');

        $query = Contribution::query()
            ->with(['branch:id,name', 'member:id,first_name,last_name,email', 'campaign:id,name,code'])
            ->orderByDesc('id');

        $this->applyBranchScope($query, $actor);

        if (! empty($filters['reconciliation_status'])) {
            $query->where('reconciliation_status', $filters['reconciliation_status']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->limit(100)->get();
    }

    public function show(User $actor, Contribution $contribution): Contribution
    {
        $this->assertCan($actor, 'payments.contributions.read');
        $this->assertInScope($actor, $contribution);

        return $contribution->load([
            'branch:id,name',
            'member:id,first_name,last_name,email',
            'campaign:id,name,code',
            'reconciliationEvents' => fn ($q) => $q->orderByDesc('id')->limit(20),
            'receipts' => fn ($q) => $q->orderByDesc('id'),
            'adjustments' => fn ($q) => $q->orderByDesc('id'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createCampaign(User $actor, array $payload): GivingCampaign
    {
        $this->assertCan($actor, 'payments.contributions.reconcile');

        $validated = validator($payload, [
            'name' => ['required', 'string', 'min:3', 'max:160'],
            'code' => ['required', 'string', 'min:2', 'max:64'],
            'branch_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ])->validate();

        if (! empty($validated['branch_id'])) {
            $this->assertBranchWritable($actor, (int) $validated['branch_id']);
        }

        return GivingCampaign::create([
            'reference' => 'GC-' . now()->format('Ymd') . '-' . strtoupper(Str::random(5)),
            'name' => $validated['name'],
            'code' => strtoupper($validated['code']),
            'branch_id' => $validated['branch_id'] ?? null,
            'status' => GivingCampaign::STATUS_ACTIVE,
            'starts_at' => isset($validated['starts_at']) ? Carbon::parse($validated['starts_at']) : null,
            'ends_at' => isset($validated['ends_at']) ? Carbon::parse($validated['ends_at']) : null,
            'created_by' => $actor->id,
        ]);
    }

    /**
     * @return Collection<int, GivingCampaign>
     */
    public function listCampaigns(User $actor): Collection
    {
        $this->assertCan($actor, 'payments.contributions.read');

        $query = GivingCampaign::query()->where('status', GivingCampaign::STATUS_ACTIVE)->orderBy('name');
        if (! $actor->isChurchWide()) {
            try {
                $scope = BranchScope::for($actor);
                $query->where(function (Builder $q) use ($scope): void {
                    $q->whereNull('branch_id')
                        ->orWhereIn('branch_id', $scope->subtreeIds((int) $scope->branchId()));
                });
            } catch (BranchScopeException) {
                $query->whereRaw('1 = 0');
            }
        }

        return $query->limit(100)->get();
    }

    /**
     * Authorized manual contribution entry.
     *
     * @param  array<string, mixed>  $payload
     */
    public function createManual(User $actor, array $payload): Contribution
    {
        $this->assertCan($actor, 'payments.contributions.manual');

        $validated = validator($payload, [
            'amount_cents' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'in:' . implode(',', config('payments.currencies', []))],
            'category' => ['required', 'string', 'in:' . implode(',', config('payments.categories', []))],
            'branch_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'member_id' => ['nullable', 'integer', 'exists:members,id'],
            'campaign_id' => ['nullable', 'integer', 'exists:giving_campaigns,id'],
            'payment_reference' => ['nullable', 'string', 'max:120'],
            'occurred_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ])->validate();

        if (! empty($validated['branch_id'])) {
            $this->assertBranchWritable($actor, (int) $validated['branch_id']);
        }

        $paymentReference = $validated['payment_reference'] ?? ('MANUAL-' . strtoupper(Str::random(10)));
        $this->assertNoDuplicateReference('manual', $paymentReference, null, $validated['amount_cents']);

        $contribution = Contribution::create([
            'reference' => 'CT-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6)),
            'payment_source_id' => null,
            'provider' => config('payments.manual_provider', 'manual'),
            'source_type' => Contribution::SOURCE_MANUAL,
            'provider_payment_reference' => $paymentReference,
            'payment_reference' => $paymentReference,
            'status' => Contribution::STATUS_SUCCEEDED,
            'amount_cents' => $validated['amount_cents'],
            'currency' => strtoupper($validated['currency']),
            'category' => $validated['category'],
            'campaign_id' => $validated['campaign_id'] ?? null,
            'branch_id' => $validated['branch_id'] ?? $actor->branch_id,
            'member_id' => $validated['member_id'] ?? null,
            'payer_linked' => ! empty($validated['member_id']),
            'provider_evidence' => [
                'source' => 'manual',
                'entered_by' => $actor->id,
                'notes' => $validated['notes'] ?? null,
            ],
            'reconciliation_status' => Contribution::RECON_UNMATCHED,
            'receipt_eligible' => true,
            'occurred_at' => isset($validated['occurred_at']) ? Carbon::parse($validated['occurred_at']) : now(),
        ]);

        $this->recordReconEvent($contribution, $actor, 'manual_created', null, Contribution::RECON_UNMATCHED, null, [
            'amount_cents' => $contribution->amount_cents,
            'category' => $contribution->category,
        ], $validated['notes'] ?? null);

        $this->audit($actor, 'contribution.manual_created', $contribution);

        return $contribution->fresh(['branch', 'member', 'campaign']);
    }

    /**
     * Match contribution to member/category/campaign/branch/payment reference without altering provider evidence.
     *
     * @param  array<string, mixed>  $payload
     */
    public function match(User $actor, Contribution $contribution, array $payload): Contribution
    {
        $this->assertCan($actor, 'payments.contributions.reconcile');
        $this->assertInScope($actor, $contribution);

        if ($contribution->reconciliation_status === Contribution::RECON_RECONCILED) {
            throw new ContributionReconciliationException('Contribution is already reconciled.', 'already_reconciled', 422);
        }

        $validated = validator($payload, [
            'member_id' => ['nullable', 'integer', 'exists:members,id'],
            'category' => ['nullable', 'string', 'in:' . implode(',', config('payments.categories', []))],
            'campaign_id' => ['nullable', 'integer', 'exists:giving_campaigns,id'],
            'branch_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'payment_reference' => ['nullable', 'string', 'max:120'],
            'expected_amount_cents' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:500'],
        ])->validate();

        $originalEvidence = $contribution->provider_evidence;
        $before = $this->snapshotMatchFields($contribution);

        if (isset($validated['expected_amount_cents']) && (int) $validated['expected_amount_cents'] !== (int) $contribution->amount_cents) {
            return $this->flagNeedsResolution(
                $actor,
                $contribution,
                'amount_mismatch',
                $validated['notes'] ?? 'Amount does not match expected value.',
                [
                    'expected_amount_cents' => $validated['expected_amount_cents'],
                    'actual_amount_cents' => $contribution->amount_cents,
                ],
            );
        }

        if (! empty($validated['payment_reference'])) {
            $dup = Contribution::query()
                ->where('id', '!=', $contribution->id)
                ->where(function (Builder $q) use ($validated): void {
                    $q->where('payment_reference', $validated['payment_reference'])
                        ->orWhere('provider_payment_reference', $validated['payment_reference']);
                })
                ->first();
            if ($dup !== null) {
                return $this->flagNeedsResolution(
                    $actor,
                    $contribution,
                    'duplicate_reference',
                    $validated['notes'] ?? 'Duplicate payment reference requires resolution.',
                    ['duplicate_contribution_id' => $dup->id],
                );
            }
        }

        $updates = [];
        foreach (['member_id', 'category', 'campaign_id', 'branch_id', 'payment_reference'] as $field) {
            if (array_key_exists($field, $validated) && $validated[$field] !== null) {
                $updates[$field] = $validated[$field];
            }
        }
        if (isset($updates['member_id'])) {
            $updates['payer_linked'] = true;
        }
        $updates['reconciliation_status'] = Contribution::RECON_MATCHED;
        $updates['resolution_reason'] = null;

        $contribution->update($updates);

        // Provider evidence must remain immutable.
        if ($contribution->fresh()->provider_evidence != $originalEvidence) {
            $contribution->update(['provider_evidence' => $originalEvidence]);
        }

        $this->recordReconEvent(
            $contribution,
            $actor,
            'matched',
            $before['reconciliation_status'],
            Contribution::RECON_MATCHED,
            $before,
            $this->snapshotMatchFields($contribution->fresh()),
            $validated['notes'] ?? null,
        );

        $this->audit($actor, 'contribution.matched', $contribution);

        return $contribution->fresh(['branch', 'member', 'campaign']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function markNeedsResolution(User $actor, Contribution $contribution, array $payload): Contribution
    {
        $this->assertCan($actor, 'payments.contributions.reconcile');
        $this->assertInScope($actor, $contribution);

        $validated = validator($payload, [
            'reason' => ['required', 'string', 'in:' . implode(',', config('payments.resolution_reasons', []))],
            'notes' => ['nullable', 'string', 'max:500'],
        ])->validate();

        return $this->flagNeedsResolution(
            $actor,
            $contribution,
            $validated['reason'],
            $validated['notes'] ?? null,
        );
    }

    /**
     * Finalize reconciliation after matching (or resolve a flagged item).
     *
     * @param  array<string, mixed>  $payload
     */
    public function reconcile(User $actor, Contribution $contribution, array $payload = []): Contribution
    {
        $this->assertCan($actor, 'payments.contributions.reconcile');
        $this->assertInScope($actor, $contribution);

        if ($contribution->status !== Contribution::STATUS_SUCCEEDED) {
            throw new ContributionReconciliationException('Only succeeded contributions can be reconciled.', 'not_succeeded', 422);
        }

        if ($contribution->reconciliation_status === Contribution::RECON_NEEDS_RESOLUTION && empty($payload['resolution_notes'])) {
            throw new ContributionReconciliationException('Resolution notes are required.', 'resolution_required', 422);
        }

        if (! in_array($contribution->reconciliation_status, [
            Contribution::RECON_MATCHED,
            Contribution::RECON_NEEDS_RESOLUTION,
            Contribution::RECON_UNMATCHED,
        ], true)) {
            throw new ContributionReconciliationException('Contribution cannot be reconciled in its current state.', 'invalid_state', 422);
        }

        // Unmatched must at least have category + payment reference to reconcile.
        if ($contribution->reconciliation_status === Contribution::RECON_UNMATCHED) {
            if (! $contribution->category || (! $contribution->payment_reference && ! $contribution->provider_payment_reference)) {
                throw new ContributionReconciliationException('Match member/category/reference before reconciling.', 'not_matched', 422);
            }
        }

        $evidenceBefore = $contribution->provider_evidence;
        $from = $contribution->reconciliation_status;

        $contribution->update([
            'reconciliation_status' => Contribution::RECON_RECONCILED,
            'reconciled_by' => $actor->id,
            'reconciled_at' => now(),
            'resolution_reason' => null,
            'provider_evidence' => $evidenceBefore, // explicit immutability
        ]);

        $this->recordReconEvent(
            $contribution,
            $actor,
            'reconciled',
            $from,
            Contribution::RECON_RECONCILED,
            null,
            [
                'reconciled_by' => $actor->id,
                'resolution_notes' => $payload['resolution_notes'] ?? null,
            ],
            $payload['resolution_notes'] ?? $payload['notes'] ?? null,
        );

        $this->audit($actor, 'contribution.reconciled', $contribution);

        return $contribution->fresh(['branch', 'member', 'campaign']);
    }

    /**
     * Issue one verifiable receipt for a reconciled, succeeded contribution.
     *
     * @param  array<string, mixed>  $payload
     */
    public function issueReceipt(User $actor, Contribution $contribution, array $payload = []): ContributionReceipt
    {
        $this->assertCan($actor, 'payments.receipts.issue');
        $this->assertInScope($actor, $contribution);

        if ($contribution->status !== Contribution::STATUS_SUCCEEDED) {
            throw new ContributionReconciliationException('Contribution is not confirmed.', 'not_confirmed', 422);
        }
        if ($contribution->reconciliation_status !== Contribution::RECON_RECONCILED) {
            throw new ContributionReconciliationException('Contribution must be reconciled before issuing a receipt.', 'not_reconciled', 422);
        }
        if (! $contribution->receipt_eligible) {
            throw new ContributionReconciliationException('Contribution is not eligible for a receipt.', 'not_eligible', 422);
        }

        $existing = $contribution->activeReceipt();
        if ($existing !== null) {
            throw new ContributionReconciliationException('An active receipt already exists.', 'receipt_exists', 422, [
                'receipt_id' => $existing->id,
                'receipt_number' => $existing->receipt_number,
            ]);
        }

        $deliver = (bool) ($payload['deliver'] ?? true);
        $campaign = $contribution->campaign_id ? GivingCampaign::query()->find($contribution->campaign_id) : null;

        $financialFields = [
            'contribution_reference' => $contribution->reference,
            'provider' => $contribution->provider,
            'provider_payment_reference' => $contribution->provider_payment_reference,
            'payment_reference' => $contribution->payment_reference,
            'amount_cents' => $contribution->amount_cents,
            'currency' => $contribution->currency,
            'category' => $contribution->category,
            'campaign_code' => $campaign?->code,
            'branch_id' => $contribution->branch_id,
            'occurred_at' => $contribution->occurred_at?->toIso8601String(),
        ];

        $receipt = ContributionReceipt::create([
            'receipt_number' => 'RCPT-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6)),
            'verification_code' => strtoupper(Str::random(12)),
            'contribution_id' => $contribution->id,
            'status' => ContributionReceipt::STATUS_ISSUED,
            'amount_cents' => $contribution->amount_cents,
            'currency' => $contribution->currency,
            'category' => $contribution->category,
            'campaign_name' => $campaign?->name,
            'branch_id' => $contribution->branch_id,
            'member_id' => $contribution->member_id,
            'financial_fields' => $financialFields,
            'issued_by' => $actor->id,
            'issued_at' => now(),
        ]);

        if ($deliver) {
            $this->deliverReceipt($receipt);
        }

        $this->audit($actor, 'contribution.receipt_issued', $contribution, [
            'receipt_number' => $receipt->receipt_number,
            'delivered' => $receipt->delivered,
        ]);

        return $receipt->fresh();
    }

    /**
     * Void a receipt with a traceable adjustment (history retained).
     *
     * @param  array<string, mixed>  $payload
     */
    public function voidReceipt(User $actor, ContributionReceipt $receipt, array $payload): ContributionAdjustment
    {
        $this->assertCan($actor, 'payments.receipts.void');
        $contribution = $receipt->contribution;
        $this->assertInScope($actor, $contribution);

        if ($receipt->status !== ContributionReceipt::STATUS_ISSUED) {
            throw new ContributionReconciliationException('Only issued receipts can be voided.', 'not_issued', 422);
        }

        $validated = validator($payload, [
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ])->validate();

        return DB::transaction(function () use ($actor, $receipt, $contribution, $validated): ContributionAdjustment {
            $receipt->update([
                'status' => ContributionReceipt::STATUS_VOIDED,
                'voided_by' => $actor->id,
                'voided_at' => now(),
                'void_reason' => $validated['reason'],
            ]);

            $adjustment = ContributionAdjustment::create([
                'reference' => 'ADJ-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6)),
                'contribution_id' => $contribution->id,
                'receipt_id' => $receipt->id,
                'adjustment_type' => ContributionAdjustment::TYPE_VOID,
                'amount_delta_cents' => 0,
                'before_values' => ['receipt_status' => ContributionReceipt::STATUS_ISSUED],
                'after_values' => ['receipt_status' => ContributionReceipt::STATUS_VOIDED],
                'reason' => $validated['reason'],
                'created_by' => $actor->id,
                'occurred_at' => now(),
            ]);

            $this->audit($actor, 'contribution.receipt_voided', $contribution, [
                'receipt_number' => $receipt->receipt_number,
                'adjustment_reference' => $adjustment->reference,
            ]);

            return $adjustment;
        });
    }

    /**
     * Correct contribution metadata via adjustment (never deletes history; provider evidence untouched).
     *
     * @param  array<string, mixed>  $payload
     */
    public function correct(User $actor, Contribution $contribution, array $payload): ContributionAdjustment
    {
        $this->assertCan($actor, 'payments.contributions.reconcile');
        $this->assertInScope($actor, $contribution);

        $validated = validator($payload, [
            'category' => ['nullable', 'string', 'in:' . implode(',', config('payments.categories', []))],
            'campaign_id' => ['nullable', 'integer', 'exists:giving_campaigns,id'],
            'member_id' => ['nullable', 'integer', 'exists:members,id'],
            'branch_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ])->validate();

        $evidence = $contribution->provider_evidence;
        $before = $this->snapshotMatchFields($contribution);
        $updates = [];
        foreach (['category', 'campaign_id', 'member_id', 'branch_id'] as $field) {
            if (array_key_exists($field, $validated) && $validated[$field] !== null) {
                $updates[$field] = $validated[$field];
            }
        }
        if ($updates === []) {
            throw new ContributionReconciliationException('No correction fields provided.', 'empty_correction', 422);
        }

        $type = isset($updates['category'])
            ? ContributionAdjustment::TYPE_CATEGORY_CHANGE
            : ContributionAdjustment::TYPE_CORRECTION;

        $contribution->update($updates);
        $contribution->update(['provider_evidence' => $evidence]);

        // Active receipt is superseded conceptually via adjustment note; amount unchanged here.
        $active = $contribution->activeReceipt();
        if ($active) {
            $active->update([
                'category' => $contribution->fresh()->category,
                'member_id' => $contribution->fresh()->member_id,
                'branch_id' => $contribution->fresh()->branch_id,
                'financial_fields' => array_merge($active->financial_fields ?? [], [
                    'category' => $contribution->fresh()->category,
                    'corrected_at' => now()->toIso8601String(),
                ]),
            ]);
        }

        $adjustment = ContributionAdjustment::create([
            'reference' => 'ADJ-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6)),
            'contribution_id' => $contribution->id,
            'receipt_id' => $active?->id,
            'adjustment_type' => $type,
            'amount_delta_cents' => 0,
            'before_values' => $before,
            'after_values' => $this->snapshotMatchFields($contribution->fresh()),
            'reason' => $validated['reason'],
            'created_by' => $actor->id,
            'occurred_at' => now(),
        ]);

        $this->audit($actor, 'contribution.corrected', $contribution, [
            'adjustment_reference' => $adjustment->reference,
        ]);

        return $adjustment;
    }

    public function verifyReceipt(string $receiptNumber, string $verificationCode): ?array
    {
        $receipt = ContributionReceipt::query()
            ->where('receipt_number', $receiptNumber)
            ->where('verification_code', $verificationCode)
            ->first();

        if ($receipt === null) {
            return null;
        }

        return $this->formatReceipt($receipt);
    }

    /**
     * @return array<string, mixed>
     */
    public function formatContribution(Contribution $contribution): array
    {
        return [
            'id' => $contribution->id,
            'reference' => $contribution->reference,
            'source_type' => $contribution->source_type,
            'provider' => $contribution->provider,
            'provider_payment_reference' => $contribution->provider_payment_reference,
            'payment_reference' => $contribution->payment_reference,
            'status' => $contribution->status,
            'amount_cents' => $contribution->amount_cents,
            'currency' => $contribution->currency,
            'category' => $contribution->category,
            'campaign_id' => $contribution->campaign_id,
            'campaign' => $contribution->relationLoaded('campaign') ? $contribution->campaign : null,
            'branch_id' => $contribution->branch_id,
            'branch' => $contribution->relationLoaded('branch') ? $contribution->branch : null,
            'member_id' => $contribution->member_id,
            'member' => $contribution->relationLoaded('member') ? $contribution->member : null,
            'payer_linked' => $contribution->payer_linked,
            'reconciliation_status' => $contribution->reconciliation_status,
            'resolution_reason' => $contribution->resolution_reason,
            'reconciled_at' => $contribution->reconciled_at?->toIso8601String(),
            'receipt_eligible' => $contribution->receipt_eligible,
            'provider_evidence' => $contribution->provider_evidence,
            'occurred_at' => $contribution->occurred_at?->toIso8601String(),
            'active_receipt' => ($r = $contribution->relationLoaded('receipts')
                ? $contribution->receipts->firstWhere('status', ContributionReceipt::STATUS_ISSUED)
                : $contribution->activeReceipt())
                ? $this->formatReceipt($r)
                : null,
            'reconciliation_events' => $contribution->relationLoaded('reconciliationEvents')
                ? $contribution->reconciliationEvents->map(fn (ContributionReconciliationEvent $e) => [
                    'id' => $e->id,
                    'action' => $e->action,
                    'from_status' => $e->from_status,
                    'to_status' => $e->to_status,
                    'notes' => $e->notes,
                    'occurred_at' => $e->occurred_at?->toIso8601String(),
                ])->values()->all()
                : [],
            'adjustments' => $contribution->relationLoaded('adjustments')
                ? $contribution->adjustments->map(fn (ContributionAdjustment $a) => [
                    'id' => $a->id,
                    'reference' => $a->reference,
                    'adjustment_type' => $a->adjustment_type,
                    'reason' => $a->reason,
                    'occurred_at' => $a->occurred_at?->toIso8601String(),
                ])->values()->all()
                : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formatReceipt(ContributionReceipt $receipt): array
    {
        return [
            'id' => $receipt->id,
            'receipt_number' => $receipt->receipt_number,
            'verification_code' => $receipt->verification_code,
            'contribution_id' => $receipt->contribution_id,
            'status' => $receipt->status,
            'amount_cents' => $receipt->amount_cents,
            'currency' => $receipt->currency,
            'category' => $receipt->category,
            'campaign_name' => $receipt->campaign_name,
            'branch_id' => $receipt->branch_id,
            'member_id' => $receipt->member_id,
            'financial_fields' => $receipt->financial_fields,
            'delivered' => $receipt->delivered,
            'delivery_channel' => $receipt->delivery_channel,
            'delivered_at' => $receipt->delivered_at?->toIso8601String(),
            'issued_at' => $receipt->issued_at?->toIso8601String(),
            'voided_at' => $receipt->voided_at?->toIso8601String(),
            'void_reason' => $receipt->void_reason,
        ];
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function flagNeedsResolution(
        User $actor,
        Contribution $contribution,
        string $reason,
        ?string $notes = null,
        array $details = [],
    ): Contribution {
        $evidence = $contribution->provider_evidence;
        $from = $contribution->reconciliation_status;

        $contribution->update([
            'reconciliation_status' => Contribution::RECON_NEEDS_RESOLUTION,
            'resolution_reason' => $reason,
            'provider_evidence' => $evidence,
        ]);

        $this->recordReconEvent(
            $contribution,
            $actor,
            'needs_resolution',
            $from,
            Contribution::RECON_NEEDS_RESOLUTION,
            null,
            array_merge(['reason' => $reason], $details),
            $notes,
        );

        $this->audit($actor, 'contribution.needs_resolution', $contribution, [
            'reason' => $reason,
        ]);

        return $contribution->fresh(['branch', 'member', 'campaign']);
    }

    private function deliverReceipt(ContributionReceipt $receipt): void
    {
        $member = $receipt->member_id ? Member::query()->find($receipt->member_id) : null;
        if ($member === null || ! $member->consent_data_processing || ! $member->user_id) {
            $receipt->update([
                'delivered' => false,
                'delivery_channel' => 'none',
            ]);

            return;
        }

        $prefs = $member->communication_preferences ?? [];
        $channel = ! empty($prefs['in_app']) || ! isset($prefs['in_app']) ? 'in_app' : 'none';

        if ($channel === 'in_app') {
            MemberNotification::create([
                'member_id' => $member->id,
                'user_id' => $member->user_id,
                'type' => 'giving.receipt',
                'category' => 'administrative',
                'message' => 'Your giving receipt ' . $receipt->receipt_number . ' is ready.',
                'metadata' => [
                    'receipt_number' => $receipt->receipt_number,
                    'amount_cents' => $receipt->amount_cents,
                    'currency' => $receipt->currency,
                ],
                'deep_link' => '/contributions',
            ]);
            $receipt->update([
                'delivered' => true,
                'delivery_channel' => 'in_app',
                'delivered_at' => now(),
            ]);
        } else {
            $receipt->update([
                'delivered' => false,
                'delivery_channel' => 'none',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotMatchFields(Contribution $contribution): array
    {
        return [
            'member_id' => $contribution->member_id,
            'category' => $contribution->category,
            'campaign_id' => $contribution->campaign_id,
            'branch_id' => $contribution->branch_id,
            'payment_reference' => $contribution->payment_reference,
            'amount_cents' => $contribution->amount_cents,
            'reconciliation_status' => $contribution->reconciliation_status,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function recordReconEvent(
        Contribution $contribution,
        User $actor,
        string $action,
        ?string $from,
        ?string $to,
        ?array $before,
        ?array $after,
        ?string $notes,
    ): void {
        ContributionReconciliationEvent::create([
            'contribution_id' => $contribution->id,
            'actor_id' => $actor->id,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'before_values' => $before,
            'after_values' => $after,
            'notes' => $notes,
            'occurred_at' => now(),
        ]);
    }

    private function assertNoDuplicateReference(string $provider, string $reference, ?int $exceptId, int $amountCents): void
    {
        $existing = Contribution::query()
            ->where('provider', $provider)
            ->where(function (Builder $q) use ($reference): void {
                $q->where('provider_payment_reference', $reference)
                    ->orWhere('payment_reference', $reference);
            })
            ->when($exceptId, fn (Builder $q) => $q->where('id', '!=', $exceptId))
            ->first();

        if ($existing !== null) {
            throw new ContributionReconciliationException(
                'Duplicate payment reference requires resolution.',
                'duplicate_reference',
                409,
                [
                    'existing_contribution_id' => $existing->id,
                    'existing_amount_cents' => $existing->amount_cents,
                    'incoming_amount_cents' => $amountCents,
                ],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function audit(User $actor, string $action, Contribution $contribution, array $after = []): void
    {
        $this->audit->record(
            actor: $actor,
            action: $action,
            category: AuditEvent::CATEGORY_BUSINESS,
            module: 'payments',
            branchId: $contribution->branch_id,
            subjectType: Contribution::class,
            subjectId: $contribution->id,
            after: array_merge([
                'reference' => $contribution->reference,
                'reconciliation_status' => $contribution->reconciliation_status,
            ], $after),
        );
    }

    private function assertInScope(User $actor, Contribution $contribution): void
    {
        if ($contribution->branch_id === null) {
            return;
        }
        if (! $this->isInBranchScope($actor, (int) $contribution->branch_id)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function applyBranchScope(Builder $query, User $actor): void
    {
        if ($actor->isChurchWide()) {
            return;
        }

        try {
            $scope = BranchScope::for($actor);
            $query->where(function (Builder $inner) use ($scope): void {
                $inner->whereNull('branch_id')
                    ->orWhereIn('branch_id', $scope->subtreeIds((int) $scope->branchId()));
            });
        } catch (BranchScopeException) {
            $query->whereRaw('1 = 0');
        }
    }

    private function assertBranchWritable(User $actor, int $branchId): void
    {
        if (! $this->isInBranchScope($actor, $branchId)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function isInBranchScope(User $actor, int $branchId): bool
    {
        if ($actor->isChurchWide()) {
            return true;
        }

        try {
            return BranchScope::for($actor)->includes($branchId);
        } catch (BranchScopeException) {
            return false;
        }
    }

    private function assertCan(User $actor, string $action): void
    {
        if (! $this->authorization->allows($actor, $action)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }
}
