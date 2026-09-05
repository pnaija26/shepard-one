<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Contribution;
use App\Models\Member;
use App\Models\PaymentSource;
use App\Models\PaymentWebhookEvent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Story 11.1: connect approved payment sources and ingest signed webhooks idempotently.
 */
class PaymentSourceService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
    ) {
    }

    /**
     * @return Collection<int, PaymentSource>
     */
    public function list(User $actor): Collection
    {
        $this->assertCan($actor, 'payments.sources.read');

        $query = PaymentSource::query()->with(['branch:id,name'])->orderByDesc('id');
        $this->applyBranchScope($query, $actor);

        return $query->limit(100)->get();
    }

    public function show(User $actor, PaymentSource $source): PaymentSource
    {
        $this->assertCan($actor, 'payments.sources.read');
        $this->assertInScope($actor, $source);

        return $source->load(['branch:id,name']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(User $actor, array $payload): PaymentSource
    {
        $this->assertCan($actor, 'payments.sources.manage');

        $validated = $this->validateConfigPayload($payload);
        $this->assertApprovedProvider($validated['provider']);
        if (! empty($validated['branch_id'])) {
            $this->assertBranchWritable($actor, (int) $validated['branch_id']);
        }

        $apiKey = $validated['api_key'] ?? null;
        $webhookSecret = $validated['webhook_secret'] ?? null;
        unset($validated['api_key'], $validated['webhook_secret']);

        $source = PaymentSource::create([
            'reference' => 'PS-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6)),
            'name' => $validated['name'],
            'provider' => $validated['provider'],
            'environment' => $validated['environment'] ?? 'sandbox',
            'currency' => strtoupper($validated['currency'] ?? 'USD'),
            'branch_id' => $validated['branch_id'] ?? null,
            'supported_categories' => array_values($validated['supported_categories']),
            'branch_mapping' => $validated['branch_mapping'] ?? [],
            'api_key_encrypted' => $apiKey,
            'webhook_secret_encrypted' => $webhookSecret,
            'api_key_hint' => $this->hint($apiKey),
            'webhook_secret_hint' => $this->hint($webhookSecret),
            'enabled' => false,
            'status' => PaymentSource::STATUS_DRAFT,
            'configured_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        $this->auditSafe($actor, 'payment_source.created', $source, [
            'provider' => $source->provider,
            'environment' => $source->environment,
            'has_api_key' => $apiKey !== null,
            'has_webhook_secret' => $webhookSecret !== null,
        ]);

        return $source->fresh(['branch:id,name']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(User $actor, PaymentSource $source, array $payload): PaymentSource
    {
        $this->assertCan($actor, 'payments.sources.manage');
        $this->assertInScope($actor, $source);

        $validated = $this->validateConfigPayload($payload, allowPartial: true);
        if (isset($validated['provider']) && $validated['provider'] !== $source->provider) {
            throw new PaymentSourceException('Provider cannot be changed after creation.', 'provider_locked', 422);
        }

        $updates = [
            'updated_by' => $actor->id,
        ];
        foreach (['name', 'environment', 'currency', 'branch_id', 'supported_categories', 'branch_mapping', 'enabled'] as $field) {
            if (array_key_exists($field, $validated)) {
                $updates[$field] = $field === 'currency'
                    ? strtoupper((string) $validated[$field])
                    : $validated[$field];
            }
        }
        if (array_key_exists('api_key', $validated) && $validated['api_key'] !== null && $validated['api_key'] !== '') {
            $updates['api_key_encrypted'] = $validated['api_key'];
            $updates['api_key_hint'] = $this->hint($validated['api_key']);
        }
        if (array_key_exists('webhook_secret', $validated) && $validated['webhook_secret'] !== null && $validated['webhook_secret'] !== '') {
            $updates['webhook_secret_encrypted'] = $validated['webhook_secret'];
            $updates['webhook_secret_hint'] = $this->hint($validated['webhook_secret']);
        }
        if (! empty($updates['enabled']) && $source->status === PaymentSource::STATUS_DRAFT) {
            throw new PaymentSourceException('Source must be tested before enabling.', 'not_tested', 422);
        }
        if (! empty($updates['enabled'])) {
            $updates['status'] = PaymentSource::STATUS_ACTIVE;
        } elseif (array_key_exists('enabled', $updates) && $updates['enabled'] === false) {
            $updates['status'] = PaymentSource::STATUS_DISABLED;
        }

        $source->update($updates);

        $this->auditSafe($actor, 'payment_source.updated', $source, [
            'enabled' => $source->enabled,
            'status' => $source->status,
            'credentials_rotated' => isset($updates['api_key_encrypted']) || isset($updates['webhook_secret_encrypted']),
        ]);

        return $source->fresh(['branch:id,name']);
    }

    /**
     * Test connection without logging or returning raw credentials.
     *
     * @return array<string, mixed>
     */
    public function testConnection(User $actor, PaymentSource $source): array
    {
        $this->assertCan($actor, 'payments.sources.manage');
        $this->assertInScope($actor, $source);

        $apiKey = $source->api_key_encrypted;
        $webhookSecret = $source->webhook_secret_encrypted;

        $passed = is_string($apiKey) && strlen($apiKey) >= 8
            && is_string($webhookSecret) && strlen($webhookSecret) >= 8
            && ($source->supported_categories ?? []) !== []
            && in_array($source->currency, config('payments.currencies', []), true);

        $details = [
            'provider' => $source->provider,
            'environment' => $source->environment,
            'currency_ok' => in_array($source->currency, config('payments.currencies', []), true),
            'categories_configured' => count($source->supported_categories ?? []),
            'api_key_present' => is_string($apiKey) && $apiKey !== '',
            'webhook_secret_present' => is_string($webhookSecret) && $webhookSecret !== '',
            // Simulated provider ping — never echo secrets.
            'provider_ping' => $passed ? 'ok' : 'failed',
        ];

        $source->update([
            'last_tested_at' => now(),
            'last_test_result' => $passed ? 'passed' : 'failed',
            'last_test_details' => $details,
            'status' => $passed ? PaymentSource::STATUS_TESTED : $source->status,
            'updated_by' => $actor->id,
        ]);

        Log::info('payment_source.connection_tested', [
            'payment_source_id' => $source->id,
            'provider' => $source->provider,
            'result' => $passed ? 'passed' : 'failed',
        ]);

        $this->auditSafe($actor, 'payment_source.tested', $source, [
            'result' => $passed ? 'passed' : 'failed',
        ]);

        if (! $passed) {
            throw new PaymentSourceException('Connection test failed.', 'test_failed', 422, $details);
        }

        return [
            'passed' => true,
            'details' => $details,
            'status' => $source->fresh()->status,
        ];
    }

    /**
     * Process a signed provider webhook (or authenticated simulation).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function processWebhook(
        string $provider,
        array $payload,
        ?string $rawBody,
        ?string $signatureHeader,
        ?User $actor = null,
        ?PaymentSource $forcedSource = null,
    ): array {
        $this->assertApprovedProvider($provider);

        if ($actor !== null) {
            $this->assertCan($actor, 'payments.sources.manage');
        }

        $source = $forcedSource ?? $this->resolveSource($provider, $payload);
        if ($source === null || ! $source->enabled) {
            $this->monitorRejection($provider, null, $payload, 'source_inactive', 'missing');
            throw new PaymentSourceException('No active payment source for provider.', 'source_inactive', 422);
        }

        if ($actor !== null) {
            $this->assertInScope($actor, $source);
        }

        $signatureValid = $this->verifySignature($source, $rawBody ?? json_encode($payload) ?: '', $signatureHeader);
        if (! $signatureValid) {
            $this->monitorRejection($provider, $source, $payload, 'invalid_signature', 'invalid');
            $this->auditSystem('payment_webhook.rejected', $source, [
                'reason' => 'invalid_signature',
                'provider' => $provider,
            ]);
            throw new PaymentSourceException('Invalid webhook signature.', 'invalid_signature', 401);
        }

        $eventId = (string) ($payload['event_id'] ?? $payload['id'] ?? '');
        $paymentReference = (string) ($payload['payment_reference'] ?? $payload['reference'] ?? '');
        $amountCents = (int) ($payload['amount_cents'] ?? $payload['amount'] ?? 0);
        $currency = strtoupper((string) ($payload['currency'] ?? $source->currency));
        $category = (string) ($payload['category'] ?? 'other');
        $status = $this->mapContributionStatus((string) ($payload['status'] ?? 'succeeded'));

        if ($eventId === '' || $paymentReference === '' || $amountCents <= 0) {
            $this->monitorRejection($provider, $source, $payload, 'invalid_payload', 'valid');
            throw new PaymentSourceException('Webhook payload is incomplete.', 'invalid_payload', 422);
        }

        if (! in_array($category, $source->supported_categories ?? [], true)) {
            $this->monitorRejection($provider, $source, $payload, 'unsupported_category', 'valid', $eventId, $paymentReference);
            throw new PaymentSourceException('Category is not supported by this source.', 'unsupported_category', 422);
        }

        // Replay / idempotency by provider event id.
        $existingEvent = PaymentWebhookEvent::query()
            ->where('provider', $provider)
            ->where('provider_event_id', $eventId)
            ->first();
        if ($existingEvent !== null) {
            $existingEvent->update([
                'status' => PaymentWebhookEvent::STATUS_REPLAYED,
                'processed_at' => now(),
            ]);
            $this->auditSystem('payment_webhook.replayed', $source, [
                'provider_event_id' => $eventId,
                'payment_reference' => $paymentReference,
            ]);

            return [
                'status' => 'replayed',
                'contribution_id' => Contribution::query()
                    ->where('provider', $provider)
                    ->where('provider_payment_reference', $paymentReference)
                    ->value('id'),
            ];
        }

        $existingContribution = Contribution::query()
            ->where('provider', $provider)
            ->where('provider_payment_reference', $paymentReference)
            ->first();

        if ($existingContribution !== null && (int) $existingContribution->amount_cents !== $amountCents) {
            PaymentWebhookEvent::create([
                'payment_source_id' => $source->id,
                'provider' => $provider,
                'provider_event_id' => $eventId,
                'payment_reference' => $paymentReference,
                'status' => PaymentWebhookEvent::STATUS_CONFLICT,
                'reject_reason' => 'amount_conflict',
                'amount_cents' => $amountCents,
                'currency' => $currency,
                'payload_sanitized' => $this->sanitizePayload($payload),
                'signature_valid' => 'valid',
                'occurred_at' => isset($payload['occurred_at']) ? Carbon::parse($payload['occurred_at']) : now(),
                'processed_at' => now(),
            ]);
            $this->auditSystem('payment_webhook.conflict', $source, [
                'provider_event_id' => $eventId,
                'payment_reference' => $paymentReference,
                'existing_amount_cents' => $existingContribution->amount_cents,
                'incoming_amount_cents' => $amountCents,
            ]);
            throw new PaymentSourceException('Conflicting amount for payment reference.', 'amount_conflict', 409, [
                'existing_amount_cents' => $existingContribution->amount_cents,
                'incoming_amount_cents' => $amountCents,
            ]);
        }

        return DB::transaction(function () use (
            $source,
            $provider,
            $payload,
            $eventId,
            $paymentReference,
            $amountCents,
            $currency,
            $category,
            $status,
            $existingContribution,
        ): array {
            $branchId = $this->resolveBranchId($source, $payload);
            [$memberId, $payerLinked] = $this->resolvePayer($payload);

            if ($existingContribution !== null) {
                $existingContribution->update([
                    'status' => $status,
                    'category' => $category,
                    'branch_id' => $branchId ?? $existingContribution->branch_id,
                    'member_id' => $payerLinked ? $memberId : $existingContribution->member_id,
                    'payer_linked' => $payerLinked || $existingContribution->payer_linked,
                    'payer_external_id' => $payload['payer_external_id'] ?? $existingContribution->payer_external_id,
                    'occurred_at' => isset($payload['occurred_at'])
                        ? Carbon::parse($payload['occurred_at'])
                        : $existingContribution->occurred_at,
                ]);
                $contribution = $existingContribution->fresh();
            } else {
                $contribution = Contribution::create([
                    'reference' => 'CT-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6)),
                    'payment_source_id' => $source->id,
                    'provider' => $provider,
                    'source_type' => Contribution::SOURCE_INTEGRATED,
                    'provider_payment_reference' => $paymentReference,
                    'payment_reference' => $paymentReference,
                    'status' => $status,
                    'amount_cents' => $amountCents,
                    'currency' => $currency,
                    'category' => $category,
                    'branch_id' => $branchId,
                    'member_id' => $payerLinked ? $memberId : null,
                    'payer_linked' => $payerLinked,
                    'payer_external_id' => $payload['payer_external_id'] ?? null,
                    'provider_evidence' => $this->sanitizePayload($payload),
                    'reconciliation_status' => Contribution::RECON_UNMATCHED,
                    'receipt_eligible' => $status === Contribution::STATUS_SUCCEEDED,
                    'occurred_at' => isset($payload['occurred_at']) ? Carbon::parse($payload['occurred_at']) : now(),
                ]);
            }

            PaymentWebhookEvent::create([
                'payment_source_id' => $source->id,
                'provider' => $provider,
                'provider_event_id' => $eventId,
                'payment_reference' => $paymentReference,
                'status' => PaymentWebhookEvent::STATUS_PROCESSED,
                'amount_cents' => $amountCents,
                'currency' => $currency,
                'payload_sanitized' => $this->sanitizePayload($payload),
                'signature_valid' => 'valid',
                'occurred_at' => $contribution->occurred_at,
                'processed_at' => now(),
            ]);

            $this->auditSystem('payment_webhook.processed', $source, [
                'contribution_id' => $contribution->id,
                'provider_event_id' => $eventId,
                'payment_reference' => $paymentReference,
                'amount_cents' => $amountCents,
                'status' => $status,
            ]);

            return [
                'status' => 'processed',
                'contribution' => $this->formatContribution($contribution),
            ];
        });
    }

    /**
     * @return Collection<int, Contribution>
     */
    public function listContributions(User $actor, ?int $sourceId = null): Collection
    {
        $this->assertCan($actor, 'payments.sources.read');

        $query = Contribution::query()->with(['branch:id,name', 'paymentSource:id,name,provider'])->orderByDesc('id');
        if ($sourceId !== null) {
            $query->where('payment_source_id', $sourceId);
        }
        $this->applyContributionBranchScope($query, $actor);

        return $query->limit(100)->get();
    }

    /**
     * @return array<string, mixed>
     */
    public function format(PaymentSource $source): array
    {
        return [
            'id' => $source->id,
            'reference' => $source->reference,
            'name' => $source->name,
            'provider' => $source->provider,
            'environment' => $source->environment,
            'currency' => $source->currency,
            'branch_id' => $source->branch_id,
            'branch' => $source->relationLoaded('branch') ? $source->branch : null,
            'supported_categories' => $source->supported_categories,
            'branch_mapping' => $source->branch_mapping,
            'api_key_hint' => $source->api_key_hint,
            'webhook_secret_hint' => $source->webhook_secret_hint,
            'has_api_key' => $source->api_key_encrypted !== null && $source->api_key_encrypted !== '',
            'has_webhook_secret' => $source->webhook_secret_encrypted !== null && $source->webhook_secret_encrypted !== '',
            'enabled' => $source->enabled,
            'status' => $source->status,
            'last_tested_at' => $source->last_tested_at?->toIso8601String(),
            'last_test_result' => $source->last_test_result,
            'last_test_details' => $source->last_test_details,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formatContribution(Contribution $contribution): array
    {
        return [
            'id' => $contribution->id,
            'reference' => $contribution->reference,
            'payment_source_id' => $contribution->payment_source_id,
            'provider' => $contribution->provider,
            'provider_payment_reference' => $contribution->provider_payment_reference,
            'status' => $contribution->status,
            'amount_cents' => $contribution->amount_cents,
            'currency' => $contribution->currency,
            'category' => $contribution->category,
            'branch_id' => $contribution->branch_id,
            'member_id' => $contribution->member_id,
            'payer_linked' => $contribution->payer_linked,
            'occurred_at' => $contribution->occurred_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateConfigPayload(array $payload, bool $allowPartial = false): array
    {
        return validator($payload, [
            'name' => [$allowPartial ? 'nullable' : 'required', 'string', 'min:3', 'max:160'],
            'provider' => [$allowPartial ? 'nullable' : 'required', 'string', 'max:64'],
            'environment' => ['nullable', 'string', 'in:' . implode(',', config('payments.environments', []))],
            'currency' => ['nullable', 'string', 'in:' . implode(',', config('payments.currencies', []))],
            'branch_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'supported_categories' => [$allowPartial ? 'nullable' : 'required', 'array', 'min:1'],
            'supported_categories.*' => ['string', 'in:' . implode(',', config('payments.categories', []))],
            'branch_mapping' => ['nullable', 'array'],
            'api_key' => [$allowPartial ? 'nullable' : 'required', 'string', 'min:8', 'max:500'],
            'webhook_secret' => [$allowPartial ? 'nullable' : 'required', 'string', 'min:8', 'max:500'],
            'enabled' => ['nullable', 'boolean'],
        ])->validate();
    }

    private function assertApprovedProvider(string $provider): void
    {
        if (! isset(config('payments.approved_providers')[$provider])) {
            throw new PaymentSourceException('Provider is not an approved payment source.', 'unsupported_provider', 422, [
                'approved' => array_keys(config('payments.approved_providers', [])),
            ]);
        }
    }

    private function hint(?string $secret): ?string
    {
        if ($secret === null || $secret === '') {
            return null;
        }

        return '…' . substr($secret, -4);
    }

    private function verifySignature(PaymentSource $source, string $rawBody, ?string $signatureHeader): bool
    {
        $secret = $source->webhook_secret_encrypted;
        if (! is_string($secret) || $secret === '') {
            return false;
        }
        if ($signatureHeader === null || $signatureHeader === '') {
            return false;
        }

        // Support "t=timestamp,v1=hex" or raw hex HMAC.
        $timestamp = null;
        $provided = $signatureHeader;
        if (str_contains($signatureHeader, 'v1=')) {
            foreach (explode(',', $signatureHeader) as $part) {
                if (str_starts_with($part, 't=')) {
                    $timestamp = (int) substr($part, 2);
                }
                if (str_starts_with($part, 'v1=')) {
                    $provided = substr($part, 3);
                }
            }
            $tolerance = (int) config('payments.signature_tolerance_seconds', 300);
            if ($timestamp === null || abs(now()->timestamp - $timestamp) > $tolerance) {
                return false;
            }
            $signedPayload = $timestamp . '.' . $rawBody;
        } else {
            $signedPayload = $rawBody;
        }

        $expected = hash_hmac('sha256', $signedPayload, $secret);

        return hash_equals($expected, $provided);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveSource(string $provider, array $payload): ?PaymentSource
    {
        $environment = (string) ($payload['environment'] ?? 'live');
        $query = PaymentSource::query()
            ->where('provider', $provider)
            ->where('enabled', true)
            ->where('status', PaymentSource::STATUS_ACTIVE);

        if (isset($payload['payment_source_id'])) {
            return $query->where('id', (int) $payload['payment_source_id'])->first();
        }

        $source = (clone $query)->where('environment', $environment)->orderByDesc('id')->first();

        return $source ?? $query->orderByDesc('id')->first();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveBranchId(PaymentSource $source, array $payload): ?int
    {
        if (! empty($payload['branch_id'])) {
            return (int) $payload['branch_id'];
        }
        $code = (string) ($payload['branch_code'] ?? '');
        if ($code !== '' && isset(($source->branch_mapping ?? [])[$code])) {
            return (int) $source->branch_mapping[$code];
        }

        return $source->branch_id;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: ?int, 1: bool}
     */
    private function resolvePayer(array $payload): array
    {
        $memberId = isset($payload['member_id']) ? (int) $payload['member_id'] : null;
        if ($memberId) {
            $member = Member::query()->find($memberId);
            if ($member && $member->consent_data_processing) {
                return [$member->id, true];
            }

            return [null, false];
        }

        $email = strtolower((string) ($payload['payer_email'] ?? ''));
        if ($email !== '') {
            $member = Member::query()->whereRaw('LOWER(email) = ?', [$email])->first();
            if ($member && $member->consent_data_processing) {
                return [$member->id, true];
            }
        }

        return [null, false];
    }

    private function mapContributionStatus(string $status): string
    {
        $normalized = strtolower($status);

        return match ($normalized) {
            'succeeded', 'success', 'paid', 'completed' => Contribution::STATUS_SUCCEEDED,
            'failed', 'failure' => Contribution::STATUS_FAILED,
            'refunded' => Contribution::STATUS_REFUNDED,
            'cancelled', 'canceled' => Contribution::STATUS_CANCELLED,
            default => Contribution::STATUS_PENDING,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sanitizePayload(array $payload): array
    {
        $clean = $payload;
        foreach (['api_key', 'secret', 'webhook_secret', 'token', 'password', 'authorization', 'card_number', 'cvv'] as $key) {
            unset($clean[$key]);
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function monitorRejection(
        string $provider,
        ?PaymentSource $source,
        array $payload,
        string $reason,
        string $signatureValid,
        ?string $eventId = null,
        ?string $paymentReference = null,
    ): void {
        PaymentWebhookEvent::create([
            'payment_source_id' => $source?->id,
            'provider' => $provider,
            'provider_event_id' => $eventId ?? ('rej-' . Str::uuid()),
            'payment_reference' => $paymentReference ?? ($payload['payment_reference'] ?? $payload['reference'] ?? null),
            'status' => PaymentWebhookEvent::STATUS_REJECTED,
            'reject_reason' => $reason,
            'amount_cents' => isset($payload['amount_cents']) ? (int) $payload['amount_cents'] : null,
            'currency' => $payload['currency'] ?? null,
            'payload_sanitized' => $this->sanitizePayload($payload),
            'signature_valid' => $signatureValid,
            'occurred_at' => now(),
            'processed_at' => now(),
        ]);

        Log::warning('payment_webhook.rejected', [
            'provider' => $provider,
            'reason' => $reason,
            'payment_source_id' => $source?->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function auditSafe(User $actor, string $action, PaymentSource $source, array $after = []): void
    {
        $this->audit->record(
            actor: $actor,
            action: $action,
            category: AuditEvent::CATEGORY_SECURITY,
            module: 'payments',
            branchId: $source->branch_id,
            subjectType: PaymentSource::class,
            subjectId: $source->id,
            after: array_merge([
                'reference' => $source->reference,
                'provider' => $source->provider,
            ], $this->sanitizePayload($after)),
        );
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function auditSystem(string $action, PaymentSource $source, array $after = []): void
    {
        $this->audit->record(
            actor: null,
            action: $action,
            category: AuditEvent::CATEGORY_SECURITY,
            module: 'payments',
            branchId: $source->branch_id,
            subjectType: PaymentSource::class,
            subjectId: $source->id,
            after: $this->sanitizePayload($after),
        );
    }

    private function assertInScope(User $actor, PaymentSource $source): void
    {
        if ($source->branch_id === null) {
            return;
        }
        if (! $this->isInBranchScope($actor, (int) $source->branch_id)) {
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

    private function applyContributionBranchScope(Builder $query, User $actor): void
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
