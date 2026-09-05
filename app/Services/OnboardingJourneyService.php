<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Member;
use App\Models\MemberNotification;
use App\Models\OnboardingEnrollment;
use App\Models\OnboardingJourney;
use App\Models\OnboardingJourneyVersion;
use App\Models\OnboardingStepRun;
use App\Models\User;
use App\Models\Visitor;
use App\Models\VisitorVisit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Story 3.2: configurable welcome and onboarding journeys.
 */
class OnboardingJourneyService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
    ) {
    }

    /**
     * @return Collection<int, OnboardingJourney>
     */
    public function listJourneys(User $actor): Collection
    {
        $this->assertCan($actor, 'onboarding.read');

        $query = OnboardingJourney::query()->with('branch:id,name')->orderBy('name');
        $this->applyBranchScope($query, $actor);

        return $query->get();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createJourney(User $actor, array $payload): OnboardingJourney
    {
        $this->assertCan($actor, 'onboarding.manage');

        $validated = $this->validateJourneyPayload($payload);

        return OnboardingJourney::create([
            'name' => $validated['name'],
            'trigger_event' => $validated['trigger_event'],
            'branch_id' => $validated['branch_id'] ?? null,
            'status' => OnboardingJourney::STATUS_DRAFT,
            'current_version' => 0,
            'created_by' => $actor->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateJourney(User $actor, OnboardingJourney $journey, array $payload): OnboardingJourney
    {
        $this->assertCan($actor, 'onboarding.manage');
        $this->assertJourneyInScope($actor, $journey);

        if ($journey->status === OnboardingJourney::STATUS_PUBLISHED) {
            throw ValidationException::withMessages([
                'journey' => ['Published journeys cannot be edited. Publish a new version instead.'],
            ]);
        }

        $validated = $this->validateJourneyPayload($payload, $journey->id);

        $journey->update([
            'name' => $validated['name'],
            'trigger_event' => $validated['trigger_event'],
            'branch_id' => $validated['branch_id'] ?? null,
        ]);

        return $journey->fresh(['branch:id,name']);
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     * @param  array<string, mixed>|null  $stopConditions
     */
    public function publishJourney(
        User $actor,
        OnboardingJourney $journey,
        array $steps,
        ?array $stopConditions = null,
    ): OnboardingJourneyVersion {
        $this->assertCan($actor, 'onboarding.manage');
        $this->assertJourneyInScope($actor, $journey);

        $validatedSteps = $this->validateSteps($steps);

        return DB::transaction(function () use ($actor, $journey, $validatedSteps, $stopConditions) {
            $version = $journey->current_version + 1;

            $journeyVersion = OnboardingJourneyVersion::create([
                'journey_id' => $journey->id,
                'version' => $version,
                'steps' => $validatedSteps,
                'stop_conditions' => $stopConditions,
                'published_by' => $actor->id,
                'published_at' => now(),
            ]);

            $journey->update([
                'status' => OnboardingJourney::STATUS_PUBLISHED,
                'current_version' => $version,
            ]);

            $this->audit->record(
                actor: $actor,
                action: 'onboarding.journey.published',
                category: AuditEvent::CATEGORY_BUSINESS,
                module: 'onboarding',
                branchId: $journey->branch_id,
                subjectType: OnboardingJourney::class,
                subjectId: $journey->id,
                metadata: ['version' => $version],
            );

            return $journeyVersion;
        });
    }

    public function handleEvent(string $event, Model $subject, ?User $actor = null): ?OnboardingEnrollment
    {
        $branchId = $this->branchIdForSubject($subject);
        if ($branchId === null) {
            return null;
        }

        $journeys = OnboardingJourney::query()
            ->where('status', OnboardingJourney::STATUS_PUBLISHED)
            ->where('trigger_event', $event)
            ->where(function (Builder $q) use ($branchId): void {
                $q->whereNull('branch_id')->orWhere('branch_id', $branchId);
            })
            ->get();

        $enrollment = null;
        foreach ($journeys as $journey) {
            $created = $this->enrollSubject($journey, $subject, $actor);
            if ($created !== null) {
                $enrollment = $created;
            }
        }

        return $enrollment;
    }

    /**
     * @return array{processed: int, completed: int, failed: int, skipped: int}
     */
    public function processDueSteps(?User $actor = null): array
    {
        if ($actor !== null) {
            $this->assertCan($actor, 'onboarding.manage');
        }

        $counts = ['processed' => 0, 'completed' => 0, 'failed' => 0, 'skipped' => 0];

        OnboardingStepRun::query()
            ->with(['enrollment.journeyVersion', 'enrollment.subject'])
            ->where('status', OnboardingStepRun::STATUS_PENDING)
            ->where('scheduled_for', '<=', now())
            ->orderBy('scheduled_for')
            ->chunkById(50, function ($runs) use (&$counts, $actor): void {
                foreach ($runs as $run) {
                    $counts['processed']++;
                    $outcome = $this->processStepRun($run, $actor);
                    $counts[$outcome]++;
                }
            });

        return $counts;
    }

    /**
     * @return Collection<int, OnboardingEnrollment>
     */
    public function listEnrollments(User $actor, array $filters = []): Collection
    {
        $this->assertCan($actor, 'onboarding.read');

        $query = OnboardingEnrollment::query()
            ->with(['journey:id,name,trigger_event', 'stepRuns'])
            ->orderByDesc('enrolled_at');

        $this->applyEnrollmentBranchScope($query, $actor);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->get();
    }

    public function showEnrollment(User $actor, OnboardingEnrollment $enrollment): OnboardingEnrollment
    {
        $this->assertCan($actor, 'onboarding.read');
        $this->assertEnrollmentInScope($actor, $enrollment);

        return $enrollment->load(['journey', 'journeyVersion', 'stepRuns', 'subject']);
    }

    public function formatJourney(OnboardingJourney $journey): array
    {
        return [
            'id' => $journey->id,
            'name' => $journey->name,
            'trigger_event' => $journey->trigger_event,
            'branch' => $journey->branch ? ['id' => $journey->branch->id, 'name' => $journey->branch->name] : null,
            'status' => $journey->status,
            'current_version' => $journey->current_version,
            'latest_version' => $journey->latestVersion() ? [
                'version' => $journey->latestVersion()->version,
                'steps' => $journey->latestVersion()->steps,
                'published_at' => $journey->latestVersion()->published_at?->toIso8601String(),
            ] : null,
        ];
    }

    public function formatEnrollment(OnboardingEnrollment $enrollment): array
    {
        $subject = $enrollment->subject;

        return [
            'id' => $enrollment->id,
            'journey' => [
                'id' => $enrollment->journey_id,
                'name' => $enrollment->journey?->name,
                'trigger_event' => $enrollment->journey?->trigger_event,
            ],
            'journey_version' => $enrollment->journey_version,
            'subject_type' => class_basename($enrollment->subject_type),
            'subject_id' => $enrollment->subject_id,
            'subject_name' => $this->subjectName($subject),
            'status' => $enrollment->status,
            'stop_reason' => $enrollment->stop_reason,
            'enrolled_at' => $enrollment->enrolled_at?->toIso8601String(),
            'steps' => $enrollment->stepRuns->map(fn (OnboardingStepRun $run) => [
                'step_key' => $run->step_key,
                'day_offset' => $run->day_offset,
                'action_type' => $run->action_type,
                'scheduled_for' => $run->scheduled_for?->toIso8601String(),
                'status' => $run->status,
                'skip_reason' => $run->skip_reason,
                'failure_reason' => $run->failure_reason,
                'executed_at' => $run->executed_at?->toIso8601String(),
            ])->values(),
        ];
    }

    private function enrollSubject(OnboardingJourney $journey, Model $subject, ?User $actor): ?OnboardingEnrollment
    {
        if (OnboardingEnrollment::query()
            ->where('journey_id', $journey->id)
            ->where('subject_type', $subject::class)
            ->where('subject_id', $subject->id)
            ->exists()
        ) {
            return null;
        }

        $version = $journey->latestVersion();
        if ($version === null) {
            return null;
        }

        return DB::transaction(function () use ($journey, $version, $subject, $actor) {
            $enrollment = OnboardingEnrollment::create([
                'journey_id' => $journey->id,
                'journey_version_id' => $version->id,
                'journey_version' => $version->version,
                'subject_type' => $subject::class,
                'subject_id' => $subject->id,
                'branch_id' => $this->branchIdForSubject($subject),
                'status' => OnboardingEnrollment::STATUS_ACTIVE,
                'enrolled_at' => now(),
            ]);

            foreach ($version->steps as $step) {
                OnboardingStepRun::create([
                    'enrollment_id' => $enrollment->id,
                    'step_key' => $step['key'],
                    'day_offset' => (int) $step['day_offset'],
                    'action_type' => $step['action_type'],
                    'scheduled_for' => now()->addDays((int) $step['day_offset']),
                    'status' => OnboardingStepRun::STATUS_PENDING,
                ]);
            }

            $this->audit->record(
                actor: $actor,
                action: 'onboarding.enrolled',
                category: AuditEvent::CATEGORY_BUSINESS,
                module: 'onboarding',
                branchId: $enrollment->branch_id,
                subjectType: OnboardingEnrollment::class,
                subjectId: $enrollment->id,
                metadata: [
                    'journey_id' => $journey->id,
                    'journey_version' => $version->version,
                    'subject_type' => $subject::class,
                    'subject_id' => $subject->id,
                ],
            );

            return $enrollment;
        });
    }

    private function processStepRun(OnboardingStepRun $run, ?User $actor): string
    {
        return DB::transaction(function () use ($run, $actor) {
            $locked = OnboardingStepRun::query()->whereKey($run->id)->lockForUpdate()->first();
            if ($locked === null || $locked->status !== OnboardingStepRun::STATUS_PENDING) {
                return 'completed';
            }

            $enrollment = $locked->enrollment()->with(['journeyVersion', 'subject'])->first();
            if ($enrollment === null) {
                return 'failed';
            }

            $skipReason = $this->skipReasonForEnrollment($enrollment);
            if ($skipReason !== null) {
                $locked->update([
                    'status' => OnboardingStepRun::STATUS_SKIPPED,
                    'skip_reason' => $skipReason,
                    'executed_at' => now(),
                    'attempts' => $locked->attempts + 1,
                ]);

                $this->audit->record(
                    actor: $actor,
                    action: 'onboarding.step.skipped',
                    category: AuditEvent::CATEGORY_BUSINESS,
                    module: 'onboarding',
                    branchId: $enrollment->branch_id,
                    subjectType: OnboardingEnrollment::class,
                    subjectId: $enrollment->id,
                    metadata: ['step_key' => $locked->step_key, 'reason' => $skipReason],
                );

                return 'skipped';
            }

            try {
                $result = $this->executeStep($locked, $enrollment);
                $locked->update([
                    'status' => OnboardingStepRun::STATUS_COMPLETED,
                    'result' => $result,
                    'executed_at' => now(),
                    'attempts' => $locked->attempts + 1,
                ]);

                $this->audit->record(
                    actor: $actor,
                    action: 'onboarding.step.completed',
                    category: AuditEvent::CATEGORY_BUSINESS,
                    module: 'onboarding',
                    branchId: $enrollment->branch_id,
                    subjectType: OnboardingEnrollment::class,
                    subjectId: $enrollment->id,
                    metadata: ['step_key' => $locked->step_key, 'action_type' => $locked->action_type],
                );

                $this->refreshEnrollmentStatus($enrollment);

                return 'completed';
            } catch (\Throwable $e) {
                $locked->update([
                    'status' => OnboardingStepRun::STATUS_FAILED,
                    'failure_reason' => $e->getMessage(),
                    'executed_at' => now(),
                    'attempts' => $locked->attempts + 1,
                ]);

                return 'failed';
            }
        });
    }

    /** @return array<string, mixed> */
    private function executeStep(OnboardingStepRun $run, OnboardingEnrollment $enrollment): array
    {
        $subject = $enrollment->subject;
        $step = collect($enrollment->journeyVersion?->steps ?? [])
            ->firstWhere('key', $run->step_key) ?? [];

        $result = [
            'action_type' => $run->action_type,
            'step_key' => $run->step_key,
            'channel' => $step['channel'] ?? null,
            'template' => $step['template'] ?? null,
            'title' => $step['title'] ?? null,
            'executed' => true,
        ];

        if ($subject instanceof Member && $subject->user_id !== null && in_array($run->action_type, ['message', 'reminder'], true)) {
            MemberNotification::create([
                'member_id' => $subject->id,
                'user_id' => $subject->user_id,
                'type' => 'onboarding.' . $run->action_type,
                'message' => $step['message'] ?? ('Onboarding step: ' . $run->step_key),
                'metadata' => ['enrollment_id' => $enrollment->id, 'step_key' => $run->step_key],
            ]);
        }

        if ($run->action_type === 'message' && isset($step['simulate_failure']) && $step['simulate_failure'] === true) {
            throw new \RuntimeException('Simulated onboarding step failure.');
        }

        return $result;
    }

    private function skipReasonForEnrollment(OnboardingEnrollment $enrollment): ?string
    {
        if ($enrollment->status === OnboardingEnrollment::STATUS_STOPPED) {
            return $enrollment->stop_reason ?? 'enrollment_stopped';
        }

        $subject = $enrollment->subject;
        $stopConditions = $enrollment->journeyVersion?->stop_conditions ?? [];

        if ($subject instanceof Member) {
            if (in_array($subject->lifecycle_status, config('onboarding.blocked_lifecycle_statuses', []), true)) {
                return 'lifecycle_status_blocked';
            }

            if (($stopConditions['require_consent_data_processing'] ?? true) && ! $subject->consent_data_processing) {
                return 'consent_withdrawn';
            }
        }

        if ($subject instanceof Visitor) {
            $latestVisit = VisitorVisit::query()
                ->where('visitor_id', $subject->id)
                ->orderByDesc('visit_date')
                ->first();

            if ($latestVisit !== null) {
                if (($stopConditions['require_consent_follow_up'] ?? true) && ! $latestVisit->consent_follow_up) {
                    return 'follow_up_consent_withdrawn';
                }
                if (($stopConditions['require_consent_data_processing'] ?? true) && ! $latestVisit->consent_data_processing) {
                    return 'consent_withdrawn';
                }
            }
        }

        return null;
    }

    private function refreshEnrollmentStatus(OnboardingEnrollment $enrollment): void
    {
        $pending = $enrollment->stepRuns()->where('status', OnboardingStepRun::STATUS_PENDING)->count();
        if ($pending === 0) {
            $enrollment->update(['status' => OnboardingEnrollment::STATUS_COMPLETED]);
        }
    }

    private function branchIdForSubject(Model $subject): ?int
    {
        return match (true) {
            $subject instanceof Member => $subject->branch_id,
            $subject instanceof Visitor => $subject->branch_id,
            default => null,
        };
    }

    private function subjectName(?Model $subject): ?string
    {
        if ($subject === null) {
            return null;
        }

        return method_exists($subject, 'fullName') ? $subject->fullName() : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateJourneyPayload(array $payload, ?int $journeyId = null): array
    {
        return validator($payload, [
            'name' => ['required', 'string', 'max:255'],
            'trigger_event' => ['required', 'string', 'in:' . implode(',', array_keys(config('onboarding.triggers', [])))],
            'branch_id' => ['nullable', 'integer', 'exists:organizations,id'],
        ])->validate();
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     * @return array<int, array<string, mixed>>
     */
    private function validateSteps(array $steps): array
    {
        if ($steps === []) {
            throw ValidationException::withMessages(['steps' => ['At least one journey step is required.']]);
        }

        $allowedOffsets = config('onboarding.day_offsets', []);
        $allowedActions = config('onboarding.action_types', []);
        $validated = [];

        foreach ($steps as $index => $step) {
            $row = validator($step, [
                'key' => ['required', 'string', 'max:64'],
                'day_offset' => ['required', 'integer', 'in:' . implode(',', $allowedOffsets)],
                'action_type' => ['required', 'string', 'in:' . implode(',', $allowedActions)],
                'channel' => ['nullable', 'string', 'in:' . implode(',', config('onboarding.channels', []))],
                'template' => ['nullable', 'string', 'max:120'],
                'title' => ['nullable', 'string', 'max:255'],
                'message' => ['nullable', 'string', 'max:2000'],
                'simulate_failure' => ['nullable', 'boolean'],
            ])->validate();

            $validated[] = $row;
        }

        return $validated;
    }

    private function assertCan(User $actor, string $action): void
    {
        if (! $this->authorization->allows($actor, $action)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function assertJourneyInScope(User $actor, OnboardingJourney $journey): void
    {
        if ($journey->branch_id === null || $actor->isChurchWide()) {
            return;
        }

        try {
            BranchScope::for($actor)->assertIncludes($journey->branch_id);
        } catch (BranchScopeException) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function assertEnrollmentInScope(User $actor, OnboardingEnrollment $enrollment): void
    {
        if ($actor->isChurchWide()) {
            return;
        }

        try {
            BranchScope::for($actor)->assertIncludes($enrollment->branch_id);
        } catch (BranchScopeException) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    /** @param  Builder<OnboardingJourney>  $query */
    private function applyBranchScope(Builder $query, User $actor): void
    {
        if ($actor->isChurchWide()) {
            return;
        }

        try {
            $scope = BranchScope::for($actor);
            $branchIds = $scope->subtreeIds((int) $scope->branchId());
            $query->where(function (Builder $q) use ($branchIds): void {
                $q->whereNull('branch_id')->orWhereIn('branch_id', $branchIds);
            });
        } catch (BranchScopeException) {
            $query->whereRaw('1 = 0');
        }
    }

    /** @param  Builder<OnboardingEnrollment>  $query */
    private function applyEnrollmentBranchScope(Builder $query, User $actor): void
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
