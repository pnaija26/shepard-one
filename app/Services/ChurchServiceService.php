<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\ChurchService;
use App\Models\ChurchServiceChange;
use App\Models\ChurchServiceChangeEvent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Story 4.1: schedule and maintain church services.
 */
class ChurchServiceService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
    ) {
    }

    /**
     * @return Collection<int, ChurchService>
     */
    public function listServices(User $actor, array $filters = []): Collection
    {
        $this->assertCan($actor, 'services.read');

        $query = ChurchService::query()
            ->with('branch:id,name')
            ->orderBy('service_date')
            ->orderBy('start_time');

        if (! empty($filters['branch_id'])) {
            $query->where('branch_id', $filters['branch_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['from_date'])) {
            $query->where('service_date', '>=', $filters['from_date']);
        }

        $this->applyBranchScope($query, $actor);

        return $query->limit(200)->get();
    }

    public function showService(User $actor, ChurchService $service): ChurchService
    {
        $this->assertCan($actor, 'services.read');
        $this->assertServiceInScope($actor, $service);

        return $service->load([
            'branch:id,name',
            'changes' => fn ($q) => $q->orderByDesc('created_at')->limit(20),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createService(User $actor, array $payload): ChurchService
    {
        $this->assertCan($actor, 'services.manage');

        $validated = $this->validatePayload($payload);
        $this->assertBranchWritable($actor, (int) $validated['branch_id']);
        $this->assertScheduleValid($validated);
        $this->assertNoConflicts($validated);

        return DB::transaction(function () use ($actor, $validated): ChurchService {
            $service = ChurchService::create([
                ...$this->mapAttributes($validated),
                'status' => ChurchService::STATUS_DRAFT,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->recordChange($actor, $service, 'created', null, $this->snapshot($service), 'Service draft created');
            $this->audit($actor, 'church_service.created', $service);

            return $service->fresh(['branch:id,name']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateService(User $actor, ChurchService $service, array $payload): ChurchService
    {
        $this->assertCan($actor, 'services.manage');
        $this->assertServiceInScope($actor, $service);

        if ($service->status === ChurchService::STATUS_CANCELLED) {
            throw ValidationException::withMessages(['service' => ['Cancelled services cannot be edited.']]);
        }

        $validated = $this->validatePayload($payload, $service->id);
        $this->assertBranchWritable($actor, (int) $validated['branch_id']);
        $this->assertScheduleValid($validated);
        $this->assertNoConflicts($validated, $service->id);

        return DB::transaction(function () use ($actor, $service, $validated): ChurchService {
            $before = $this->snapshot($service);

            $service->update([
                ...$this->mapAttributes($validated),
                'updated_by' => $actor->id,
            ]);

            $service = $service->fresh();
            $this->recordChange($actor, $service, 'updated', $before, $this->snapshot($service), 'Service updated');

            if ($service->status === ChurchService::STATUS_PUBLISHED) {
                $this->emitChangeEvent($service, 'updated', [
                    'service_id' => $service->id,
                    'branch_id' => $service->branch_id,
                    'service_date' => $service->service_date?->toDateString(),
                    'start_time' => $this->formatTime($service->start_time),
                    'venue' => $service->venue,
                ]);
            }

            $this->audit($actor, 'church_service.updated', $service, $before);

            return $service->load(['branch:id,name', 'changes']);
        });
    }

    public function publishService(User $actor, ChurchService $service): ChurchService
    {
        $this->assertCan($actor, 'services.manage');
        $this->assertServiceInScope($actor, $service);

        if ($service->status === ChurchService::STATUS_PUBLISHED) {
            return $service;
        }

        $this->assertNoConflicts($this->payloadFromService($service), $service->id);
        $this->assertLeadershipValid($service);

        return DB::transaction(function () use ($actor, $service): ChurchService {
            $before = $this->snapshot($service);

            $service->update([
                'status' => ChurchService::STATUS_PUBLISHED,
                'published_at' => now(),
                'updated_by' => $actor->id,
            ]);

            $service = $service->fresh();
            $this->recordChange($actor, $service, 'published', $before, $this->snapshot($service), 'Service published');
            $this->emitChangeEvent($service, 'published', [
                'service_id' => $service->id,
                'branch_id' => $service->branch_id,
                'service_date' => $service->service_date?->toDateString(),
            ]);
            $this->audit($actor, 'church_service.published', $service, $before);

            return $service->load(['branch:id,name', 'changes']);
        });
    }

    public function cancelService(User $actor, ChurchService $service, ?string $reason = null): ChurchService
    {
        $this->assertCan($actor, 'services.manage');
        $this->assertServiceInScope($actor, $service);

        if ($service->status === ChurchService::STATUS_CANCELLED) {
            return $service;
        }

        return DB::transaction(function () use ($actor, $service, $reason): ChurchService {
            $before = $this->snapshot($service);

            $service->update([
                'status' => ChurchService::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'updated_by' => $actor->id,
            ]);

            $service = $service->fresh();
            $this->recordChange($actor, $service, 'cancelled', $before, $this->snapshot($service), $reason ?? 'Service cancelled');
            $this->emitChangeEvent($service, 'cancelled', [
                'service_id' => $service->id,
                'branch_id' => $service->branch_id,
                'reason' => $reason,
            ]);
            $this->audit($actor, 'church_service.cancelled', $service, $before);

            return $service->load(['branch:id,name', 'changes']);
        });
    }

    public function formatService(ChurchService $service): array
    {
        return [
            'id' => $service->id,
            'branch_id' => $service->branch_id,
            'service_type' => $service->service_type,
            'title' => $service->title,
            'service_date' => $service->service_date?->toDateString(),
            'start_time' => $this->formatTime($service->start_time),
            'end_time' => $this->formatTime($service->end_time),
            'venue' => $service->venue,
            'ministers' => $service->ministers,
            'teams' => $service->teams,
            'capacity' => $service->capacity,
            'registration_required' => $service->registration_required,
            'registration_capacity' => $service->registration_capacity,
            'attendance_target' => $service->attendance_target,
            'livestream' => $service->livestream,
            'status' => $service->status,
            'published_at' => $service->published_at?->toIso8601String(),
            'cancelled_at' => $service->cancelled_at?->toIso8601String(),
            'branch' => $service->relationLoaded('branch') ? $service->branch : null,
            'changes' => $service->relationLoaded('changes')
                ? $service->changes->map(fn (ChurchServiceChange $change) => [
                    'id' => $change->id,
                    'change_type' => $change->change_type,
                    'summary' => $change->summary,
                    'created_at' => $change->created_at?->toIso8601String(),
                ])->values()->all()
                : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validatePayload(array $payload, ?int $serviceId = null): array
    {
        return validator($payload, [
            'branch_id' => ['required', 'integer', 'exists:organizations,id'],
            'service_type' => ['required', 'string', 'in:' . implode(',', array_keys(config('church_services.service_types', [])))],
            'title' => ['nullable', 'string', 'max:255'],
            'service_date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'venue' => ['required', 'string', 'max:255'],
            'ministers' => ['nullable', 'array'],
            'ministers.*.name' => ['required_with:ministers', 'string', 'max:120'],
            'ministers.*.role' => ['nullable', 'string', 'max:64'],
            'ministers.*.member_id' => ['nullable', 'integer'],
            'teams' => ['nullable', 'array'],
            'teams.*.name' => ['required_with:teams', 'string', 'max:120'],
            'teams.*.team_id' => ['nullable', 'integer'],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'registration_required' => ['nullable', 'boolean'],
            'registration_capacity' => ['nullable', 'integer', 'min:1'],
            'attendance_target' => ['nullable', 'integer', 'min:0'],
            'livestream' => ['nullable', 'array'],
            'livestream.enabled' => ['nullable', 'boolean'],
            'livestream.url' => ['nullable', 'url', 'max:500'],
            'livestream.platform' => ['nullable', 'string', 'max:64'],
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
            'service_type' => $validated['service_type'],
            'title' => $validated['title'] ?? null,
            'service_date' => $validated['service_date'],
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'] ?? null,
            'venue' => $validated['venue'],
            'ministers' => $validated['ministers'] ?? [],
            'teams' => $validated['teams'] ?? [],
            'capacity' => $validated['capacity'] ?? null,
            'registration_required' => $validated['registration_required'] ?? false,
            'registration_capacity' => $validated['registration_capacity'] ?? null,
            'attendance_target' => $validated['attendance_target'] ?? null,
            'livestream' => $validated['livestream'] ?? null,
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

        if (isset($validated['capacity'], $validated['registration_capacity'])
            && $validated['registration_capacity'] > $validated['capacity']) {
            throw ValidationException::withMessages(['registration_capacity' => ['Registration capacity cannot exceed venue capacity.']]);
        }

        if (isset($validated['attendance_target'], $validated['capacity'])
            && $validated['attendance_target'] > $validated['capacity']) {
            throw ValidationException::withMessages(['attendance_target' => ['Attendance target cannot exceed capacity.']]);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function assertNoConflicts(array $validated, ?int $ignoreId = null): void
    {
        $endTime = $validated['end_time'] ?? $this->defaultEndTime($validated['start_time']);

        $candidates = ChurchService::query()
            ->where('branch_id', $validated['branch_id'])
            ->where('venue', $validated['venue'])
            ->whereDate('service_date', $validated['service_date'])
            ->whereIn('status', [ChurchService::STATUS_DRAFT, ChurchService::STATUS_PUBLISHED])
            ->when($ignoreId !== null, fn (Builder $q) => $q->where('id', '!=', $ignoreId))
            ->get();

        foreach ($candidates as $candidate) {
            $candidateEnd = $candidate->end_time
                ? $this->formatTime($candidate->end_time)
                : $this->defaultEndTime($this->formatTime($candidate->start_time));

            if ($this->timesOverlap($validated['start_time'], $endTime, $this->formatTime($candidate->start_time), $candidateEnd)) {
                throw ValidationException::withMessages([
                    'venue' => ['A service is already scheduled at this venue during the selected time.'],
                ]);
            }
        }
    }

    private function assertLeadershipValid(ChurchService $service): void
    {
        $ministers = $service->ministers ?? [];
        if ($ministers === []) {
            throw ValidationException::withMessages(['ministers' => ['At least one minister is required to publish.']]);
        }

        foreach ($ministers as $minister) {
            if (empty($minister['name'])) {
                throw ValidationException::withMessages(['ministers' => ['Each minister entry must include a name.']]);
            }
        }
    }

    private function timesOverlap(string $startA, string $endA, string $startB, string $endB): bool
    {
        return $startA < $endB && $startB < $endA;
    }

    private function defaultEndTime(string $startTime): string
    {
        return Carbon::createFromFormat('H:i', substr($startTime, 0, 5))
            ->addMinutes((int) config('church_services.default_duration_minutes', 120))
            ->format('H:i');
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(ChurchService $service): array
    {
        return $this->formatService($service);
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFromService(ChurchService $service): array
    {
        return [
            'branch_id' => $service->branch_id,
            'service_type' => $service->service_type,
            'title' => $service->title,
            'service_date' => $service->service_date?->toDateString(),
            'start_time' => $this->formatTime($service->start_time),
            'end_time' => $this->formatTime($service->end_time),
            'venue' => $service->venue,
            'ministers' => $service->ministers,
            'teams' => $service->teams,
            'capacity' => $service->capacity,
            'registration_required' => $service->registration_required,
            'registration_capacity' => $service->registration_capacity,
            'attendance_target' => $service->attendance_target,
            'livestream' => $service->livestream,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function recordChange(
        User $actor,
        ChurchService $service,
        string $type,
        ?array $before,
        ?array $after,
        string $summary,
    ): void {
        ChurchServiceChange::create([
            'church_service_id' => $service->id,
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
    private function emitChangeEvent(ChurchService $service, string $eventType, array $payload): void
    {
        ChurchServiceChangeEvent::create([
            'church_service_id' => $service->id,
            'event_type' => $eventType,
            'payload' => $payload,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $before
     */
    private function audit(User $actor, string $action, ChurchService $service, ?array $before = null): void
    {
        $this->audit->record(
            actor: $actor,
            action: $action,
            category: AuditEvent::CATEGORY_BUSINESS,
            module: 'services',
            branchId: $service->branch_id,
            subjectType: ChurchService::class,
            subjectId: $service->id,
            before: $before,
            after: $this->snapshot($service),
        );
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

    private function assertServiceInScope(User $actor, ChurchService $service): void
    {
        if ($actor->isChurchWide()) {
            return;
        }

        try {
            BranchScope::for($actor)->assertIncludes((int) $service->branch_id);
        } catch (BranchScopeException) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    /** @param  Builder<ChurchService>  $query */
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
