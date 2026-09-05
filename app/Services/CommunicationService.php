<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\ChurchGroupMembership;
use App\Models\Communication;
use App\Models\CommunicationDelivery;
use App\Models\CommunicationSuppression;
use App\Models\Member;
use App\Models\MemberNotification;
use App\Models\RoleAssignment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Story 10.1: send permission-aware multi-channel communications.
 */
class CommunicationService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
    ) {
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Communication>
     */
    public function list(User $actor, array $filters = []): Collection
    {
        $this->assertCan($actor, 'communications.read');

        $query = Communication::query()
            ->with(['branch:id,name', 'creator:id,name'])
            ->orderByDesc('id');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['purpose'])) {
            $query->where('purpose', $filters['purpose']);
        }

        $this->applyBranchScope($query, $actor);

        return $query->limit(100)->get();
    }

    public function show(User $actor, Communication $communication): Communication
    {
        $this->assertCan($actor, 'communications.read');
        $this->assertInScope($actor, $communication);

        return $communication->load([
            'branch:id,name',
            'creator:id,name',
            'deliveries' => fn ($q) => $q->orderByDesc('id')->limit(100),
            'deliveries.member:id,first_name,last_name,membership_id',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(User $actor, array $payload): Communication
    {
        $this->assertCan($actor, 'communications.send');

        $validated = $this->validateCreatePayload($payload);
        if (! empty($validated['branch_id'])) {
            $this->assertBranchWritable($actor, (int) $validated['branch_id']);
        }

        $this->assertContentAllowed($validated['body'], (bool) ($validated['sensitive_content'] ?? false), $validated['channels']);

        $communication = DB::transaction(function () use ($actor, $validated): Communication {
            $scheduleType = $validated['schedule_type'];
            $scheduledAt = isset($validated['scheduled_at']) ? Carbon::parse($validated['scheduled_at']) : null;
            $nextRun = match ($scheduleType) {
                'immediate' => now(),
                'scheduled', 'recurring' => $scheduledAt ?? now(),
                'event', 'workflow' => $scheduledAt ?? now(),
                default => now(),
            };

            $communication = Communication::create([
                'reference' => $this->generateReference(),
                'branch_id' => $validated['branch_id'] ?? null,
                'name' => $validated['name'],
                'subject' => $validated['subject'],
                'body' => $validated['body'],
                'purpose' => $validated['purpose'],
                'channels' => array_values($validated['channels']),
                'audience_type' => $validated['audience_type'],
                'audience_params' => $validated['audience_params'] ?? [],
                'schedule_type' => $scheduleType,
                'scheduled_at' => $scheduledAt,
                'next_run_at' => $nextRun,
                'recurrence_rule' => $validated['recurrence_rule'] ?? null,
                'status' => Communication::STATUS_QUEUED,
                'sensitive_content' => (bool) ($validated['sensitive_content'] ?? false),
                'batch_size' => $validated['batch_size'] ?? (int) config('communications.batch_size', 50),
                'rate_limit_per_minute' => $validated['rate_limit_per_minute'] ?? (int) config('communications.rate_limit_per_minute', 120),
                'source_type' => $validated['source_type'] ?? null,
                'source_id' => $validated['source_id'] ?? null,
                'created_by' => $actor->id,
            ]);

            $recipients = $this->resolveAudience($actor, $communication);
            $queued = 0;
            foreach ($recipients as $member) {
                foreach ($communication->channels as $channel) {
                    $destination = $this->destinationFor($member, $channel);
                    CommunicationDelivery::query()->firstOrCreate(
                        [
                            'communication_id' => $communication->id,
                            'member_id' => $member->id,
                            'channel' => $channel,
                        ],
                        [
                            'destination' => $destination,
                            'status' => CommunicationDelivery::STATUS_PENDING,
                            'queued_at' => now(),
                        ],
                    );
                    $queued++;
                }
            }

            $communication->update(['queued_count' => $queued]);

            $this->audit($actor, 'communication.created', $communication, [
                'queued_count' => $queued,
                'channels' => $communication->channels,
                'audience_type' => $communication->audience_type,
            ]);

            return $communication->fresh(['deliveries']);
        });

        if ($communication->schedule_type === 'immediate'
            || ($communication->next_run_at !== null && $communication->next_run_at->lte(now()))) {
            $this->processCommunication($actor, $communication->fresh());
        }

        return $communication->fresh(['deliveries']);
    }

    public function cancel(User $actor, Communication $communication): Communication
    {
        $this->assertCan($actor, 'communications.cancel');
        $this->assertInScope($actor, $communication);

        if (in_array($communication->status, [Communication::STATUS_COMPLETED, Communication::STATUS_CANCELLED], true)) {
            throw new CommunicationException('Communication cannot be cancelled in its current status.', 'not_cancellable', 422);
        }

        $communication->update([
            'status' => Communication::STATUS_CANCELLED,
            'cancelled_by' => $actor->id,
            'cancelled_at' => now(),
            'next_run_at' => null,
        ]);

        CommunicationDelivery::query()
            ->where('communication_id', $communication->id)
            ->whereIn('status', [
                CommunicationDelivery::STATUS_PENDING,
                CommunicationDelivery::STATUS_QUEUED,
                CommunicationDelivery::STATUS_DEFERRED,
                CommunicationDelivery::STATUS_RETRIED,
            ])
            ->update([
                'status' => CommunicationDelivery::STATUS_SKIPPED,
                'skip_reason' => 'cancelled',
                'result' => ['reason' => 'cancelled'],
            ]);

        $this->audit($actor, 'communication.cancelled', $communication);

        return $communication->fresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function suppress(User $actor, array $payload): CommunicationSuppression
    {
        $this->assertCan($actor, 'communications.send');

        $validated = validator($payload, [
            'member_id' => ['required', 'integer', 'exists:members,id'],
            'channel' => ['nullable', 'string', 'in:' . implode(',', array_keys(config('communications.channels', [])))],
            'reason' => ['required', 'string', 'in:unsubscribe,bounce,complaint,manual'],
        ])->validate();

        $suppression = CommunicationSuppression::create([
            'member_id' => $validated['member_id'],
            'channel' => $validated['channel'] ?? null,
            'reason' => $validated['reason'],
            'active' => true,
            'created_by' => $actor->id,
            'suppressed_at' => now(),
        ]);

        $this->audit->record(
            actor: $actor,
            action: 'communication.suppression_added',
            category: AuditEvent::CATEGORY_BUSINESS,
            module: 'communications',
            branchId: null,
            subjectType: CommunicationSuppression::class,
            subjectId: $suppression->id,
            after: [
                'member_id' => $suppression->member_id,
                'channel' => $suppression->channel,
                'reason' => $suppression->reason,
            ],
        );

        return $suppression;
    }

    /**
     * Process due / deferred / immediate queued communications.
     *
     * @return array{processed: int, sent: int, skipped: int, failed: int, deferred: int}
     */
    public function processDue(User $actor, ?int $branchId = null): array
    {
        $this->assertCan($actor, 'communications.process');

        $counts = ['processed' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0, 'deferred' => 0];

        $query = Communication::query()
            ->whereIn('status', [Communication::STATUS_QUEUED, Communication::STATUS_PROCESSING])
            ->where(function (Builder $q): void {
                $q->whereNull('next_run_at')
                    ->orWhere('next_run_at', '<=', now());
            })
            ->orderBy('id');

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        } else {
            $this->applyBranchScope($query, $actor);
        }

        foreach ($query->limit(20)->get() as $communication) {
            $counts['processed']++;
            $result = $this->processCommunication($actor, $communication);
            $counts['sent'] += $result['sent'];
            $counts['skipped'] += $result['skipped'];
            $counts['failed'] += $result['failed'];
            $counts['deferred'] += $result['deferred'];
        }

        return $counts;
    }

    /**
     * @return array{sent: int, skipped: int, failed: int, deferred: int, retried: int}
     */
    public function processRetries(User $actor, ?int $branchId = null): array
    {
        $this->assertCan($actor, 'communications.process');

        $counts = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'deferred' => 0, 'retried' => 0];
        $maxRetries = (int) config('communications.max_retries', 3);

        $query = CommunicationDelivery::query()
            ->with('communication')
            ->whereIn('status', [CommunicationDelivery::STATUS_FAILED, CommunicationDelivery::STATUS_RETRIED])
            ->where('attempt', '<', $maxRetries)
            ->orderBy('id');

        if ($branchId !== null) {
            $query->whereHas('communication', fn (Builder $q) => $q->where('branch_id', $branchId));
        }

        $quota = (int) config('communications.provider_quota_per_run', 500);
        $used = 0;

        foreach ($query->limit(100)->get() as $delivery) {
            if ($used >= $quota) {
                break;
            }
            $communication = $delivery->communication;
            if ($communication === null || $communication->status === Communication::STATUS_CANCELLED) {
                $counts['skipped']++;

                continue;
            }

            $counts['retried']++;
            $outcome = $this->attemptDelivery($actor, $communication, $delivery);
            $counts[$outcome]++;
            $used++;
        }

        return $counts;
    }

    /**
     * @return array{sent: int, skipped: int, failed: int, deferred: int}
     */
    public function processCommunication(User $actor, Communication $communication): array
    {
        $counts = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'deferred' => 0];

        if ($communication->status === Communication::STATUS_CANCELLED) {
            return $counts;
        }

        $communication->update(['status' => Communication::STATUS_PROCESSING]);

        $batchSize = (int) ($communication->batch_size ?: config('communications.batch_size', 50));
        $rateLimit = (int) ($communication->rate_limit_per_minute ?: config('communications.rate_limit_per_minute', 120));
        $quota = min($batchSize, $rateLimit, (int) config('communications.provider_quota_per_run', 500));

        $deliveries = CommunicationDelivery::query()
            ->where('communication_id', $communication->id)
            ->where(function (Builder $q): void {
                $q->whereIn('status', [
                    CommunicationDelivery::STATUS_PENDING,
                    CommunicationDelivery::STATUS_QUEUED,
                    CommunicationDelivery::STATUS_RETRIED,
                ])->orWhere(function (Builder $inner): void {
                    $inner->where('status', CommunicationDelivery::STATUS_DEFERRED)
                        ->where(function (Builder $d): void {
                            $d->whereNull('deferred_until')
                                ->orWhere('deferred_until', '<=', now());
                        });
                });
            })
            ->orderBy('id')
            ->limit($quota)
            ->get();

        foreach ($deliveries as $delivery) {
            $outcome = $this->attemptDelivery($actor, $communication, $delivery);
            $counts[$outcome]++;
        }

        $remaining = CommunicationDelivery::query()
            ->where('communication_id', $communication->id)
            ->whereIn('status', [
                CommunicationDelivery::STATUS_PENDING,
                CommunicationDelivery::STATUS_QUEUED,
                CommunicationDelivery::STATUS_DEFERRED,
                CommunicationDelivery::STATUS_RETRIED,
            ])
            ->count();

        $communication->refresh();
        $communication->update([
            'sent_count' => CommunicationDelivery::query()->where('communication_id', $communication->id)->where('status', CommunicationDelivery::STATUS_SENT)->count(),
            'skipped_count' => CommunicationDelivery::query()->where('communication_id', $communication->id)->where('status', CommunicationDelivery::STATUS_SKIPPED)->count(),
            'failed_count' => CommunicationDelivery::query()->where('communication_id', $communication->id)->whereIn('status', [CommunicationDelivery::STATUS_FAILED, CommunicationDelivery::STATUS_RETRIED])->count(),
            'deferred_count' => CommunicationDelivery::query()->where('communication_id', $communication->id)->where('status', CommunicationDelivery::STATUS_DEFERRED)->count(),
            'status' => $remaining > 0 ? Communication::STATUS_QUEUED : Communication::STATUS_COMPLETED,
            'next_run_at' => $remaining > 0
                ? ($this->nextRecurrence($communication) ?? now()->addMinute())
                : ($communication->schedule_type === 'recurring' ? $this->nextRecurrence($communication) : null),
            'processed_at' => now(),
        ]);

        // Recurring: when a cycle completes, enqueue a fresh pending set for next run if rule set.
        if ($remaining === 0 && $communication->schedule_type === 'recurring' && $communication->recurrence_rule) {
            $communication->update([
                'status' => Communication::STATUS_QUEUED,
                'next_run_at' => $this->nextRecurrence($communication) ?? now()->addDay(),
            ]);
        }

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    public function format(Communication $communication, bool $includeBody = false): array
    {
        return [
            'id' => $communication->id,
            'reference' => $communication->reference,
            'branch_id' => $communication->branch_id,
            'branch' => $communication->relationLoaded('branch') ? $communication->branch : null,
            'name' => $communication->name,
            'subject' => $communication->subject,
            // Operators see status without full body unless explicitly requested for senders.
            'body' => $includeBody ? $communication->body : null,
            'body_present' => $communication->body !== null && $communication->body !== '',
            'purpose' => $communication->purpose,
            'channels' => $communication->channels,
            'audience_type' => $communication->audience_type,
            'audience_params' => $communication->audience_params,
            'schedule_type' => $communication->schedule_type,
            'scheduled_at' => $communication->scheduled_at?->toIso8601String(),
            'next_run_at' => $communication->next_run_at?->toIso8601String(),
            'recurrence_rule' => $communication->recurrence_rule,
            'status' => $communication->status,
            'sensitive_content' => $communication->sensitive_content,
            'queued_count' => $communication->queued_count,
            'sent_count' => $communication->sent_count,
            'skipped_count' => $communication->skipped_count,
            'failed_count' => $communication->failed_count,
            'deferred_count' => $communication->deferred_count,
            'created_by' => $communication->created_by,
            'creator' => $communication->relationLoaded('creator') ? $communication->creator : null,
            'processed_at' => $communication->processed_at?->toIso8601String(),
            'deliveries' => $communication->relationLoaded('deliveries')
                ? $communication->deliveries->map(fn (CommunicationDelivery $d) => [
                    'id' => $d->id,
                    'member_id' => $d->member_id,
                    'member' => $d->relationLoaded('member') ? $d->member : null,
                    'channel' => $d->channel,
                    'destination' => $this->maskDestination($d->destination),
                    'status' => $d->status,
                    'skip_reason' => $d->skip_reason,
                    'attempt' => $d->attempt,
                    'provider_ref' => $d->provider_ref,
                    'result' => $d->result,
                    'sent_at' => $d->sent_at?->toIso8601String(),
                    'deferred_until' => $d->deferred_until?->toIso8601String(),
                ])->values()->all()
                : [],
        ];
    }

    /**
     * @return 'sent'|'skipped'|'failed'|'deferred'
     */
    private function attemptDelivery(User $actor, Communication $communication, CommunicationDelivery $delivery): string
    {
        $member = Member::query()->find($delivery->member_id);
        if ($member === null) {
            return $this->markSkipped($delivery, 'missing_member');
        }

        // Already sent — idempotent
        if ($delivery->status === CommunicationDelivery::STATUS_SENT) {
            return 'skipped';
        }

        $existingSent = CommunicationDelivery::query()
            ->where('communication_id', $communication->id)
            ->where('member_id', $member->id)
            ->where('channel', $delivery->channel)
            ->where('status', CommunicationDelivery::STATUS_SENT)
            ->where('id', '!=', $delivery->id)
            ->exists();
        if ($existingSent) {
            return $this->markSkipped($delivery, 'already_delivered');
        }

        $policy = config('members.lifecycle.status_policies.' . ($member->lifecycle_status ?? 'active') . '.communications', 'enabled');
        if ($policy === 'none') {
            return $this->markSkipped($delivery, 'lifecycle_blocked');
        }

        if (! $member->consent_data_processing) {
            return $this->markSkipped($delivery, 'missing_consent');
        }

        if ($this->isSuppressed($member->id, $delivery->channel)) {
            return $this->markSkipped($delivery, 'unsubscribed');
        }

        if (! $this->channelPreferred($member, $delivery->channel)) {
            return $this->markSkipped($delivery, 'channel_preference');
        }

        $destination = $delivery->destination ?: $this->destinationFor($member, $delivery->channel);
        if ($destination === null || $destination === '') {
            return $this->markSkipped($delivery, 'invalid_destination');
        }

        if ($communication->sensitive_content) {
            $channelConfig = config('communications.channels.' . $delivery->channel, []);
            if (empty($channelConfig['allows_sensitive'])) {
                return $this->markSkipped($delivery, 'sensitive_content_prohibited');
            }
        }

        if ($this->inQuietHours($communication)) {
            $until = $this->quietHoursEnd();
            $delivery->update([
                'status' => CommunicationDelivery::STATUS_DEFERRED,
                'skip_reason' => 'quiet_hours',
                'deferred_until' => $until,
                'destination' => $destination,
                'result' => ['reason' => 'quiet_hours', 'deferred_until' => $until->toIso8601String()],
            ]);

            return 'deferred';
        }

        try {
            $providerResult = $this->dispatchToProvider($actor, $communication, $member, $delivery->channel, $destination);
            $delivery->update([
                'status' => CommunicationDelivery::STATUS_SENT,
                'skip_reason' => null,
                'attempt' => $delivery->attempt + 1,
                'destination' => $destination,
                'provider_ref' => $providerResult['provider_ref'],
                'sent_at' => now(),
                'deferred_until' => null,
                'result' => [
                    'channel' => $delivery->channel,
                    'provider' => $providerResult['provider'],
                    // Never store full message body on delivery rows.
                ],
            ]);

            return 'sent';
        } catch (\Throwable $exception) {
            $attempt = $delivery->attempt + 1;
            $maxRetries = (int) config('communications.max_retries', 3);
            $status = $attempt >= $maxRetries
                ? CommunicationDelivery::STATUS_FAILED
                : CommunicationDelivery::STATUS_RETRIED;

            $delivery->update([
                'status' => $status,
                'skip_reason' => 'provider_failure',
                'attempt' => $attempt,
                'destination' => $destination,
                'result' => [
                    'error_class' => class_basename($exception),
                    'message' => Str::limit($exception->getMessage(), 160),
                ],
            ]);

            return 'failed';
        }
    }

    /**
     * @return array{provider: string, provider_ref: string}
     */
    private function dispatchToProvider(
        User $actor,
        Communication $communication,
        Member $member,
        string $channel,
        string $destination,
    ): array {
        // Simulated providers — fail destinations used in tests.
        if (str_starts_with(strtolower($destination), 'fail@') || str_starts_with($destination, '+000')) {
            throw new CommunicationException('Provider rejected destination.', 'provider_failure', 502);
        }

        if ($channel === 'in_app') {
            if ($member->user_id === null) {
                throw new CommunicationException('In-app delivery requires a linked user.', 'invalid_destination', 422);
            }
            MemberNotification::create([
                'member_id' => $member->id,
                'user_id' => $member->user_id,
                'type' => 'communication.' . $communication->purpose,
                'message' => Str::limit($communication->subject, 180),
                'metadata' => [
                    'communication_id' => $communication->id,
                    'reference' => $communication->reference,
                    'channel' => 'in_app',
                    // Omit body for inbox metadata minimization.
                ],
            ]);

            return ['provider' => 'in_app', 'provider_ref' => 'inapp-' . $communication->id . '-' . $member->id];
        }

        // email / sms / push / external — queued to simulated provider
        return [
            'provider' => 'simulated_' . $channel,
            'provider_ref' => strtoupper($channel) . '-' . Str::upper(Str::random(8)),
        ];
    }

    /**
     * @return Collection<int, Member>
     */
    private function resolveAudience(User $actor, Communication $communication): Collection
    {
        $params = $communication->audience_params ?? [];
        $query = Member::query()->whereNull('archived_at')->whereNull('merged_into_id');

        switch ($communication->audience_type) {
            case 'branch':
                $branchId = (int) ($params['branch_id'] ?? $communication->branch_id ?? 0);
                if ($branchId < 1) {
                    throw new CommunicationException('Branch audience requires branch_id.', 'invalid_audience', 422);
                }
                $this->assertBranchWritable($actor, $branchId);
                $query->where('branch_id', $branchId);
                break;

            case 'role':
                $roleId = (int) ($params['role_id'] ?? 0);
                if ($roleId < 1) {
                    throw new CommunicationException('Role audience requires role_id.', 'invalid_audience', 422);
                }
                $userIds = RoleAssignment::query()
                    ->where('role_id', $roleId)
                    ->where(function (Builder $q): void {
                        $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                    })
                    ->pluck('user_id');
                $query->whereIn('user_id', $userIds);
                if ($communication->branch_id) {
                    $query->where('branch_id', $communication->branch_id);
                }
                break;

            case 'group':
                $groupId = (int) ($params['group_id'] ?? 0);
                if ($groupId < 1) {
                    throw new CommunicationException('Group audience requires group_id.', 'invalid_audience', 422);
                }
                $memberIds = ChurchGroupMembership::query()
                    ->where('church_group_id', $groupId)
                    ->where('status', ChurchGroupMembership::STATUS_ACTIVE)
                    ->pluck('member_id');
                $query->whereIn('id', $memberIds);
                break;

            case 'members':
                $ids = array_values(array_filter(array_map('intval', $params['member_ids'] ?? [])));
                if ($ids === []) {
                    throw new CommunicationException('Members audience requires member_ids.', 'invalid_audience', 422);
                }
                $query->whereIn('id', $ids);
                break;

            default:
                throw new CommunicationException('Unsupported audience type.', 'invalid_audience', 422);
        }

        return $query->limit(2000)->get();
    }

    private function destinationFor(Member $member, string $channel): ?string
    {
        return match ($channel) {
            'email', 'external' => $member->email,
            'sms' => $member->phone,
            'push', 'in_app' => $member->user_id ? ('user:' . $member->user_id) : null,
            default => null,
        };
    }

    private function channelPreferred(Member $member, string $channel): bool
    {
        $prefs = $member->communication_preferences ?? [];
        if ($prefs === []) {
            return true; // no explicit prefs → allow permitted channels
        }

        $key = config('communications.preference_keys.' . $channel, $channel);
        if (! array_key_exists($key, $prefs)) {
            return true;
        }

        return (bool) $prefs[$key];
    }

    private function isSuppressed(int $memberId, string $channel): bool
    {
        return CommunicationSuppression::query()
            ->where('member_id', $memberId)
            ->where('active', true)
            ->where(function (Builder $q) use ($channel): void {
                $q->whereNull('channel')->orWhere('channel', $channel);
            })
            ->exists();
    }

    private function inQuietHours(Communication $communication): bool
    {
        $config = config('communications.quiet_hours', []);
        if (empty($config['enabled'])) {
            return false;
        }

        if (in_array($communication->purpose, $config['bypass_purposes'] ?? [], true)) {
            return false;
        }

        $now = now();
        $start = Carbon::parse($now->toDateString() . ' ' . ($config['start'] ?? '22:00'));
        $end = Carbon::parse($now->toDateString() . ' ' . ($config['end'] ?? '07:00'));

        if ($end->lte($start)) {
            // Overnight window e.g. 22:00–07:00
            return $now->gte($start) || $now->lt($end);
        }

        return $now->between($start, $end);
    }

    private function quietHoursEnd(): Carbon
    {
        $config = config('communications.quiet_hours', []);
        $now = now();
        $end = Carbon::parse($now->toDateString() . ' ' . ($config['end'] ?? '07:00'));
        if ($end->lte($now)) {
            $end->addDay();
        }

        return $end;
    }

    private function nextRecurrence(Communication $communication): ?Carbon
    {
        if ($communication->schedule_type !== 'recurring') {
            return null;
        }

        return match ($communication->recurrence_rule) {
            'hourly' => now()->addHour(),
            'daily' => now()->addDay(),
            'weekly' => now()->addWeek(),
            default => now()->addDay(),
        };
    }

    /**
     * @return 'skipped'
     */
    private function markSkipped(CommunicationDelivery $delivery, string $reason): string
    {
        $delivery->update([
            'status' => CommunicationDelivery::STATUS_SKIPPED,
            'skip_reason' => $reason,
            'result' => ['reason' => $reason],
        ]);

        return 'skipped';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateCreatePayload(array $payload): array
    {
        $channels = implode(',', array_keys(config('communications.channels', [])));
        $purposes = implode(',', config('communications.purposes', []));
        $audiences = implode(',', config('communications.audience_types', []));
        $schedules = implode(',', config('communications.schedule_types', []));

        return validator($payload, [
            'name' => ['required', 'string', 'min:3', 'max:160'],
            'subject' => ['required', 'string', 'min:3', 'max:200'],
            'body' => ['required', 'string', 'min:1', 'max:20000'],
            'purpose' => ['required', 'string', 'in:' . $purposes],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => ['string', 'in:' . $channels],
            'audience_type' => ['required', 'string', 'in:' . $audiences],
            'audience_params' => ['nullable', 'array'],
            'audience_params.branch_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'audience_params.role_id' => ['nullable', 'integer', 'exists:roles,id'],
            'audience_params.group_id' => ['nullable', 'integer'],
            'audience_params.member_ids' => ['nullable', 'array'],
            'audience_params.member_ids.*' => ['integer', 'exists:members,id'],
            'schedule_type' => ['required', 'string', 'in:' . $schedules],
            'scheduled_at' => ['nullable', 'date'],
            'recurrence_rule' => ['nullable', 'string', 'in:hourly,daily,weekly'],
            'branch_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'sensitive_content' => ['nullable', 'boolean'],
            'batch_size' => ['nullable', 'integer', 'min:1', 'max:500'],
            'rate_limit_per_minute' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'source_type' => ['nullable', 'string', 'max:120'],
            'source_id' => ['nullable', 'integer'],
        ])->validate();
    }

    /**
     * @param  array<int, string>  $channels
     */
    private function assertContentAllowed(string $body, bool $sensitive, array $channels): void
    {
        foreach (config('communications.prohibited_patterns', []) as $pattern) {
            if (preg_match($pattern, $body)) {
                throw new CommunicationException(
                    'Message body contains prohibited sensitive content.',
                    'prohibited_content',
                    422,
                );
            }
        }

        if ($sensitive) {
            foreach ($channels as $channel) {
                $allows = config('communications.channels.' . $channel . '.allows_sensitive', false);
                if (! $allows) {
                    throw new CommunicationException(
                        "Channel {$channel} does not allow sensitive content.",
                        'sensitive_channel_denied',
                        422,
                    );
                }
            }
        }
    }

    private function maskDestination(?string $destination): ?string
    {
        if ($destination === null || $destination === '') {
            return null;
        }

        if (str_contains($destination, '@')) {
            [$local, $domain] = array_pad(explode('@', $destination, 2), 2, '');

            return Str::substr($local, 0, 1) . '***@' . $domain;
        }

        if (str_starts_with($destination, 'user:')) {
            return $destination;
        }

        $len = strlen($destination);

        return $len <= 4 ? '****' : str_repeat('*', $len - 4) . substr($destination, -4);
    }

    private function generateReference(): string
    {
        return 'COM-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function audit(User $actor, string $action, Communication $communication, array $after = []): void
    {
        $this->audit->record(
            actor: $actor,
            action: $action,
            category: AuditEvent::CATEGORY_BUSINESS,
            module: 'communications',
            branchId: $communication->branch_id,
            subjectType: Communication::class,
            subjectId: $communication->id,
            after: array_merge([
                'reference' => $communication->reference,
                'status' => $communication->status,
            ], $after),
        );
    }

    private function assertInScope(User $actor, Communication $communication): void
    {
        if ($communication->branch_id === null) {
            return;
        }

        if (! $this->isInBranchScope($actor, (int) $communication->branch_id)) {
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
