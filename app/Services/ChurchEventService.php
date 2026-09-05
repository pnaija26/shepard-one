<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\ChurchEvent;
use App\Models\ChurchEventChange;
use App\Models\ChurchEventChangeEvent;
use App\Models\ChurchEventCloseSnapshot;
use App\Models\ChurchEventRegistration;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Story 4.2: plan and operate church events.
 */
class ChurchEventService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
    ) {
    }

    /**
     * @return Collection<int, ChurchEvent>
     */
    public function listEvents(User $actor, array $filters = []): Collection
    {
        $this->assertCan($actor, 'events.read');

        $query = ChurchEvent::query()
            ->with('branch:id,name')
            ->orderBy('event_date')
            ->orderBy('start_time');

        if (! empty($filters['branch_id'])) {
            $query->where('branch_id', $filters['branch_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $this->applyBranchScope($query, $actor);

        return $query->limit(200)->get();
    }

    public function showEvent(User $actor, ChurchEvent $event): ChurchEvent
    {
        $this->assertCan($actor, 'events.read');
        $this->assertEventInScope($actor, $event);

        return $event->load([
            'branch:id,name',
            'changes' => fn ($q) => $q->orderByDesc('created_at')->limit(20),
            'closeSnapshot',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createEvent(User $actor, array $payload): ChurchEvent
    {
        $this->assertCan($actor, 'events.manage');

        $validated = $this->validatePayload($payload);
        $this->assertBranchWritable($actor, (int) $validated['branch_id']);
        $this->assertScheduleValid($validated);

        return DB::transaction(function () use ($actor, $validated): ChurchEvent {
            $event = ChurchEvent::create([
                ...$this->mapAttributes($validated),
                'status' => ChurchEvent::STATUS_DRAFT,
                'registration_availability' => ChurchEvent::REGISTRATION_NA,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->recordChange($actor, $event, 'created', null, $this->snapshot($event, $actor), 'Event draft created');
            $this->audit($actor, 'church_event.created', $event);

            return $event->fresh(['branch:id,name']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateEvent(User $actor, ChurchEvent $event, array $payload): ChurchEvent
    {
        $this->assertCan($actor, 'events.manage');
        $this->assertEventInScope($actor, $event);
        $this->assertEditable($event);

        $validated = $this->validatePayload($payload);
        $this->assertBranchWritable($actor, (int) $validated['branch_id']);
        $this->assertScheduleValid($validated);

        return DB::transaction(function () use ($actor, $event, $validated): ChurchEvent {
            $before = $this->snapshot($event, $actor);

            $event->update([
                ...$this->mapAttributes($validated),
                'updated_by' => $actor->id,
            ]);

            $event = $event->fresh();
            $this->recordChange($actor, $event, 'updated', $before, $this->snapshot($event, $actor), 'Event updated');

            if (in_array($event->status, [ChurchEvent::STATUS_PUBLISHED, ChurchEvent::STATUS_POSTPONED], true)) {
                $this->emitChangeEvent($event, 'updated', [
                    'event_id' => $event->id,
                    'status' => $event->status,
                    'registration_availability' => $event->registration_availability,
                ]);
            }

            $this->audit($actor, 'church_event.updated', $event, $before);

            return $event->load(['branch:id,name', 'changes']);
        });
    }

    public function publishEvent(User $actor, ChurchEvent $event): ChurchEvent
    {
        $this->assertCan($actor, 'events.manage');
        $this->assertEventInScope($actor, $event);
        $this->assertEditable($event);

        if ($event->status === ChurchEvent::STATUS_PUBLISHED) {
            return $event;
        }

        return DB::transaction(function () use ($actor, $event): ChurchEvent {
            $before = $this->snapshot($event, $actor);
            $registrationAvailability = $this->resolveRegistrationAvailability(
                ChurchEvent::STATUS_PUBLISHED,
                $event->registration ?? [],
            );

            $event->update([
                'status' => ChurchEvent::STATUS_PUBLISHED,
                'published_at' => now(),
                'registration_availability' => $registrationAvailability,
                'updated_by' => $actor->id,
            ]);

            $event = $event->fresh();
            $this->recordChange($actor, $event, 'published', $before, $this->snapshot($event, $actor), 'Event published');
            $this->emitChangeEvent($event, 'published', [
                'event_id' => $event->id,
                'registration_availability' => $registrationAvailability,
            ]);
            $this->audit($actor, 'church_event.published', $event, $before);

            return $event->load(['branch:id,name', 'changes']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function postponeEvent(User $actor, ChurchEvent $event, array $payload): ChurchEvent
    {
        $this->assertCan($actor, 'events.manage');
        $this->assertEventInScope($actor, $event);
        $this->assertEditable($event);

        $validated = validator($payload, [
            'event_date' => ['required', 'date', 'after_or_equal:today'],
            'reason' => ['nullable', 'string', 'max:500'],
        ])->validate();

        return DB::transaction(function () use ($actor, $event, $validated): ChurchEvent {
            $before = $this->snapshot($event, $actor);

            $event->update([
                'status' => ChurchEvent::STATUS_POSTPONED,
                'postponed_to_date' => $validated['event_date'],
                'postponed_at' => now(),
                'registration_availability' => ChurchEvent::REGISTRATION_CLOSED,
                'updated_by' => $actor->id,
            ]);

            $event = $event->fresh();
            $this->recordChange($actor, $event, 'postponed', $before, $this->snapshot($event, $actor), $validated['reason'] ?? 'Event postponed');
            $this->emitChangeEvent($event, 'postponed', [
                'event_id' => $event->id,
                'postponed_to_date' => $validated['event_date'],
                'registration_availability' => ChurchEvent::REGISTRATION_CLOSED,
            ]);
            $this->audit($actor, 'church_event.postponed', $event, $before);

            return $event->load(['branch:id,name', 'changes']);
        });
    }

    public function cancelEvent(User $actor, ChurchEvent $event, ?string $reason = null): ChurchEvent
    {
        $this->assertCan($actor, 'events.manage');
        $this->assertEventInScope($actor, $event);
        $this->assertEditable($event);

        return DB::transaction(function () use ($actor, $event, $reason): ChurchEvent {
            $before = $this->snapshot($event, $actor);

            $event->update([
                'status' => ChurchEvent::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'registration_availability' => ChurchEvent::REGISTRATION_CLOSED,
                'updated_by' => $actor->id,
            ]);

            $event = $event->fresh();
            $this->recordChange($actor, $event, 'cancelled', $before, $this->snapshot($event, $actor), $reason ?? 'Event cancelled');
            $this->emitChangeEvent($event, 'cancelled', [
                'event_id' => $event->id,
                'reason' => $reason,
                'registration_availability' => ChurchEvent::REGISTRATION_CLOSED,
            ]);
            $this->audit($actor, 'church_event.cancelled', $event, $before);

            return $event->load(['branch:id,name', 'changes']);
        });
    }

    public function completeEvent(User $actor, ChurchEvent $event): ChurchEvent
    {
        $this->assertCan($actor, 'events.manage');
        $this->assertEventInScope($actor, $event);

        if (! in_array($event->status, [ChurchEvent::STATUS_PUBLISHED, ChurchEvent::STATUS_POSTPONED], true)) {
            throw ValidationException::withMessages(['status' => ['Only published or postponed events can be completed.']]);
        }

        return DB::transaction(function () use ($actor, $event): ChurchEvent {
            $before = $this->snapshot($event, $actor);

            $event->update([
                'status' => ChurchEvent::STATUS_COMPLETED,
                'completed_at' => now(),
                'registration_availability' => ChurchEvent::REGISTRATION_CLOSED,
                'updated_by' => $actor->id,
            ]);

            $event = $event->fresh();
            $this->recordChange($actor, $event, 'completed', $before, $this->snapshot($event, $actor), 'Event marked completed');
            $this->audit($actor, 'church_event.completed', $event, $before);

            return $event->load(['branch:id,name', 'changes']);
        });
    }

    public function closeEvent(User $actor, ChurchEvent $event): ChurchEvent
    {
        $this->assertCan($actor, 'events.manage');
        $this->assertEventInScope($actor, $event);

        if ($event->status !== ChurchEvent::STATUS_COMPLETED) {
            throw ValidationException::withMessages(['status' => ['Only completed events can be closed.']]);
        }

        return DB::transaction(function () use ($actor, $event): ChurchEvent {
            $before = $this->snapshot($event, $actor);

            ChurchEventCloseSnapshot::updateOrCreate(
                ['church_event_id' => $event->id],
                [
                    'registrations_count' => $event->registrations()
                        ->where('status', ChurchEventRegistration::STATUS_CONFIRMED)
                        ->count(),
                    'attendance_count' => 0,
                    'volunteer_participation' => $event->volunteers ?? [],
                    'feedback_count' => 0,
                    'incidents_count' => 0,
                    'budget_summary' => $this->canViewBudget($actor) ? ($event->budget ?? null) : null,
                    'closed_by' => $actor->id,
                    'snapshot_at' => now(),
                ],
            );

            $event->update([
                'status' => ChurchEvent::STATUS_CLOSED,
                'closed_at' => now(),
                'registration_availability' => ChurchEvent::REGISTRATION_CLOSED,
                'updated_by' => $actor->id,
            ]);

            $event = $event->fresh();
            $this->recordChange($actor, $event, 'closed', $before, $this->snapshot($event, $actor), 'Event closed for reporting');
            $this->emitChangeEvent($event, 'closed', ['event_id' => $event->id]);
            $this->audit($actor, 'church_event.closed', $event, $before);

            return $event->load(['branch:id,name', 'changes', 'closeSnapshot']);
        });
    }

    public function formatEvent(ChurchEvent $event, ?User $actor = null): array
    {
        $includeBudget = $actor !== null && $this->canViewBudget($actor);

        return [
            'id' => $event->id,
            'branch_id' => $event->branch_id,
            'title' => $event->title,
            'description' => $event->description,
            'event_date' => $event->event_date?->toDateString(),
            'end_date' => $event->end_date?->toDateString(),
            'start_time' => $this->formatTime($event->start_time),
            'end_time' => $this->formatTime($event->end_time),
            'venue' => $event->venue,
            'capacity' => $event->capacity,
            'speakers' => $event->speakers,
            'registration' => $event->registration,
            'ticketing_policy' => $event->ticketing_policy,
            'volunteers' => $event->volunteers,
            'materials' => $event->materials,
            'budget' => $includeBudget ? $event->budget : null,
            'budget_restricted' => ! $includeBudget && $event->budget !== null,
            'reminders' => $event->reminders,
            'status' => $event->status,
            'registration_availability' => $event->registration_availability,
            'postponed_to_date' => $event->postponed_to_date?->toDateString(),
            'published_at' => $event->published_at?->toIso8601String(),
            'closed_at' => $event->closed_at?->toIso8601String(),
            'branch' => $event->relationLoaded('branch') ? $event->branch : null,
            'close_snapshot' => $event->relationLoaded('closeSnapshot') && $event->closeSnapshot !== null
                ? [
                    'registrations_count' => $event->closeSnapshot->registrations_count,
                    'attendance_count' => $event->closeSnapshot->attendance_count,
                    'volunteer_participation' => $event->closeSnapshot->volunteer_participation,
                    'feedback_count' => $event->closeSnapshot->feedback_count,
                    'incidents_count' => $event->closeSnapshot->incidents_count,
                    'budget_summary' => $includeBudget ? $event->closeSnapshot->budget_summary : null,
                    'snapshot_at' => $event->closeSnapshot->snapshot_at?->toIso8601String(),
                ]
                : null,
            'changes' => $event->relationLoaded('changes')
                ? $event->changes->map(fn (ChurchEventChange $change) => [
                    'id' => $change->id,
                    'change_type' => $change->change_type,
                    'summary' => $change->summary,
                    'created_at' => $change->created_at?->toIso8601String(),
                ])->values()->all()
                : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $registration
     */
    private function resolveRegistrationAvailability(string $status, array $registration): string
    {
        if ($status !== ChurchEvent::STATUS_PUBLISHED) {
            return ChurchEvent::REGISTRATION_CLOSED;
        }

        if (! ($registration['enabled'] ?? false)) {
            return ChurchEvent::REGISTRATION_NA;
        }

        return ChurchEvent::REGISTRATION_OPEN;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validatePayload(array $payload): array
    {
        return validator($payload, [
            'branch_id' => ['required', 'integer', 'exists:organizations,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'event_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:event_date'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'venue' => ['required', 'string', 'max:255'],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'speakers' => ['nullable', 'array'],
            'speakers.*.name' => ['required_with:speakers', 'string', 'max:120'],
            'registration' => ['nullable', 'array'],
            'registration.enabled' => ['nullable', 'boolean'],
            'registration.capacity' => ['nullable', 'integer', 'min:1'],
            'registration.waitlist_enabled' => ['nullable', 'boolean'],
            'ticketing_policy' => ['nullable', 'array'],
            'ticketing_policy.type' => ['nullable', 'string', 'in:' . implode(',', config('church_events.ticketing_types', []))],
            'ticketing_policy.price' => ['nullable', 'numeric', 'min:0'],
            'volunteers' => ['nullable', 'array'],
            'materials' => ['nullable', 'array'],
            'budget' => ['nullable', 'array'],
            'budget.estimated' => ['nullable', 'numeric', 'min:0'],
            'budget.actual' => ['nullable', 'numeric', 'min:0'],
            'reminders' => ['nullable', 'array'],
        ])->validate();
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function mapAttributes(array $validated): array
    {
        return [
            'branch_id' => $validated['branch_id'],
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'event_date' => $validated['event_date'],
            'end_date' => $validated['end_date'] ?? null,
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'] ?? null,
            'venue' => $validated['venue'],
            'capacity' => $validated['capacity'] ?? null,
            'speakers' => $validated['speakers'] ?? [],
            'registration' => $validated['registration'] ?? ['enabled' => false],
            'ticketing_policy' => $validated['ticketing_policy'] ?? ['type' => 'free'],
            'volunteers' => $validated['volunteers'] ?? [],
            'materials' => $validated['materials'] ?? [],
            'budget' => $validated['budget'] ?? null,
            'reminders' => $validated['reminders'] ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function assertScheduleValid(array $validated): void
    {
        if (! empty($validated['end_time']) && $validated['end_time'] <= $validated['start_time']) {
            throw ValidationException::withMessages(['end_time' => ['End time must be after start time.']]);
        }

        if (isset($validated['capacity'], $validated['registration']['capacity'])
            && $validated['registration']['capacity'] > $validated['capacity']) {
            throw ValidationException::withMessages(['registration.capacity' => ['Registration capacity cannot exceed event capacity.']]);
        }
    }

    private function assertEditable(ChurchEvent $event): void
    {
        if (in_array($event->status, config('church_events.protected_statuses', []), true)) {
            throw ValidationException::withMessages(['status' => ['This event is protected from modification.']]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(ChurchEvent $event, User $actor): array
    {
        return $this->formatEvent($event, $actor);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function recordChange(
        User $actor,
        ChurchEvent $event,
        string $type,
        ?array $before,
        ?array $after,
        string $summary,
    ): void {
        ChurchEventChange::create([
            'church_event_id' => $event->id,
            'change_type' => $type,
            'before_state' => $before,
            'after_state' => $after,
            'summary' => $summary,
            'changed_by' => $actor->id,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function emitChangeEvent(ChurchEvent $event, string $eventType, array $payload): void
    {
        ChurchEventChangeEvent::create([
            'church_event_id' => $event->id,
            'event_type' => $eventType,
            'payload' => $payload,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $before
     */
    private function audit(User $actor, string $action, ChurchEvent $event, ?array $before = null): void
    {
        $this->audit->record(
            actor: $actor,
            action: $action,
            category: AuditEvent::CATEGORY_BUSINESS,
            module: 'events',
            branchId: $event->branch_id,
            subjectType: ChurchEvent::class,
            subjectId: $event->id,
            before: $before,
            after: $this->snapshot($event, $actor),
        );
    }

    private function canViewBudget(User $actor): bool
    {
        return $this->authorization->allows($actor, 'events.budget.read');
    }

    private function formatTime(mixed $time): ?string
    {
        if ($time === null) {
            return null;
        }

        if ($time instanceof \DateTimeInterface) {
            return $time->format('H:i');
        }

        return substr((string) $time, 0, 5);
    }

    private function assertCan(User $actor, string $action): void
    {
        if (! $this->authorization->allows($actor, $action)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
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

    private function assertEventInScope(User $actor, ChurchEvent $event): void
    {
        if ($actor->isChurchWide()) {
            return;
        }

        try {
            BranchScope::for($actor)->assertIncludes((int) $event->branch_id);
        } catch (BranchScopeException) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    /** @param  Builder<ChurchEvent>  $query */
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
