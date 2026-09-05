<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\ChurchGroup;
use App\Models\ChurchGroupMembership;
use App\Models\Member;
use App\Models\MemberNotification;
use App\Models\PrayerRequest;
use App\Models\PrayerRequestActivity;
use App\Models\PrayerRequestConfidentialityEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stories 8.3–8.4: submit and process prayer requests with confidentiality.
 */
class PrayerRequestService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function submit(User $actor, array $payload): PrayerRequest
    {
        $validated = $this->validateSubmitPayload($payload);
        $assisted = (bool) ($validated['assisted'] ?? false);

        if ($assisted) {
            $this->assertCan($actor, 'prayer.requests.submit');
        } else {
            if (! $this->authorization->allows($actor, 'prayer.requests.submit.self')
                && ! $this->authorization->allows($actor, 'prayer.requests.submit')) {
                throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
            }
        }

        $requester = $this->resolveRequester($actor, $validated, $assisted);
        $branchId = (int) ($validated['branch_id'] ?? $requester->branch_id);
        $this->assertBranchWritable($actor, $branchId);

        if ((int) $requester->branch_id !== $branchId) {
            throw ValidationException::withMessages([
                'branch_id' => ['Requester must belong to the selected branch.'],
            ]);
        }

        $scope = $validated['confidentiality'];
        $groupId = null;
        if ($scope === PrayerRequest::SCOPE_GROUP) {
            $groupId = (int) ($validated['church_group_id'] ?? 0);
            $this->assertGroupAllowed($requester, $groupId, $branchId);
        }

        if (! $validated['consent_prayer_processing']) {
            throw ValidationException::withMessages([
                'consent_prayer_processing' => ['Consent to prayer processing is required.'],
            ]);
        }

        if (in_array($scope, [PrayerRequest::SCOPE_GROUP, PrayerRequest::SCOPE_PUBLIC_TESTIMONY, PrayerRequest::SCOPE_PRAYER_TEAM], true)
            && empty($validated['consent_sharing'])) {
            throw ValidationException::withMessages([
                'consent_sharing' => ['Consent to share with the selected audience is required.'],
            ]);
        }

        return DB::transaction(function () use ($actor, $validated, $requester, $branchId, $scope, $groupId, $assisted): PrayerRequest {
            $request = PrayerRequest::create([
                'reference' => $this->generateReference(),
                'branch_id' => $branchId,
                'requester_member_id' => $requester->id,
                'requester_user_id' => $requester->user_id,
                'submitted_by_user_id' => $actor->id,
                'assisted_submission' => $assisted || (int) $requester->user_id !== (int) $actor->id,
                'category' => $validated['category'],
                'priority' => $validated['priority'],
                'request_body' => $validated['request_body'],
                'confidentiality' => $scope,
                'church_group_id' => $groupId,
                'consent_prayer_processing' => true,
                'consent_sharing' => (bool) ($validated['consent_sharing'] ?? false),
                'status' => PrayerRequest::STATUS_SUBMITTED,
                'data_classification' => (string) config('prayer_requests.data_classification', 'restricted_sensitive'),
                'is_restricted' => $scope !== PrayerRequest::SCOPE_PUBLIC_TESTIMONY,
                'submitted_at' => now(),
                'propagation_completed_at' => now(),
            ]);

            PrayerRequestConfidentialityEvent::create([
                'prayer_request_id' => $request->id,
                'from_confidentiality' => null,
                'to_confidentiality' => $scope,
                'change_type' => PrayerRequestConfidentialityEvent::TYPE_INITIAL,
                'actor_id' => $actor->id,
                'effective_at' => now(),
                'propagation_completed_at' => now(),
                'created_at' => now(),
            ]);

            $this->audit->record(
                actor: $actor,
                action: 'prayer_request.submitted',
                category: AuditEvent::CATEGORY_BUSINESS,
                module: 'prayer',
                branchId: $request->branch_id,
                subjectType: PrayerRequest::class,
                subjectId: $request->id,
                after: [
                    'reference' => $request->reference,
                    'category' => $request->category,
                    'confidentiality' => $request->confidentiality,
                    'assisted_submission' => $request->assisted_submission,
                    // Never log request body.
                ],
            );

            return $request->fresh(['branch:id,name', 'requester:id,first_name,last_name', 'churchGroup:id,name']);
        });
    }

    /**
     * @return Collection<int, PrayerRequest>
     */
    public function listMine(User $actor): Collection
    {
        $member = Member::query()->where('user_id', $actor->id)->first();
        if ($member === null) {
            if (! $this->authorization->allows($actor, 'prayer.requests.read.self')) {
                throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
            }

            return collect();
        }

        return PrayerRequest::query()
            ->with(['branch:id,name', 'churchGroup:id,name'])
            ->where('requester_member_id', $member->id)
            ->orderByDesc('id')
            ->limit(100)
            ->get();
    }

    /**
     * Audience-filtered discovery list.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, PrayerRequest>
     */
    public function listDiscoverable(User $actor, array $filters = []): Collection
    {
        $query = PrayerRequest::query()
            ->with(['branch:id,name', 'churchGroup:id,name'])
            ->where('status', '!=', PrayerRequest::STATUS_WITHDRAWN)
            ->orderByDesc('id');

        $this->applyBranchScope($query, $actor);
        $this->applyAudienceFilter($query, $actor);

        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (! empty($filters['confidentiality'])) {
            $query->where('confidentiality', $filters['confidentiality']);
        }

        return $query->limit(200)->get();
    }

    public function show(User $actor, PrayerRequest $request): PrayerRequest
    {
        if (! $this->canDiscover($actor, $request)) {
            $this->auditAccessDenied($actor, $request, 'show');

            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }

        return $request->load([
            'branch:id,name',
            'requester:id,first_name,last_name,membership_id',
            'churchGroup:id,name',
            'confidentialityEvents',
            'assignedOfficer:id,name',
            'activities.actor:id,name',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function assign(User $actor, PrayerRequest $request, array $payload): PrayerRequest
    {
        $this->assertCanProcess($actor, $request, 'assign');

        $validated = validator($payload, [
            'assignee_id' => ['required', 'integer', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        $assignee = User::query()->findOrFail((int) $validated['assignee_id']);
        $this->assertEligibleAssignee($assignee, $request);

        return DB::transaction(function () use ($actor, $request, $assignee, $validated): PrayerRequest {
            $from = $request->assigned_officer_id;

            $request->update([
                'assigned_officer_id' => $assignee->id,
                'assigned_at' => now(),
                'status' => $request->status === PrayerRequest::STATUS_SUBMITTED
                    ? PrayerRequest::STATUS_ACKNOWLEDGED
                    : $request->status,
            ]);

            $this->recordActivity($actor, $request, 'assignment', $request->status, $validated['notes'] ?? null, null, $from, $assignee->id);
            $this->auditProcess($actor, 'prayer_request.assigned', $request, [
                'from_officer_id' => $from,
                'to_officer_id' => $assignee->id,
            ]);
            $this->notifyUser(
                $assignee,
                'prayer.request.assigned',
                'A prayer request was assigned to you.',
                $request,
            );

            return $request->fresh(['assignedOfficer:id,name', 'activities.actor:id,name']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function acknowledge(User $actor, PrayerRequest $request, array $payload = []): PrayerRequest
    {
        $this->assertCanProcess($actor, $request, 'acknowledge');

        $validated = validator($payload, [
            'notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        return DB::transaction(function () use ($actor, $request, $validated): PrayerRequest {
            $request->update([
                'status' => PrayerRequest::STATUS_ACKNOWLEDGED,
                'acknowledged_at' => now(),
                'assigned_officer_id' => $request->assigned_officer_id ?? $actor->id,
                'assigned_at' => $request->assigned_at ?? now(),
            ]);

            $this->recordActivity($actor, $request, 'acknowledgement', PrayerRequest::STATUS_ACKNOWLEDGED, $validated['notes'] ?? null);
            $this->auditProcess($actor, 'prayer_request.acknowledged', $request);
            $this->notifyRequesterMinimal($request, 'prayer.request.acknowledged', 'Your prayer request was acknowledged by the prayer team.');

            return $request->fresh(['assignedOfficer:id,name', 'activities.actor:id,name']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function recordUpdate(User $actor, PrayerRequest $request, array $payload): PrayerRequest
    {
        $this->assertCanProcess($actor, $request, 'update');

        $validated = validator($payload, [
            'notes' => ['nullable', 'string', 'max:2000'],
            'restricted_notes' => ['nullable', 'string', 'max:5000'],
            'status' => ['nullable', 'string', 'in:acknowledged,in_prayer'],
        ])->validate();

        return DB::transaction(function () use ($actor, $request, $validated): PrayerRequest {
            $status = $validated['status'] ?? PrayerRequest::STATUS_IN_PRAYER;
            $request->update([
                'status' => $status,
                'process_notes' => $validated['restricted_notes'] ?? $request->process_notes,
            ]);

            $this->recordActivity(
                $actor,
                $request,
                'update',
                $status,
                $validated['notes'] ?? null,
                $validated['restricted_notes'] ?? null,
            );
            $this->auditProcess($actor, 'prayer_request.updated', $request, ['status' => $status]);

            return $request->fresh(['assignedOfficer:id,name', 'activities.actor:id,name']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function escalate(User $actor, PrayerRequest $request, array $payload): PrayerRequest
    {
        $this->assertCanProcess($actor, $request, 'escalate');

        $validated = validator($payload, [
            'to_officer_id' => ['required', 'integer', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        $target = User::query()->findOrFail((int) $validated['to_officer_id']);
        if (! $this->authorization->allows($target, 'prayer.requests.read.pastor')
            && ! $this->authorization->allows($target, 'prayer.requests.escalate')) {
            throw ValidationException::withMessages([
                'to_officer_id' => ['Escalation target must have pastor or escalation clearance.'],
            ]);
        }

        if (! $this->isInBranchScope($target, (int) $request->branch_id) && ! $target->isChurchWide()) {
            throw ValidationException::withMessages([
                'to_officer_id' => ['Escalation target is outside the request branch scope.'],
            ]);
        }

        return DB::transaction(function () use ($actor, $request, $target, $validated): PrayerRequest {
            $from = $request->assigned_officer_id;
            $request->update([
                'assigned_officer_id' => $target->id,
                'assigned_at' => now(),
                'escalated_at' => now(),
                'status' => PrayerRequest::STATUS_IN_PRAYER,
                'priority' => in_array($request->priority, ['urgent', 'high'], true) ? $request->priority : 'high',
            ]);

            $this->recordActivity($actor, $request, 'escalation', $request->status, $validated['notes'] ?? null, null, $from, $target->id, [
                'trigger' => 'manual',
            ]);
            $this->auditProcess($actor, 'prayer_request.escalated', $request, [
                'from_officer_id' => $from,
                'to_officer_id' => $target->id,
            ], AuditEvent::CATEGORY_SECURITY);
            $this->notifyUser(
                $target,
                'prayer.request.escalated',
                'A prayer request was escalated to you.',
                $request,
            );

            return $request->fresh(['assignedOfficer:id,name', 'activities.actor:id,name']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function markAnswered(User $actor, PrayerRequest $request, array $payload = []): PrayerRequest
    {
        $this->assertCanProcess($actor, $request, 'answer');

        $validated = validator($payload, [
            'notes' => ['nullable', 'string', 'max:2000'],
            'restricted_notes' => ['nullable', 'string', 'max:5000'],
        ])->validate();

        return DB::transaction(function () use ($actor, $request, $validated): PrayerRequest {
            $request->update([
                'status' => PrayerRequest::STATUS_ANSWERED,
                'answered_at' => now(),
                'process_notes' => $validated['restricted_notes'] ?? $request->process_notes,
            ]);

            $this->recordActivity(
                $actor,
                $request,
                'answered',
                PrayerRequest::STATUS_ANSWERED,
                $validated['notes'] ?? null,
                $validated['restricted_notes'] ?? null,
            );
            $this->auditProcess($actor, 'prayer_request.answered', $request);
            $this->notifyRequesterMinimal($request, 'prayer.request.answered', 'Your prayer request was marked as answered.');

            return $request->fresh(['assignedOfficer:id,name', 'activities.actor:id,name']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function close(User $actor, PrayerRequest $request, array $payload = []): PrayerRequest
    {
        $this->assertCanProcess($actor, $request, 'close');

        $validated = validator($payload, [
            'notes' => ['nullable', 'string', 'max:2000'],
            'restricted_notes' => ['nullable', 'string', 'max:5000'],
        ])->validate();

        return DB::transaction(function () use ($actor, $request, $validated): PrayerRequest {
            $request->update([
                'status' => PrayerRequest::STATUS_CLOSED,
                'closed_at' => now(),
                'process_notes' => $validated['restricted_notes'] ?? $request->process_notes,
            ]);

            $this->recordActivity(
                $actor,
                $request,
                'closure',
                PrayerRequest::STATUS_CLOSED,
                $validated['notes'] ?? null,
                $validated['restricted_notes'] ?? null,
            );
            $this->auditProcess($actor, 'prayer_request.closed', $request);
            $this->notifyRequesterMinimal($request, 'prayer.request.closed', 'Your prayer request was closed.');

            return $request->fresh(['assignedOfficer:id,name', 'activities.actor:id,name']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function publishToGroup(User $actor, PrayerRequest $request, array $payload = []): PrayerRequest
    {
        $this->assertCanProcess($actor, $request, 'publish');

        if ($request->confidentiality === PrayerRequest::SCOPE_PRIVATE
            || $request->confidentiality === PrayerRequest::SCOPE_PASTOR_ONLY) {
            $this->auditAccessDenied($actor, $request, 'group_publication');

            throw new PrayerRequestException(
                'Private and pastor-only prayer requests cannot be published to a group.',
                'publication_denied',
                422,
            );
        }

        if ($request->isWithdrawn() || ! $request->consent_sharing) {
            $this->auditAccessDenied($actor, $request, 'group_publication_no_consent');

            throw new PrayerRequestException(
                'This prayer request is not consented for group sharing.',
                'publication_denied',
                422,
            );
        }

        if ($request->confidentiality !== PrayerRequest::SCOPE_GROUP || $request->church_group_id === null) {
            throw new PrayerRequestException(
                'Only group-scoped prayer requests can be published to a group.',
                'invalid_scope',
                422,
            );
        }

        if (! $this->isInBranchScope($actor, (int) $request->branch_id)) {
            $this->auditAccessDenied($actor, $request, 'group_publication_branch');

            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }

        $validated = validator($payload, [
            'notes' => ['nullable', 'string', 'max:500'],
        ])->validate();

        return DB::transaction(function () use ($actor, $request, $validated): PrayerRequest {
            $request->update([
                'published_to_group' => true,
                'published_to_group_at' => now(),
            ]);

            $this->recordActivity($actor, $request, 'group_publication', $request->status, $validated['notes'] ?? 'Published to group.', null, null, null, [
                'church_group_id' => $request->church_group_id,
            ]);
            $this->auditProcess($actor, 'prayer_request.published_to_group', $request, [
                'church_group_id' => $request->church_group_id,
            ]);

            return $request->fresh(['churchGroup:id,name', 'activities.actor:id,name']);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function formatActivity(PrayerRequestActivity $activity, bool $includeRestricted): array
    {
        return [
            'id' => $activity->id,
            'activity_type' => $activity->activity_type,
            'status_after' => $activity->status_after,
            'notes' => $activity->notes,
            'restricted_notes' => $includeRestricted ? $activity->restricted_notes : null,
            'from_officer_id' => $activity->from_officer_id,
            'to_officer_id' => $activity->to_officer_id,
            'actor' => $activity->relationLoaded('actor') ? $activity->actor : null,
            'recorded_at' => $activity->recorded_at?->toIso8601String(),
        ];
    }

    /**
     * Narrow confidentiality or withdraw public/testimony sharing.
     *
     * @param  array<string, mixed>  $payload
     */
    public function updateConfidentiality(User $actor, PrayerRequest $request, array $payload): PrayerRequest
    {
        $this->assertCanManageOwnOrAssist($actor, $request);

        if ($request->isWithdrawn()) {
            throw new PrayerRequestException('Withdrawn prayer requests cannot change confidentiality.', 'withdrawn', 422);
        }

        $validated = validator($payload, [
            'confidentiality' => ['required', 'string', 'in:' . implode(',', array_keys(config('prayer_requests.confidentiality_scopes', [])))],
            'church_group_id' => ['nullable', 'integer', 'exists:church_groups,id'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'withdraw' => ['nullable', 'boolean'],
        ])->validate();

        if (! empty($validated['withdraw'])) {
            return $this->withdraw($actor, $request, [
                'reason' => $validated['reason'] ?? 'Requester withdrew sharing consent.',
            ]);
        }

        $from = $request->confidentiality;
        $to = $validated['confidentiality'];

        if ($this->scopeRank($to) > $this->scopeRank($from)) {
            throw new PrayerRequestException(
                'Confidentiality can only be narrowed, not broadened, after submission.',
                'cannot_broaden',
                422,
                ['from' => $from, 'to' => $to],
            );
        }

        if ($from === $to && (int) ($validated['church_group_id'] ?? $request->church_group_id) === (int) $request->church_group_id) {
            return $request->fresh(['branch:id,name', 'churchGroup:id,name', 'confidentialityEvents']);
        }

        $groupId = $request->church_group_id;
        if ($to === PrayerRequest::SCOPE_GROUP) {
            $groupId = (int) ($validated['church_group_id'] ?? $request->church_group_id);
            if ($groupId <= 0) {
                throw ValidationException::withMessages([
                    'church_group_id' => ['A church group is required for group confidentiality.'],
                ]);
            }
            $requester = Member::query()->findOrFail($request->requester_member_id);
            $this->assertGroupAllowed($requester, $groupId, (int) $request->branch_id);
        } elseif ($to !== PrayerRequest::SCOPE_GROUP) {
            $groupId = null;
        }

        $hours = (int) config('prayer_requests.propagation_hours', 1);
        $propagationCompletedAt = now()->addHours(max(0, $hours));

        return DB::transaction(function () use ($actor, $request, $from, $to, $groupId, $validated, $propagationCompletedAt): PrayerRequest {
            // Stricter scope applies immediately for discovery / indexes.
            $request->update([
                'previous_confidentiality' => $from,
                'confidentiality' => $to,
                'church_group_id' => $groupId,
                'is_restricted' => $to !== PrayerRequest::SCOPE_PUBLIC_TESTIMONY,
                'consent_sharing' => in_array($to, [
                    PrayerRequest::SCOPE_PRIVATE,
                    PrayerRequest::SCOPE_PASTOR_ONLY,
                ], true) ? false : $request->consent_sharing,
                'confidentiality_changed_at' => now(),
                'propagation_completed_at' => $propagationCompletedAt,
            ]);

            PrayerRequestConfidentialityEvent::create([
                'prayer_request_id' => $request->id,
                'from_confidentiality' => $from,
                'to_confidentiality' => $to,
                'change_type' => PrayerRequestConfidentialityEvent::TYPE_NARROWED,
                'reason' => $validated['reason'] ?? null,
                'actor_id' => $actor->id,
                'effective_at' => now(),
                'propagation_completed_at' => $propagationCompletedAt,
                'metadata' => [
                    'public_exposure_ended' => $from === PrayerRequest::SCOPE_PUBLIC_TESTIMONY
                        && $to !== PrayerRequest::SCOPE_PUBLIC_TESTIMONY,
                ],
                'created_at' => now(),
            ]);

            $this->audit->record(
                actor: $actor,
                action: 'prayer_request.confidentiality_narrowed',
                category: AuditEvent::CATEGORY_SECURITY,
                module: 'prayer',
                branchId: $request->branch_id,
                subjectType: PrayerRequest::class,
                subjectId: $request->id,
                before: ['confidentiality' => $from],
                after: [
                    'confidentiality' => $to,
                    'propagation_completed_at' => $propagationCompletedAt->toIso8601String(),
                ],
            );

            return $request->fresh(['branch:id,name', 'churchGroup:id,name', 'confidentialityEvents']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function withdraw(User $actor, PrayerRequest $request, array $payload = []): PrayerRequest
    {
        $this->assertCanManageOwnOrAssist($actor, $request);

        if ($request->isWithdrawn()) {
            throw new PrayerRequestException('Prayer request is already withdrawn.', 'already_withdrawn', 422);
        }

        $validated = validator($payload, [
            'reason' => ['nullable', 'string', 'max:1000'],
        ])->validate();

        $from = $request->confidentiality;
        $hours = (int) config('prayer_requests.propagation_hours', 1);
        $propagationCompletedAt = now()->addHours(max(0, $hours));

        return DB::transaction(function () use ($actor, $request, $from, $validated, $propagationCompletedAt): PrayerRequest {
            $request->update([
                'previous_confidentiality' => $from,
                'confidentiality' => PrayerRequest::SCOPE_PRIVATE,
                'status' => PrayerRequest::STATUS_WITHDRAWN,
                'withdrawn_at' => now(),
                'withdrawal_reason' => $validated['reason'] ?? 'Requester withdrew the prayer request from sharing.',
                'consent_sharing' => false,
                'is_restricted' => true,
                'church_group_id' => null,
                'confidentiality_changed_at' => now(),
                'propagation_completed_at' => $propagationCompletedAt,
            ]);

            PrayerRequestConfidentialityEvent::create([
                'prayer_request_id' => $request->id,
                'from_confidentiality' => $from,
                'to_confidentiality' => PrayerRequest::SCOPE_PRIVATE,
                'change_type' => PrayerRequestConfidentialityEvent::TYPE_WITHDRAWN,
                'reason' => $validated['reason'] ?? null,
                'actor_id' => $actor->id,
                'effective_at' => now(),
                'propagation_completed_at' => $propagationCompletedAt,
                'metadata' => [
                    'public_exposure_ended' => true,
                    'prior_authorized_processing_retained' => true,
                ],
                'created_at' => now(),
            ]);

            $this->audit->record(
                actor: $actor,
                action: 'prayer_request.withdrawn',
                category: AuditEvent::CATEGORY_SECURITY,
                module: 'prayer',
                branchId: $request->branch_id,
                subjectType: PrayerRequest::class,
                subjectId: $request->id,
                before: ['confidentiality' => $from, 'status' => PrayerRequest::STATUS_SUBMITTED],
                after: [
                    'confidentiality' => PrayerRequest::SCOPE_PRIVATE,
                    'status' => PrayerRequest::STATUS_WITHDRAWN,
                    'propagation_completed_at' => $propagationCompletedAt->toIso8601String(),
                ],
            );

            return $request->fresh(['branch:id,name', 'confidentialityEvents']);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function formatForActor(PrayerRequest $request, User $actor): array
    {
        $canReadBody = $this->canDiscover($actor, $request);
        $canProcess = $this->mayProcess($actor, $request);
        $scopes = config('prayer_requests.confidentiality_scopes', []);
        $categories = config('prayer_requests.categories', []);
        $isOwner = $this->isOwner($actor, $request);

        return [
            'id' => $request->id,
            'reference' => $request->reference,
            'branch_id' => $request->branch_id,
            'branch' => $request->relationLoaded('branch') ? $request->branch : null,
            'category' => $request->category,
            'category_label' => $categories[$request->category] ?? $request->category,
            'priority' => $request->priority,
            'request_body' => $canReadBody ? $request->request_body : null,
            'confidentiality' => $request->confidentiality,
            'confidentiality_label' => $scopes[$request->confidentiality]['label'] ?? $request->confidentiality,
            'previous_confidentiality' => $isOwner || $this->authorization->allows($actor, 'prayer.requests.read.pastor')
                ? $request->previous_confidentiality
                : null,
            'church_group_id' => $request->church_group_id,
            'church_group' => $request->relationLoaded('churchGroup') ? $request->churchGroup : null,
            'consent_prayer_processing' => $request->consent_prayer_processing,
            'consent_sharing' => $request->consent_sharing,
            'status' => $request->status,
            'data_classification' => $request->data_classification,
            'is_restricted' => $request->is_restricted,
            'assisted_submission' => $request->assisted_submission,
            'requester_member_id' => $isOwner || $canReadBody ? $request->requester_member_id : null,
            'requester' => ($isOwner || $canReadBody) && $request->relationLoaded('requester') && $request->requester
                ? [
                    'id' => $request->requester->id,
                    'name' => $request->requester->fullName(),
                ]
                : null,
            'submitted_at' => $request->submitted_at?->toIso8601String(),
            'confidentiality_changed_at' => $request->confidentiality_changed_at?->toIso8601String(),
            'propagation_completed_at' => $request->propagation_completed_at?->toIso8601String(),
            'propagation_pending' => $request->propagation_completed_at !== null
                && $request->propagation_completed_at->isFuture(),
            'withdrawn_at' => $request->withdrawn_at?->toIso8601String(),
            'withdrawal_reason' => $isOwner ? $request->withdrawal_reason : null,
            'assigned_officer_id' => $canReadBody ? $request->assigned_officer_id : null,
            'assigned_officer' => $canReadBody && $request->relationLoaded('assignedOfficer')
                ? $request->assignedOfficer
                : null,
            'assigned_at' => $canReadBody ? $request->assigned_at?->toIso8601String() : null,
            'acknowledged_at' => $canReadBody ? $request->acknowledged_at?->toIso8601String() : null,
            'answered_at' => $canReadBody ? $request->answered_at?->toIso8601String() : null,
            'closed_at' => $canReadBody ? $request->closed_at?->toIso8601String() : null,
            'escalated_at' => $canReadBody ? $request->escalated_at?->toIso8601String() : null,
            'process_notes' => $canProcess
                ? $request->process_notes
                : null,
            'published_to_group' => $request->published_to_group,
            'published_to_group_at' => $request->published_to_group_at?->toIso8601String(),
            'activities' => $canReadBody && $request->relationLoaded('activities')
                ? $request->activities->map(
                    fn (PrayerRequestActivity $activity) => $this->formatActivity($activity, $canProcess)
                )->values()->all()
                : [],
            'confidentiality_events' => ($isOwner || $this->authorization->allows($actor, 'prayer.requests.read.pastor'))
                && $request->relationLoaded('confidentialityEvents')
                ? $request->confidentialityEvents->map(fn (PrayerRequestConfidentialityEvent $event) => [
                    'id' => $event->id,
                    'from_confidentiality' => $event->from_confidentiality,
                    'to_confidentiality' => $event->to_confidentiality,
                    'change_type' => $event->change_type,
                    'reason' => $event->reason,
                    'effective_at' => $event->effective_at?->toIso8601String(),
                    'propagation_completed_at' => $event->propagation_completed_at?->toIso8601String(),
                    'created_at' => $event->created_at?->toIso8601String(),
                ])->values()->all()
                : [],
            'content_omitted' => ! $canReadBody,
            'can_process' => $canProcess,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateSubmitPayload(array $payload): array
    {
        return validator($payload, [
            'branch_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'requester_member_id' => ['nullable', 'integer', 'exists:members,id'],
            'assisted' => ['nullable', 'boolean'],
            'category' => ['required', 'string', 'in:' . implode(',', array_keys(config('prayer_requests.categories', [])))],
            'priority' => ['required', 'string', 'in:' . implode(',', config('prayer_requests.priorities', []))],
            'request_body' => ['required', 'string', 'min:5', 'max:5000'],
            'confidentiality' => ['required', 'string', 'in:' . implode(',', array_keys(config('prayer_requests.confidentiality_scopes', [])))],
            'church_group_id' => ['nullable', 'integer', 'exists:church_groups,id'],
            'consent_prayer_processing' => ['required', 'boolean'],
            'consent_sharing' => ['nullable', 'boolean'],
        ])->validate();
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function resolveRequester(User $actor, array $validated, bool $assisted): Member
    {
        if ($assisted || ! empty($validated['requester_member_id'])) {
            if (! $this->authorization->allows($actor, 'prayer.requests.submit')) {
                throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
            }

            $member = Member::query()->findOrFail((int) $validated['requester_member_id']);
            $this->assertMemberInScope($actor, $member);

            return $member;
        }

        $member = Member::query()->where('user_id', $actor->id)->first();
        if ($member === null) {
            throw ValidationException::withMessages([
                'requester_member_id' => ['Your account is not linked to a member profile.'],
            ]);
        }

        return $member;
    }

    private function assertGroupAllowed(Member $requester, int $groupId, int $branchId): void
    {
        $group = ChurchGroup::query()->findOrFail($groupId);
        if ((int) $group->branch_id !== $branchId) {
            throw ValidationException::withMessages([
                'church_group_id' => ['Group must belong to the same branch as the prayer request.'],
            ]);
        }

        $isMember = ChurchGroupMembership::query()
            ->where('church_group_id', $groupId)
            ->where('member_id', $requester->id)
            ->where('status', ChurchGroupMembership::STATUS_ACTIVE)
            ->exists();

        if (! $isMember) {
            throw ValidationException::withMessages([
                'church_group_id' => ['Requester must be an active member of the selected group.'],
            ]);
        }
    }

    private function scopeRank(string $scope): int
    {
        return (int) (config('prayer_requests.confidentiality_scopes.' . $scope . '.rank') ?? 0);
    }

    private function canDiscover(User $actor, PrayerRequest $request): bool
    {
        if ($request->isWithdrawn()) {
            return $this->isOwner($actor, $request)
                || $this->authorization->allows($actor, 'prayer.requests.read.pastor');
        }

        if ($this->isOwner($actor, $request)) {
            return true;
        }

        if (! $this->isInBranchScope($actor, (int) $request->branch_id)
            && $request->confidentiality !== PrayerRequest::SCOPE_PUBLIC_TESTIMONY) {
            return false;
        }

        return match ($request->confidentiality) {
            PrayerRequest::SCOPE_PRIVATE => false,
            PrayerRequest::SCOPE_PASTOR_ONLY => $this->authorization->allows($actor, 'prayer.requests.read.pastor'),
            PrayerRequest::SCOPE_PRAYER_TEAM => $this->authorization->allows($actor, 'prayer.requests.read.prayer_team')
                || $this->authorization->allows($actor, 'prayer.requests.read.pastor'),
            PrayerRequest::SCOPE_GROUP => $this->isGroupAudience($actor, $request)
                || $this->authorization->allows($actor, 'prayer.requests.read.pastor'),
            PrayerRequest::SCOPE_PUBLIC_TESTIMONY => $this->authorization->allows($actor, 'prayer.requests.read.public')
                || $this->authorization->allows($actor, 'prayer.requests.read.prayer_team')
                || $this->authorization->allows($actor, 'prayer.requests.read.pastor')
                || $this->authorization->allows($actor, 'prayer.requests.submit.self'),
            default => false,
        };
    }

    private function applyAudienceFilter(Builder $query, User $actor): void
    {
        $member = Member::query()->where('user_id', $actor->id)->first();
        $groupIds = $member
            ? ChurchGroupMembership::query()
                ->where('member_id', $member->id)
                ->where('status', ChurchGroupMembership::STATUS_ACTIVE)
                ->pluck('church_group_id')
                ->all()
            : [];

        $query->where(function (Builder $outer) use ($actor, $member, $groupIds): void {
            if ($member !== null) {
                $outer->orWhere('requester_member_id', $member->id);
            }

            if ($this->authorization->allows($actor, 'prayer.requests.read.pastor')) {
                $outer->orWhereIn('confidentiality', [
                    PrayerRequest::SCOPE_PASTOR_ONLY,
                    PrayerRequest::SCOPE_PRAYER_TEAM,
                    PrayerRequest::SCOPE_GROUP,
                    PrayerRequest::SCOPE_PUBLIC_TESTIMONY,
                ]);
            }

            if ($this->authorization->allows($actor, 'prayer.requests.read.prayer_team')) {
                $outer->orWhereIn('confidentiality', [
                    PrayerRequest::SCOPE_PRAYER_TEAM,
                    PrayerRequest::SCOPE_PUBLIC_TESTIMONY,
                ]);
            }

            if ($groupIds !== []) {
                $outer->orWhere(function (Builder $group) use ($groupIds): void {
                    $group->where('confidentiality', PrayerRequest::SCOPE_GROUP)
                        ->whereIn('church_group_id', $groupIds);
                });
            }

            if ($this->authorization->allows($actor, 'prayer.requests.read.public')
                || $this->authorization->allows($actor, 'prayer.requests.submit.self')) {
                $outer->orWhere('confidentiality', PrayerRequest::SCOPE_PUBLIC_TESTIMONY);
            }
        });
    }

    private function isOwner(User $actor, PrayerRequest $request): bool
    {
        if ((int) $request->requester_user_id === (int) $actor->id) {
            return true;
        }

        $member = Member::query()->where('user_id', $actor->id)->first();

        return $member !== null && (int) $member->id === (int) $request->requester_member_id;
    }

    private function isGroupAudience(User $actor, PrayerRequest $request): bool
    {
        if ($request->church_group_id === null) {
            return false;
        }

        $member = Member::query()->where('user_id', $actor->id)->first();
        if ($member === null) {
            return false;
        }

        return ChurchGroupMembership::query()
            ->where('church_group_id', $request->church_group_id)
            ->where('member_id', $member->id)
            ->where('status', ChurchGroupMembership::STATUS_ACTIVE)
            ->exists();
    }

    private function assertCanManageOwnOrAssist(User $actor, PrayerRequest $request): void
    {
        if ($this->isOwner($actor, $request)) {
            return;
        }

        if ($this->authorization->allows($actor, 'prayer.requests.submit')
            && $this->isInBranchScope($actor, (int) $request->branch_id)) {
            return;
        }

        throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
    }

    private function assertCanProcess(User $actor, PrayerRequest $request, string $context): void
    {
        $requiresEscalate = $context === 'escalate';
        $allowed = $requiresEscalate
            ? (
                $this->authorization->allows($actor, 'prayer.requests.escalate')
                || $this->authorization->allows($actor, 'prayer.requests.process')
            )
            : $this->authorization->allows($actor, 'prayer.requests.process');

        if (! $allowed) {
            $this->auditAccessDenied($actor, $request, $context . '_permission');

            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }

        if ($request->isWithdrawn() || $request->status === PrayerRequest::STATUS_WITHDRAWN) {
            throw new PrayerRequestException('This prayer request cannot be processed in its current status.', 'invalid_status', 422);
        }

        if ($request->status === PrayerRequest::STATUS_CLOSED) {
            throw new PrayerRequestException('Closed prayer requests cannot be processed.', 'invalid_status', 422);
        }

        $openStatuses = config('prayer_requests.open_statuses', []);
        if (in_array($context, ['assign', 'acknowledge', 'update', 'escalate'], true)
            && ! in_array($request->status, $openStatuses, true)) {
            throw new PrayerRequestException('This prayer request is not open for processing.', 'invalid_status', 422);
        }

        if ($context === 'answer' && ! in_array($request->status, $openStatuses, true)) {
            throw new PrayerRequestException('Only open prayer requests can be marked answered.', 'invalid_status', 422);
        }

        if ($context === 'close' && ! in_array($request->status, array_merge($openStatuses, [PrayerRequest::STATUS_ANSWERED]), true)) {
            throw new PrayerRequestException('This prayer request cannot be closed in its current status.', 'invalid_status', 422);
        }

        if ($request->confidentiality === PrayerRequest::SCOPE_PRIVATE) {
            $this->auditAccessDenied($actor, $request, $context . '_private');

            throw new PrayerRequestException(
                'Private prayer requests cannot be assigned or processed by the prayer team.',
                'process_denied',
                422,
            );
        }

        if (! $request->consent_prayer_processing) {
            $this->auditAccessDenied($actor, $request, $context . '_no_processing_consent');

            throw new PrayerRequestException(
                'This prayer request is not consented for prayer processing.',
                'process_denied',
                422,
            );
        }

        if (in_array($context, ['assign', 'publish'], true) && ! $request->consent_sharing) {
            $this->auditAccessDenied($actor, $request, $context . '_no_sharing_consent');

            throw new PrayerRequestException(
                'This prayer request is not consented for sharing.',
                'process_denied',
                422,
            );
        }

        if (! $this->isInBranchScope($actor, (int) $request->branch_id)) {
            $this->auditAccessDenied($actor, $request, $context . '_branch');

            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }

        if (! $this->mayProcess($actor, $request)) {
            $this->auditAccessDenied($actor, $request, $context . '_scope');

            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function mayProcess(User $actor, PrayerRequest $request): bool
    {
        if ($request->isWithdrawn() || $request->confidentiality === PrayerRequest::SCOPE_PRIVATE) {
            return false;
        }

        if (! $this->authorization->allows($actor, 'prayer.requests.process')
            && ! $this->authorization->allows($actor, 'prayer.requests.escalate')) {
            return false;
        }

        if (! $this->isInBranchScope($actor, (int) $request->branch_id)) {
            return false;
        }

        $hasPastor = $this->authorization->allows($actor, 'prayer.requests.read.pastor');
        $scopes = $hasPastor
            ? config('prayer_requests.pastor_processable_scopes', [])
            : config('prayer_requests.team_processable_scopes', []);

        if (! $hasPastor && ! $this->authorization->allows($actor, 'prayer.requests.read.prayer_team')) {
            return false;
        }

        return in_array($request->confidentiality, $scopes, true);
    }

    private function assertEligibleAssignee(User $assignee, PrayerRequest $request): void
    {
        if (! $this->authorization->allows($assignee, 'prayer.requests.process')
            && ! $this->authorization->allows($assignee, 'prayer.requests.read.prayer_team')
            && ! $this->authorization->allows($assignee, 'prayer.requests.read.pastor')) {
            throw ValidationException::withMessages([
                'assignee_id' => ['Assignee must have prayer processing clearance.'],
            ]);
        }

        if ($request->confidentiality === PrayerRequest::SCOPE_PASTOR_ONLY
            && ! $this->authorization->allows($assignee, 'prayer.requests.read.pastor')) {
            throw ValidationException::withMessages([
                'assignee_id' => ['Pastor-only requests require an assignee with pastor clearance.'],
            ]);
        }

        if (! $this->isInBranchScope($assignee, (int) $request->branch_id) && ! $assignee->isChurchWide()) {
            throw ValidationException::withMessages([
                'assignee_id' => ['Assignee is outside the request branch scope.'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function recordActivity(
        User $actor,
        PrayerRequest $request,
        string $type,
        ?string $statusAfter,
        ?string $notes = null,
        ?string $restrictedNotes = null,
        ?int $fromOfficerId = null,
        ?int $toOfficerId = null,
        ?array $metadata = null,
    ): PrayerRequestActivity {
        return PrayerRequestActivity::create([
            'prayer_request_id' => $request->id,
            'activity_type' => $type,
            'status_after' => $statusAfter,
            'notes' => $notes,
            'restricted_notes' => $restrictedNotes,
            'from_officer_id' => $fromOfficerId,
            'to_officer_id' => $toOfficerId,
            'actor_id' => $actor->id,
            'metadata' => $metadata,
            'recorded_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function auditProcess(
        User $actor,
        string $action,
        PrayerRequest $request,
        array $after = [],
        string $category = AuditEvent::CATEGORY_BUSINESS,
    ): void {
        $this->audit->record(
            actor: $actor,
            action: $action,
            category: $category,
            module: 'prayer',
            branchId: $request->branch_id,
            subjectType: PrayerRequest::class,
            subjectId: $request->id,
            after: array_merge([
                'reference' => $request->reference,
                'status' => $request->status,
                'assigned_officer_id' => $request->assigned_officer_id,
                // Never log request body or process notes content.
            ], $after),
        );
    }

    private function notifyUser(User $user, string $type, string $message, PrayerRequest $request): void
    {
        $member = Member::query()->where('user_id', $user->id)->first();
        if ($member === null) {
            return;
        }

        MemberNotification::create([
            'member_id' => $member->id,
            'user_id' => $user->id,
            'type' => $type,
            'message' => $message,
            'metadata' => [
                'prayer_request_id' => $request->id,
                'reference' => $request->reference,
                'priority' => $request->priority,
                // Intentionally omit request body and requester identity.
            ],
        ]);
    }

    private function notifyRequesterMinimal(PrayerRequest $request, string $type, string $message): void
    {
        if ($request->requester_member_id === null) {
            return;
        }

        MemberNotification::create([
            'member_id' => $request->requester_member_id,
            'user_id' => $request->requester_user_id,
            'type' => $type,
            'message' => $message,
            'metadata' => [
                'prayer_request_id' => $request->id,
                'reference' => $request->reference,
                'status' => $request->status,
                // Intentionally omit request body.
            ],
        ]);
    }

    private function generateReference(): string
    {
        do {
            $reference = 'PRY-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
        } while (PrayerRequest::query()->where('reference', $reference)->exists());

        return $reference;
    }

    private function assertCan(User $actor, string $action): void
    {
        if (! $this->authorization->allows($actor, $action)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function isInBranchScope(User $actor, int $branchId): bool
    {
        if ($actor->isChurchWide()) {
            return true;
        }

        try {
            BranchScope::for($actor)->assertIncludes($branchId);

            return true;
        } catch (BranchScopeException) {
            return false;
        }
    }

    private function assertBranchWritable(User $actor, int $branchId): void
    {
        if (! $this->isInBranchScope($actor, $branchId)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function assertMemberInScope(User $actor, Member $member): void
    {
        if (! $this->isInBranchScope($actor, (int) $member->branch_id)) {
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
            $query->where(function (Builder $inner) use ($scope, $actor): void {
                $inner->whereIn('branch_id', $scope->subtreeIds((int) $scope->branchId()))
                    ->orWhere(function (Builder $public) use ($actor): void {
                        // Public testimony may still be limited to church-wide readers with permission;
                        // branch-scoped users only see public items in scope unless they have submit.self globally.
                        $public->where('confidentiality', PrayerRequest::SCOPE_PUBLIC_TESTIMONY);
                    });
            });
        } catch (BranchScopeException) {
            $query->whereRaw('1 = 0');
        }
    }

    private function auditAccessDenied(User $actor, PrayerRequest $request, string $context): void
    {
        $this->audit->record(
            actor: $actor,
            action: 'prayer_request.access_denied',
            category: AuditEvent::CATEGORY_SECURITY,
            module: 'prayer',
            branchId: $request->branch_id,
            subjectType: PrayerRequest::class,
            subjectId: $request->id,
            after: [
                'reference' => $request->reference,
                'context' => $context,
                'confidentiality' => $request->confidentiality,
            ],
        );
    }
}
