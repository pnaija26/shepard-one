<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Member;
use App\Models\MemberNotification;
use App\Models\TrainingEnrolment;
use App\Models\TrainingOffering;
use App\Models\TrainingOfferingVersion;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Story 6.3: training and discipleship offerings with governed enrolment.
 */
class TrainingOfferingService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
    ) {
    }

    /**
     * @return Collection<int, TrainingOffering>
     */
    public function listOfferings(User $actor, array $filters = []): Collection
    {
        $this->assertCan($actor, 'training.read');

        $query = TrainingOffering::query()
            ->with('branch:id,name')
            ->orderBy('name');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['course_type'])) {
            $query->where('course_type', $filters['course_type']);
        }

        $this->applyBranchScope($query, $actor);

        return $query->limit(200)->get();
    }

    public function showOffering(User $actor, TrainingOffering $offering): TrainingOffering
    {
        $this->assertCan($actor, 'training.read');
        $this->assertOfferingInScope($actor, $offering);

        return $offering->load([
            'branch:id,name',
            'versions' => fn ($q) => $q->orderByDesc('version'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createOffering(User $actor, array $payload): TrainingOffering
    {
        $this->assertCan($actor, 'training.manage');

        $validated = $this->validateOfferingPayload($payload);
        $this->assertBranchWritable($actor, (int) $validated['branch_id']);
        $this->assertVersionConfigValid($validated);

        return DB::transaction(function () use ($actor, $validated): TrainingOffering {
            $offering = TrainingOffering::create([
                'branch_id' => $validated['branch_id'],
                'name' => $validated['name'],
                'course_type' => $validated['course_type'],
                'description' => $validated['description'] ?? null,
                'status' => TrainingOffering::STATUS_DRAFT,
                'capacity' => $validated['capacity'] ?? null,
                'waitlist_enabled' => $validated['waitlist_enabled'] ?? true,
                'current_version' => 0,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            TrainingOfferingVersion::create([
                'training_offering_id' => $offering->id,
                'version' => 1,
                'status' => TrainingOfferingVersion::STATUS_DRAFT,
                'sessions' => $this->normalizeSessions($validated['sessions']),
                'prerequisites' => $validated['prerequisites'] ?? [],
                'facilitators' => $this->normalizeFacilitators($validated['facilitators'] ?? []),
                'assessments' => $validated['assessments'] ?? [],
                'materials' => $this->normalizeMaterials($validated['materials'] ?? []),
                'completion_rules' => $validated['completion_rules'] ?? [],
                'enrolment_rules' => $validated['enrolment_rules'] ?? [],
                'created_by' => $actor->id,
            ]);

            $this->audit($actor, 'training_offering.created', $offering);

            return $offering->fresh(['versions', 'branch:id,name']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateDraft(User $actor, TrainingOffering $offering, array $payload): TrainingOffering
    {
        $this->assertCan($actor, 'training.manage');
        $this->assertOfferingInScope($actor, $offering);

        if ($offering->status === TrainingOffering::STATUS_ARCHIVED) {
            throw ValidationException::withMessages(['offering' => ['Archived offerings cannot be edited.']]);
        }

        $validated = $this->validateOfferingPayload($payload, $offering->id);
        $this->assertBranchWritable($actor, (int) $validated['branch_id']);
        $this->assertVersionConfigValid($validated);

        return DB::transaction(function () use ($actor, $offering, $validated): TrainingOffering {
            $offering->update([
                'branch_id' => $validated['branch_id'],
                'name' => $validated['name'],
                'course_type' => $validated['course_type'],
                'description' => $validated['description'] ?? null,
                'capacity' => $validated['capacity'] ?? null,
                'waitlist_enabled' => $validated['waitlist_enabled'] ?? true,
                'updated_by' => $actor->id,
            ]);

            $draft = $this->draftVersion($offering);
            if ($draft->status === TrainingOfferingVersion::STATUS_PUBLISHED) {
                $draft = TrainingOfferingVersion::create([
                    'training_offering_id' => $offering->id,
                    'version' => $offering->current_version + 1,
                    'status' => TrainingOfferingVersion::STATUS_DRAFT,
                    'sessions' => $this->normalizeSessions($validated['sessions']),
                    'prerequisites' => $validated['prerequisites'] ?? [],
                    'facilitators' => $this->normalizeFacilitators($validated['facilitators'] ?? []),
                    'assessments' => $validated['assessments'] ?? [],
                    'materials' => $this->normalizeMaterials($validated['materials'] ?? []),
                    'completion_rules' => $validated['completion_rules'] ?? [],
                    'enrolment_rules' => $validated['enrolment_rules'] ?? [],
                    'created_by' => $actor->id,
                ]);
            } else {
                $draft->update([
                    'sessions' => $this->normalizeSessions($validated['sessions']),
                    'prerequisites' => $validated['prerequisites'] ?? [],
                    'facilitators' => $this->normalizeFacilitators($validated['facilitators'] ?? []),
                    'assessments' => $validated['assessments'] ?? [],
                    'materials' => $this->normalizeMaterials($validated['materials'] ?? []),
                    'completion_rules' => $validated['completion_rules'] ?? [],
                    'enrolment_rules' => $validated['enrolment_rules'] ?? [],
                ]);
            }

            $this->audit($actor, 'training_offering.updated', $offering);

            return $offering->fresh(['versions', 'branch:id,name']);
        });
    }

    public function publishOffering(User $actor, TrainingOffering $offering): TrainingOffering
    {
        $this->assertCan($actor, 'training.publish');
        $this->assertOfferingInScope($actor, $offering);

        $draft = $this->draftVersion($offering);
        if (($draft->sessions ?? []) === []) {
            throw ValidationException::withMessages(['sessions' => ['At least one session is required before publishing.']]);
        }

        return DB::transaction(function () use ($actor, $offering, $draft): TrainingOffering {
            $draft->update([
                'status' => TrainingOfferingVersion::STATUS_PUBLISHED,
                'published_by' => $actor->id,
                'published_at' => now(),
            ]);

            $offering->update([
                'status' => TrainingOffering::STATUS_PUBLISHED,
                'current_version' => $draft->version,
                'published_at' => now(),
                'updated_by' => $actor->id,
            ]);

            $this->audit($actor, 'training_offering.published', $offering, ['version' => $draft->version]);

            return $offering->fresh(['versions', 'branch:id,name']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function requestEnrolment(User $actor, TrainingOffering $offering, array $payload): TrainingEnrolment
    {
        $registrar = $this->authorization->allows($actor, 'training.enrol');
        $selfEnrol = $this->authorization->allows($actor, 'training.enrol.self');

        if (! $registrar && ! $selfEnrol) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }

        $this->assertOfferingInScope($actor, $offering);

        if ($offering->status !== TrainingOffering::STATUS_PUBLISHED) {
            throw new TrainingOfferingException('Only published offerings accept enrolment.', 'not_published', 422);
        }

        $validated = validator($payload, [
            'member_id' => ['required', 'integer', 'exists:members,id'],
        ])->validate();

        $member = Member::query()->findOrFail((int) $validated['member_id']);
        $this->assertMemberInScope($actor, $member);

        if (! $registrar) {
            $linked = Member::query()->where('user_id', $actor->id)->first();
            if ($linked === null || (int) $linked->id !== (int) $member->id) {
                throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
            }
        }

        $version = $this->publishedVersion($offering);
        if ($version === null) {
            throw new TrainingOfferingException('Published version not found.', 'missing_version', 422);
        }

        if (TrainingEnrolment::query()
            ->where('training_offering_id', $offering->id)
            ->where('member_id', $member->id)
            ->whereIn('status', [TrainingEnrolment::STATUS_ENROLLED, TrainingEnrolment::STATUS_WAITLISTED])
            ->exists()) {
            throw new TrainingOfferingException('Member already has an active enrolment for this offering.', 'duplicate_enrolment', 422);
        }

        $prerequisiteFailure = $this->evaluatePrerequisites($member, $version);
        if ($prerequisiteFailure !== null) {
            return $this->recordEnrolment($actor, $offering, $version, $member, TrainingEnrolment::STATUS_REJECTED, [
                'rejection_reason' => $prerequisiteFailure,
            ]);
        }

        $eligibilityFailure = $this->evaluateEligibility($member, $version);
        if ($eligibilityFailure !== null) {
            return $this->recordEnrolment($actor, $offering, $version, $member, TrainingEnrolment::STATUS_REJECTED, [
                'rejection_reason' => $eligibilityFailure,
            ]);
        }

        $enrolledCount = TrainingEnrolment::query()
            ->where('training_offering_id', $offering->id)
            ->where('status', TrainingEnrolment::STATUS_ENROLLED)
            ->count();

        if ($offering->capacity !== null && $enrolledCount >= $offering->capacity) {
            if ($offering->waitlist_enabled) {
                $position = TrainingEnrolment::query()
                    ->where('training_offering_id', $offering->id)
                    ->where('status', TrainingEnrolment::STATUS_WAITLISTED)
                    ->count() + 1;

                return $this->recordEnrolment($actor, $offering, $version, $member, TrainingEnrolment::STATUS_WAITLISTED, [
                    'waitlist_position' => $position,
                ]);
            }

            return $this->recordEnrolment($actor, $offering, $version, $member, TrainingEnrolment::STATUS_REJECTED, [
                'rejection_reason' => 'Offering is at capacity.',
            ]);
        }

        return $this->recordEnrolment($actor, $offering, $version, $member, TrainingEnrolment::STATUS_ENROLLED);
    }

    public function showEnrolment(User $actor, TrainingEnrolment $enrolment): TrainingEnrolment
    {
        $canManage = $this->authorization->allows($actor, 'training.enrol');
        $canSelf = $this->authorization->allows($actor, 'training.enrol.self');

        if (! $canManage && ! $canSelf && ! $this->authorization->allows($actor, 'training.read')) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }

        $enrolment->load(['offering.branch:id,name', 'member:id,first_name,last_name,membership_id', 'version']);

        if (! $canManage && ! $this->authorization->allows($actor, 'training.read')) {
            $linked = Member::query()->where('user_id', $actor->id)->first();
            if ($linked === null || (int) $linked->id !== (int) $enrolment->member_id) {
                throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
            }
        } else {
            $this->assertOfferingInScope($actor, $enrolment->offering);
        }

        return $enrolment;
    }

    /**
     * @return Collection<int, TrainingEnrolment>
     */
    public function listEnrolments(User $actor, TrainingOffering $offering): Collection
    {
        $this->assertCan($actor, 'training.enrol');
        $this->assertOfferingInScope($actor, $offering);

        return TrainingEnrolment::query()
            ->with('member:id,first_name,last_name,membership_id')
            ->where('training_offering_id', $offering->id)
            ->orderByDesc('created_at')
            ->limit(500)
            ->get();
    }

    public function formatOffering(TrainingOffering $offering, User $actor, ?Member $viewerMember = null): array
    {
        $canReadRestrictedMaterials = $this->authorization->allows($actor, 'training.materials.restricted');
        $canReadFacilitators = $this->authorization->allows($actor, 'training.facilitators.read');
        $isEnrolled = $viewerMember !== null && TrainingEnrolment::query()
            ->where('training_offering_id', $offering->id)
            ->where('member_id', $viewerMember->id)
            ->where('status', TrainingEnrolment::STATUS_ENROLLED)
            ->exists();

        $published = $this->publishedVersion($offering);

        return [
            'id' => $offering->id,
            'branch_id' => $offering->branch_id,
            'branch' => $offering->relationLoaded('branch') ? $offering->branch : null,
            'name' => $offering->name,
            'course_type' => $offering->course_type,
            'description' => $offering->description,
            'status' => $offering->status,
            'capacity' => $offering->capacity,
            'waitlist_enabled' => $offering->waitlist_enabled,
            'current_version' => $offering->current_version,
            'published_at' => $offering->published_at?->toIso8601String(),
            'published_config' => $published ? [
                'version' => $published->version,
                'sessions' => $published->sessions ?? [],
                'prerequisites' => $published->prerequisites ?? [],
                'facilitators' => $this->visibleFacilitators($published->facilitators ?? [], $canReadFacilitators),
                'assessments' => $published->assessments ?? [],
                'materials' => $this->visibleMaterials($published->materials ?? [], $canReadRestrictedMaterials, $isEnrolled || $this->authorization->allows($actor, 'training.manage')),
                'completion_rules' => $published->completion_rules ?? [],
                'enrolment_rules' => $published->enrolment_rules ?? [],
            ] : null,
            'versions' => $offering->relationLoaded('versions')
                ? $offering->versions->map(fn (TrainingOfferingVersion $version) => [
                    'id' => $version->id,
                    'version' => $version->version,
                    'status' => $version->status,
                    'session_count' => count($version->sessions ?? []),
                    'published_at' => $version->published_at?->toIso8601String(),
                ])->values()->all()
                : [],
        ];
    }

    public function formatEnrolment(TrainingEnrolment $enrolment, User $actor): array
    {
        $canReadRestrictedMaterials = $this->authorization->allows($actor, 'training.materials.restricted');
        $isEnrolled = $enrolment->status === TrainingEnrolment::STATUS_ENROLLED;

        return [
            'id' => $enrolment->id,
            'training_offering_id' => $enrolment->training_offering_id,
            'offering_name' => $enrolment->offering?->name,
            'member_id' => $enrolment->member_id,
            'member' => $enrolment->relationLoaded('member') ? [
                'id' => $enrolment->member->id,
                'full_name' => $enrolment->member->fullName(),
                'membership_id' => $enrolment->member->membership_id,
            ] : null,
            'status' => $enrolment->status,
            'waitlist_position' => $enrolment->waitlist_position,
            'rejection_reason' => $enrolment->rejection_reason,
            'schedule' => $enrolment->schedule_snapshot ?? [],
            'materials' => $this->visibleMaterials(
                $enrolment->materials_snapshot ?? [],
                $canReadRestrictedMaterials,
                $isEnrolled || $this->authorization->allows($actor, 'training.manage'),
            ),
            'created_at' => $enrolment->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateOfferingPayload(array $payload, ?int $offeringId = null): array
    {
        return validator($payload, [
            'branch_id' => ['required', 'integer', 'exists:organizations,id'],
            'name' => ['required', 'string', 'max:160'],
            'course_type' => ['required', 'string', 'in:' . implode(',', config('training_offerings.course_types', []))],
            'description' => ['nullable', 'string', 'max:5000'],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'waitlist_enabled' => ['nullable', 'boolean'],
            'sessions' => ['required', 'array', 'min:1'],
            'sessions.*.title' => ['required', 'string', 'max:160'],
            'sessions.*.scheduled_at' => ['required', 'date'],
            'sessions.*.location' => ['nullable', 'string', 'max:160'],
            'sessions.*.duration_minutes' => ['nullable', 'integer', 'min:15'],
            'prerequisites' => ['nullable', 'array'],
            'prerequisites.required_offering_ids' => ['nullable', 'array'],
            'prerequisites.required_offering_ids.*' => ['integer', 'exists:training_offerings,id'],
            'facilitators' => ['nullable', 'array'],
            'facilitators.*.name' => ['required_with:facilitators', 'string', 'max:160'],
            'facilitators.*.role' => ['nullable', 'string', 'max:80'],
            'facilitators.*.email' => ['nullable', 'email', 'max:160'],
            'facilitators.*.phone' => ['nullable', 'string', 'max:40'],
            'assessments' => ['nullable', 'array'],
            'assessments.*.title' => ['required_with:assessments', 'string', 'max:160'],
            'assessments.*.type' => ['nullable', 'string', 'in:' . implode(',', config('training_offerings.assessment_types', []))],
            'assessments.*.required' => ['nullable', 'boolean'],
            'materials' => ['nullable', 'array'],
            'materials.*.title' => ['required_with:materials', 'string', 'max:160'],
            'materials.*.url' => ['nullable', 'string', 'max:500'],
            'materials.*.access_level' => ['required_with:materials', 'string', 'in:' . implode(',', config('training_offerings.material_access_levels', []))],
            'completion_rules' => ['nullable', 'array'],
            'enrolment_rules' => ['nullable', 'array'],
            'enrolment_rules.lifecycle_stages' => ['nullable', 'array'],
            'enrolment_rules.min_age' => ['nullable', 'integer', 'min:0'],
            'enrolment_rules.max_age' => ['nullable', 'integer', 'min:0'],
        ])->validate();
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function assertVersionConfigValid(array $validated): void
    {
        foreach ($validated['sessions'] as $index => $session) {
            if (empty($session['title']) || empty($session['scheduled_at'])) {
                throw ValidationException::withMessages([
                    "sessions.{$index}" => ['Each session requires a title and scheduled time.'],
                ]);
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $sessions
     * @return array<int, array<string, mixed>>
     */
    private function normalizeSessions(array $sessions): array
    {
        return array_values(array_map(fn (array $session) => [
            'title' => $session['title'],
            'scheduled_at' => Carbon::parse($session['scheduled_at'])->toIso8601String(),
            'location' => $session['location'] ?? null,
            'duration_minutes' => $session['duration_minutes'] ?? null,
        ], $sessions));
    }

    /**
     * @param  array<int, array<string, mixed>>  $facilitators
     * @return array<int, array<string, mixed>>
     */
    private function normalizeFacilitators(array $facilitators): array
    {
        return array_values(array_map(fn (array $facilitator) => [
            'name' => $facilitator['name'],
            'role' => $facilitator['role'] ?? 'facilitator',
            'email' => $facilitator['email'] ?? null,
            'phone' => $facilitator['phone'] ?? null,
        ], $facilitators));
    }

    /**
     * @param  array<int, array<string, mixed>>  $materials
     * @return array<int, array<string, mixed>>
     */
    private function normalizeMaterials(array $materials): array
    {
        return array_values(array_map(fn (array $material) => [
            'title' => $material['title'],
            'url' => $material['url'] ?? null,
            'access_level' => $material['access_level'] ?? 'enrolled',
        ], $materials));
    }

    /**
     * @param  array<int, array<string, mixed>>  $facilitators
     * @return array<int, array<string, mixed>>
     */
    private function visibleFacilitators(array $facilitators, bool $canReadContact): array
    {
        return array_values(array_map(function (array $facilitator) use ($canReadContact) {
            if ($canReadContact) {
                return $facilitator;
            }

            return [
                'name' => $facilitator['name'],
                'role' => $facilitator['role'] ?? 'facilitator',
                'contact_restricted' => ! empty($facilitator['email']) || ! empty($facilitator['phone']),
            ];
        }, $facilitators));
    }

    /**
     * @param  array<int, array<string, mixed>>  $materials
     * @return array<int, array<string, mixed>>
     */
    private function visibleMaterials(array $materials, bool $canReadRestricted, bool $isEnrolledOrStaff): array
    {
        return array_values(array_filter(array_map(function (array $material) use ($canReadRestricted, $isEnrolledOrStaff) {
            $level = $material['access_level'] ?? 'enrolled';

            if ($level === 'restricted' && ! $canReadRestricted) {
                return [
                    'title' => '[Restricted material]',
                    'access_level' => $level,
                    'restricted' => true,
                ];
            }

            if ($level === 'enrolled' && ! $isEnrolledOrStaff) {
                return null;
            }

            return $material;
        }, $materials)));
    }

    private function evaluatePrerequisites(Member $member, TrainingOfferingVersion $version): ?string
    {
        $requiredIds = $version->prerequisites['required_offering_ids'] ?? [];
        if ($requiredIds === []) {
            return null;
        }

        foreach ($requiredIds as $requiredId) {
            $completed = TrainingProgressService::memberHasValidCompletion($member->id, (int) $requiredId);

            if (! $completed) {
                return 'Required prerequisite offering not completed.';
            }
        }

        return null;
    }

    private function evaluateEligibility(Member $member, TrainingOfferingVersion $version): ?string
    {
        $rules = $version->enrolment_rules ?? [];

        $stages = $rules['lifecycle_stages'] ?? [];
        if ($stages !== [] && ! in_array($member->lifecycle_stage, $stages, true)) {
            return 'Member lifecycle stage is not eligible for this offering.';
        }

        if (! empty($rules['min_age']) && $member->date_of_birth !== null) {
            $age = Carbon::parse($member->date_of_birth)->age;
            if ($age < (int) $rules['min_age']) {
                return 'Member does not meet the minimum age requirement.';
            }
        }

        if (! empty($rules['max_age']) && $member->date_of_birth !== null) {
            $age = Carbon::parse($member->date_of_birth)->age;
            if ($age > (int) $rules['max_age']) {
                return 'Member exceeds the maximum age requirement.';
            }
        }

        if (($rules['requires_consent'] ?? false) && ! $member->consent_data_processing) {
            return 'Member consent is required before enrolment.';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function recordEnrolment(
        User $actor,
        TrainingOffering $offering,
        TrainingOfferingVersion $version,
        Member $member,
        string $status,
        array $extra = [],
    ): TrainingEnrolment {
        return DB::transaction(function () use ($actor, $offering, $version, $member, $status, $extra): TrainingEnrolment {
            $permittedMaterials = $this->visibleMaterials(
                $version->materials ?? [],
                true,
                $status === TrainingEnrolment::STATUS_ENROLLED || $status === TrainingEnrolment::STATUS_WAITLISTED,
            );

            $enrolment = TrainingEnrolment::create([
                'training_offering_id' => $offering->id,
                'training_offering_version_id' => $version->id,
                'member_id' => $member->id,
                'branch_id' => $offering->branch_id,
                'status' => $status,
                'waitlist_position' => $extra['waitlist_position'] ?? null,
                'rejection_reason' => $extra['rejection_reason'] ?? null,
                'schedule_snapshot' => $version->sessions ?? [],
                'materials_snapshot' => $permittedMaterials,
                'enrolled_by' => $actor->id,
            ]);

            $this->audit($actor, 'training.enrolment.' . $status, $offering, [
                'enrolment_id' => $enrolment->id,
                'member_id' => $member->id,
            ]);

            if (in_array($status, [TrainingEnrolment::STATUS_ENROLLED, TrainingEnrolment::STATUS_WAITLISTED], true)) {
                $this->notifyMember($member, $enrolment, $offering);
            }

            return $enrolment->fresh(['member:id,first_name,last_name,membership_id', 'offering:id,name']);
        });
    }

    private function notifyMember(Member $member, TrainingEnrolment $enrolment, TrainingOffering $offering): void
    {
        if ($member->user_id === null) {
            return;
        }

        $message = $enrolment->status === TrainingEnrolment::STATUS_WAITLISTED
            ? 'You have been waitlisted for ' . $offering->name . '.'
            : 'You are enrolled in ' . $offering->name . '. Review your schedule and materials.';

        MemberNotification::create([
            'member_id' => $member->id,
            'user_id' => $member->user_id,
            'type' => 'training.enrolment.' . $enrolment->status,
            'message' => $message,
            'metadata' => [
                'training_offering_id' => $offering->id,
                'enrolment_id' => $enrolment->id,
                'session_count' => count($enrolment->schedule_snapshot ?? []),
            ],
        ]);
    }

    private function draftVersion(TrainingOffering $offering): TrainingOfferingVersion
    {
        $draft = TrainingOfferingVersion::query()
            ->where('training_offering_id', $offering->id)
            ->where('status', TrainingOfferingVersion::STATUS_DRAFT)
            ->orderByDesc('version')
            ->first();

        if ($draft !== null) {
            return $draft;
        }

        return TrainingOfferingVersion::query()
            ->where('training_offering_id', $offering->id)
            ->orderByDesc('version')
            ->firstOrFail();
    }

    private function publishedVersion(TrainingOffering $offering): ?TrainingOfferingVersion
    {
        if ($offering->current_version < 1) {
            return null;
        }

        return TrainingOfferingVersion::query()
            ->where('training_offering_id', $offering->id)
            ->where('version', $offering->current_version)
            ->where('status', TrainingOfferingVersion::STATUS_PUBLISHED)
            ->first();
    }

    private function assertCan(User $actor, string $action): void
    {
        if (! $this->authorization->allows($actor, $action)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
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

    private function assertOfferingInScope(User $actor, TrainingOffering $offering): void
    {
        if ($actor->isChurchWide()) {
            return;
        }

        try {
            BranchScope::for($actor)->assertIncludes((int) $offering->branch_id);
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

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function audit(User $actor, string $action, TrainingOffering $offering, ?array $metadata = null): void
    {
        $this->audit->record(
            actor: $actor,
            action: $action,
            category: AuditEvent::CATEGORY_BUSINESS,
            module: 'training',
            branchId: $offering->branch_id,
            subjectType: TrainingOffering::class,
            subjectId: $offering->id,
            after: array_filter([
                'offering_id' => $offering->id,
                'metadata' => $metadata,
            ]),
        );
    }
}
