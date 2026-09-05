<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Member;
use App\Models\MemberNotification;
use App\Models\OperationalIncident;
use App\Models\OperationalIncidentActivity;
use App\Models\OperationalIncidentEscalation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Story 4.6: report, respond to, and close operational incidents.
 */
class OperationalIncidentService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
    ) {
    }

    /**
     * @return Collection<int, OperationalIncident>
     */
    public function listIncidents(User $actor, array $filters = []): Collection
    {
        $this->assertCan($actor, 'incidents.read');

        $query = OperationalIncident::query()
            ->with(['branch:id,name', 'owner:id,name'])
            ->orderByDesc('occurred_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['classification'])) {
            $query->where('classification', $filters['classification']);
        }

        $this->applyBranchScope($query, $actor);

        return $query->limit(200)->get();
    }

    public function showIncident(User $actor, OperationalIncident $incident): OperationalIncident
    {
        $this->assertCanView($actor, $incident);

        return $incident->load([
            'branch:id,name',
            'owner:id,name',
            'reporter:id,name',
            'reviewer:id,name',
            'activities.actor:id,name',
            'activities.owner:id,name',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function reportIncident(User $actor, array $payload): OperationalIncident
    {
        $this->assertCan($actor, 'incidents.report');

        if ($this->hasProhibitedAttachments($payload)) {
            throw ValidationException::withMessages([
                'evidence' => ['File attachments are not accepted. Submit text references only.'],
            ]);
        }

        $validated = $this->validateReportPayload($payload);
        $this->assertBranchWritable($actor, (int) $validated['branch_id']);

        $routing = config('incidents.classification_routing.' . $validated['classification'], null);
        if ($routing === null) {
            throw ValidationException::withMessages(['classification' => ['Unsupported incident classification.']]);
        }

        $owner = $this->resolveOwner((int) $validated['branch_id'], $validated['classification'], $validated['priority']);
        if ($owner === null) {
            throw ValidationException::withMessages(['branch_id' => ['No incident responder is configured for this branch.']]);
        }

        $isRestricted = in_array($validated['classification'], config('incidents.restricted_classifications', []), true);

        return DB::transaction(function () use ($actor, $validated, $routing, $owner, $isRestricted): OperationalIncident {
            $incident = OperationalIncident::create([
                'reference' => $this->generateReference(),
                'branch_id' => $validated['branch_id'],
                'classification' => $validated['classification'],
                'priority' => $validated['priority'],
                'status' => OperationalIncident::STATUS_OPEN,
                'occurred_at' => Carbon::parse($validated['occurred_at']),
                'location' => $validated['location'],
                'description' => $validated['description'],
                'sensitive_details' => $validated['sensitive_details'] ?? null,
                'evidence' => $validated['evidence'] ?? [],
                'assigned_team' => $routing['team'],
                'owner_id' => $owner->id,
                'is_restricted' => $isRestricted,
                'requires_review' => in_array($validated['priority'], ['high', 'critical'], true),
                'reported_by' => $actor->id,
            ]);

            OperationalIncidentActivity::create([
                'operational_incident_id' => $incident->id,
                'activity_type' => 'investigation',
                'notes' => 'Incident reported and assigned.',
                'owner_id' => $owner->id,
                'actor_id' => $actor->id,
                'created_at' => now(),
            ]);

            $this->audit->record(
                actor: $actor,
                action: 'incident.reported',
                category: AuditEvent::CATEGORY_BUSINESS,
                module: 'incidents',
                branchId: $incident->branch_id,
                subjectType: OperationalIncident::class,
                subjectId: $incident->id,
                after: [
                    'reference' => $incident->reference,
                    'classification' => $incident->classification,
                    'priority' => $incident->priority,
                    'owner_id' => $incident->owner_id,
                ],
            );

            $this->notifyOwner($incident, 'incident.assigned');

            if ($incident->priority === 'critical') {
                $this->processCriticalEscalation($actor, $incident);
            }

            return $incident->fresh(['branch:id,name', 'owner:id,name']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function recordActivity(User $actor, OperationalIncident $incident, array $payload): OperationalIncidentActivity
    {
        $this->assertCanRespond($actor, $incident);

        $validated = validator($payload, [
            'activity_type' => ['required', 'string', 'in:' . implode(',', config('incidents.activity_types', []))],
            'notes' => ['nullable', 'string', 'max:2000'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'closure_outcome' => ['nullable', 'string', 'max:2000'],
            'follow_up_actions' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        if (in_array($validated['activity_type'], ['review_approved', 'review_returned'], true)) {
            throw ValidationException::withMessages(['activity_type' => ['Use the review endpoint for closure review.']]);
        }

        return DB::transaction(function () use ($actor, $incident, $validated): OperationalIncidentActivity {
            $before = ['status' => $incident->status, 'owner_id' => $incident->owner_id];
            $status = $this->resolveStatusForActivity($validated['activity_type'], $incident->status);
            $ownerId = $validated['owner_id'] ?? $incident->owner_id;

            $updates = [
                'status' => $status,
                'owner_id' => $ownerId,
            ];

            if ($validated['activity_type'] === 'resolution') {
                $updates['status'] = $incident->requires_review
                    ? OperationalIncident::STATUS_PENDING_REVIEW
                    : OperationalIncident::STATUS_RESOLVED;
                $updates['resolved_at'] = now();
                $updates['closure_outcome'] = $validated['closure_outcome'] ?? $incident->closure_outcome;
                $updates['follow_up_actions'] = $validated['follow_up_actions'] ?? $incident->follow_up_actions;
            }

            if ($validated['activity_type'] === 'escalation') {
                $updates['status'] = OperationalIncident::STATUS_ESCALATED;
            }

            $incident->update($updates);

            $activity = OperationalIncidentActivity::create([
                'operational_incident_id' => $incident->id,
                'activity_type' => $validated['activity_type'],
                'notes' => $validated['notes'] ?? null,
                'owner_id' => $ownerId,
                'actor_id' => $actor->id,
                'created_at' => now(),
            ]);

            $this->audit->record(
                actor: $actor,
                action: 'incident.activity_recorded',
                category: AuditEvent::CATEGORY_BUSINESS,
                module: 'incidents',
                branchId: $incident->branch_id,
                subjectType: OperationalIncident::class,
                subjectId: $incident->id,
                before: $before,
                after: [
                    'status' => $incident->status,
                    'owner_id' => $incident->owner_id,
                    'activity_type' => $activity->activity_type,
                ],
            );

            return $activity->load(['actor:id,name', 'owner:id,name']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function reviewClosure(User $actor, OperationalIncident $incident, array $payload): OperationalIncident
    {
        $this->assertCan($actor, 'incidents.review');
        $this->assertIncidentInScope($actor, $incident);

        if ($incident->status !== OperationalIncident::STATUS_PENDING_REVIEW) {
            throw ValidationException::withMessages(['status' => ['Only incidents pending review can be reviewed.']]);
        }

        $validated = validator($payload, [
            'decision' => ['required', 'string', 'in:approve,return'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'owner_id' => ['required_if:decision,return', 'integer', 'exists:users,id'],
            'follow_up_actions' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        return DB::transaction(function () use ($actor, $incident, $validated): OperationalIncident {
            $before = ['status' => $incident->status, 'owner_id' => $incident->owner_id];

            if ($validated['decision'] === 'approve') {
                $incident->update([
                    'status' => OperationalIncident::STATUS_CLOSED,
                    'closed_at' => now(),
                    'reviewed_by' => $actor->id,
                    'follow_up_actions' => $validated['follow_up_actions'] ?? $incident->follow_up_actions,
                ]);

                $activityType = 'review_approved';
            } else {
                $incident->update([
                    'status' => OperationalIncident::STATUS_RETURNED,
                    'owner_id' => $validated['owner_id'],
                    'reviewed_by' => $actor->id,
                    'resolved_at' => null,
                ]);

                $activityType = 'review_returned';
            }

            OperationalIncidentActivity::create([
                'operational_incident_id' => $incident->id,
                'activity_type' => $activityType,
                'notes' => $validated['notes'] ?? null,
                'owner_id' => $incident->owner_id,
                'actor_id' => $actor->id,
                'created_at' => now(),
            ]);

            $this->incrementEventIncidentCount($incident);

            $this->audit->record(
                actor: $actor,
                action: 'incident.reviewed',
                category: AuditEvent::CATEGORY_BUSINESS,
                module: 'incidents',
                branchId: $incident->branch_id,
                subjectType: OperationalIncident::class,
                subjectId: $incident->id,
                before: $before,
                after: [
                    'status' => $incident->status,
                    'owner_id' => $incident->owner_id,
                    'decision' => $validated['decision'],
                ],
            );

            return $incident->fresh(['branch:id,name', 'owner:id,name', 'activities.actor:id,name']);
        });
    }

    /**
     * @return array{processed: int, escalated: int, skipped: int}
     */
    public function processEscalations(User $actor, ?int $branchId = null): array
    {
        $this->assertCan($actor, 'incidents.respond');

        $counts = ['processed' => 0, 'escalated' => 0, 'skipped' => 0];

        $query = OperationalIncident::query()
            ->whereIn('status', config('incidents.open_statuses', []));

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        $this->applyBranchScope($query, $actor);

        foreach ($query->cursor() as $incident) {
            $counts['processed']++;
            $trigger = $this->resolveEscalationTrigger($incident);

            if ($trigger === null) {
                $counts['skipped']++;

                continue;
            }

            if (OperationalIncidentEscalation::query()
                ->where('operational_incident_id', $incident->id)
                ->where('trigger_type', $trigger)
                ->exists()) {
                $counts['skipped']++;

                continue;
            }

            $target = $this->resolveEscalationTarget($incident);
            if ($target === null || $target->id === $incident->owner_id) {
                $counts['skipped']++;

                continue;
            }

            $this->escalateIncident($actor, $incident, $target, $trigger);
            $counts['escalated']++;
        }

        return $counts;
    }

    public function formatIncident(OperationalIncident $incident, bool $includeSensitive = false): array
    {
        $routing = config('incidents.classification_routing.' . $incident->classification, []);
        $canSeeSensitive = $includeSensitive || ! $incident->is_restricted;

        return [
            'id' => $incident->id,
            'reference' => $incident->reference,
            'branch_id' => $incident->branch_id,
            'classification' => $incident->classification,
            'classification_label' => config('incidents.classifications.' . $incident->classification, $incident->classification),
            'priority' => $incident->priority,
            'status' => $incident->status,
            'occurred_at' => $incident->occurred_at?->toIso8601String(),
            'location' => $incident->location,
            'description' => $canSeeSensitive ? $incident->description : 'Restricted incident details.',
            'sensitive_details' => $canSeeSensitive ? $incident->sensitive_details : null,
            'evidence' => $canSeeSensitive ? ($incident->evidence ?? []) : [],
            'assigned_team' => $incident->assigned_team,
            'assigned_team_label' => $routing['label'] ?? $incident->assigned_team,
            'owner' => $incident->owner ? ['id' => $incident->owner->id, 'name' => $incident->owner->name] : null,
            'is_restricted' => $incident->is_restricted,
            'requires_review' => $incident->requires_review,
            'closure_outcome' => $incident->closure_outcome,
            'follow_up_actions' => $incident->follow_up_actions,
            'resolved_at' => $incident->resolved_at?->toIso8601String(),
            'closed_at' => $incident->closed_at?->toIso8601String(),
            'activities' => $incident->relationLoaded('activities')
                ? $incident->activities->map(fn (OperationalIncidentActivity $activity) => [
                    'id' => $activity->id,
                    'activity_type' => $activity->activity_type,
                    'notes' => $canSeeSensitive ? $activity->notes : null,
                    'owner' => $activity->owner?->name,
                    'actor' => $activity->actor?->name,
                    'created_at' => $activity->created_at?->toIso8601String(),
                ])->values()->all()
                : [],
        ];
    }

    public function resolveDefaultOwner(int $branchId, string $classification, string $priority): ?User
    {
        return $this->resolveOwner($branchId, $classification, $priority);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateReportPayload(array $payload): array
    {
        $maxEvidence = (int) config('incidents.evidence.max_items', 5);
        $maxRef = (int) config('incidents.evidence.max_reference_length', 500);

        return validator($payload, [
            'branch_id' => ['required', 'integer', 'exists:organizations,id'],
            'classification' => ['required', 'string', 'in:' . implode(',', array_keys(config('incidents.classifications', [])))],
            'priority' => ['required', 'string', 'in:' . implode(',', config('incidents.priorities', []))],
            'occurred_at' => ['required', 'date'],
            'location' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'min:10', 'max:5000'],
            'sensitive_details' => ['nullable', 'string', 'max:5000'],
            'evidence' => ['nullable', 'array', 'max:' . $maxEvidence],
            'evidence.*' => ['string', 'max:' . $maxRef],
        ])->validate();
    }

    private function resolveOwner(int $branchId, string $classification, string $priority): ?User
    {
        $query = User::query()
            ->whereHas('assignedRoles.permissions', fn (Builder $q) => $q->where('action', 'incidents.respond'));

        if (in_array($priority, ['critical', 'high'], true)) {
            $lead = (clone $query)->where('branch_id', $branchId)->first()
                ?? (clone $query)->whereNull('branch_id')->first();

            if ($lead !== null) {
                return $lead;
            }
        }

        return (clone $query)
            ->where(function (Builder $q) use ($branchId): void {
                $q->where('branch_id', $branchId)->orWhereNull('branch_id');
            })
            ->first();
    }

    private function resolveEscalationTrigger(OperationalIncident $incident): ?string
    {
        if ($incident->priority === 'critical' && $incident->status === OperationalIncident::STATUS_OPEN) {
            return 'critical';
        }

        $hours = config('incidents.priority_escalation_hours.' . $incident->priority);
        if ($hours === null) {
            return null;
        }

        $start = $incident->occurred_at ?? $incident->created_at;
        $deadline = $start?->copy()->addHours((int) $hours);
        if ($deadline !== null && now()->greaterThanOrEqualTo($deadline)) {
            return 'overdue';
        }

        return null;
    }

    private function resolveEscalationTarget(OperationalIncident $incident): ?User
    {
        return User::query()
            ->where('id', '!=', $incident->owner_id)
            ->where(function (Builder $query) use ($incident): void {
                $query->where('branch_id', $incident->branch_id)
                    ->orWhereNull('branch_id');
            })
            ->whereHas('assignedRoles.permissions', fn (Builder $q) => $q->where('action', 'incidents.review'))
            ->orderByRaw('branch_id is null')
            ->first();
    }

    private function escalateIncident(User $actor, OperationalIncident $incident, User $target, string $trigger): void
    {
        DB::transaction(function () use ($actor, $incident, $target, $trigger): void {
            $fromOwnerId = $incident->owner_id;

            OperationalIncidentEscalation::create([
                'operational_incident_id' => $incident->id,
                'trigger_type' => $trigger,
                'from_owner_id' => $fromOwnerId,
                'to_owner_id' => $target->id,
                'branch_id' => $incident->branch_id,
                'escalated_by' => $actor->id,
                'created_at' => now(),
            ]);

            $incident->update([
                'owner_id' => $target->id,
                'status' => OperationalIncident::STATUS_ESCALATED,
            ]);

            OperationalIncidentActivity::create([
                'operational_incident_id' => $incident->id,
                'activity_type' => 'escalation',
                'notes' => 'Escalated due to ' . str_replace('_', ' ', $trigger) . '.',
                'owner_id' => $target->id,
                'actor_id' => $actor->id,
                'created_at' => now(),
            ]);

            $this->audit->record(
                actor: $actor,
                action: 'incident.escalated',
                category: AuditEvent::CATEGORY_BUSINESS,
                module: 'incidents',
                branchId: $incident->branch_id,
                subjectType: OperationalIncident::class,
                subjectId: $incident->id,
                before: ['owner_id' => $fromOwnerId],
                after: ['owner_id' => $target->id, 'trigger' => $trigger],
            );

            $this->notifyOwner($incident->fresh(), 'incident.escalated');
        });
    }

    private function processCriticalEscalation(User $actor, OperationalIncident $incident): void
    {
        if (OperationalIncidentEscalation::query()
            ->where('operational_incident_id', $incident->id)
            ->where('trigger_type', 'critical')
            ->exists()) {
            return;
        }

        $target = $this->resolveEscalationTarget($incident);
        if ($target === null || $target->id === $incident->owner_id) {
            return;
        }

        $this->escalateIncident($actor, $incident->fresh(), $target, 'critical');
    }

    private function resolveStatusForActivity(string $activityType, string $currentStatus): string
    {
        return match ($activityType) {
            'investigation' => OperationalIncident::STATUS_INVESTIGATING,
            'action' => OperationalIncident::STATUS_INVESTIGATING,
            'reassignment' => OperationalIncident::STATUS_INVESTIGATING,
            'escalation' => OperationalIncident::STATUS_ESCALATED,
            'resolution' => OperationalIncident::STATUS_RESOLVED,
            default => $currentStatus,
        };
    }

    private function generateReference(): string
    {
        do {
            $reference = 'INC-' . strtoupper(Str::random(8));
        } while (OperationalIncident::query()->where('reference', $reference)->exists());

        return $reference;
    }

    private function incrementEventIncidentCount(OperationalIncident $incident): void
    {
        if ($incident->status !== OperationalIncident::STATUS_CLOSED) {
            return;
        }

        // Reserved for future event linkage; no-op until incidents link to gatherings.
    }

    private function notifyOwner(OperationalIncident $incident, string $type): void
    {
        $owner = $incident->owner ?? User::query()->find($incident->owner_id);
        if ($owner === null) {
            return;
        }

        $member = Member::query()->where('user_id', $owner->id)->first();
        if ($member === null) {
            return;
        }

        MemberNotification::create([
            'member_id' => $member->id,
            'user_id' => $owner->id,
            'type' => $type,
            'message' => 'Operational incident ' . $incident->reference . ' requires your attention.',
            'metadata' => [
                'incident_id' => $incident->id,
                'priority' => $incident->priority,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hasProhibitedAttachments(array $payload): bool
    {
        return isset($payload['attachment'])
            || isset($payload['attachments'])
            || isset($payload['file'])
            || isset($payload['files']);
    }

    private function assertCan(User $actor, string $action): void
    {
        if (! $this->authorization->allows($actor, $action)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function assertCanView(User $actor, OperationalIncident $incident): void
    {
        if ($this->authorization->allows($actor, 'incidents.read')) {
            $this->assertIncidentInScope($actor, $incident);

            return;
        }

        if ($incident->reported_by === $actor->id && $this->authorization->allows($actor, 'incidents.report')) {
            return;
        }

        throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
    }

    private function assertCanRespond(User $actor, OperationalIncident $incident): void
    {
        $this->assertCan($actor, 'incidents.respond');
        $this->assertIncidentInScope($actor, $incident);
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

    private function assertIncidentInScope(User $actor, OperationalIncident $incident): void
    {
        if ($actor->isChurchWide()) {
            return;
        }

        try {
            BranchScope::for($actor)->assertIncludes((int) $incident->branch_id);
        } catch (BranchScopeException) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    /** @param  Builder<OperationalIncident>  $query */
    private function applyBranchScope(Builder $query, User $actor): void
    {
        if ($actor->isChurchWide()) {
            return;
        }

        try {
            $scope = BranchScope::for($actor);
            $query->whereIn('branch_id', $scope->subtreeIds((int) $scope->branchId()));
        } catch (BranchScopeException) {
            $query->whereRaw('1 = 0');
        }
    }
}
