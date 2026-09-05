<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Member;
use App\Models\ServiceTeamAssignment;
use App\Models\User;
use App\Models\VolunteerProfile;
use App\Models\VolunteerProfileChange;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Story 5.3: volunteer profiles with verification and restricted coordinator notes.
 */
class VolunteerProfileService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
    ) {
    }

    /**
     * @return Collection<int, VolunteerProfile>
     */
    public function listProfiles(User $actor, array $filters = []): Collection
    {
        $this->assertCan($actor, 'volunteers.read');

        $query = VolunteerProfile::query()
            ->with(['member:id,first_name,last_name,membership_id,lifecycle_status'])
            ->orderByDesc('updated_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $this->applyBranchScope($query, $actor);

        return $query->limit(200)->get();
    }

    public function showProfile(User $actor, VolunteerProfile $profile): VolunteerProfile
    {
        $this->assertCanView($actor, $profile);

        return $profile->load([
            'member:id,first_name,last_name,membership_id,lifecycle_status,user_id',
            'changes' => fn ($q) => $q->latest('created_at')->limit(20),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createProfile(User $actor, array $payload): VolunteerProfile
    {
        $this->assertCan($actor, 'volunteers.manage');

        $validated = validator($payload, [
            'member_id' => ['required', 'integer', 'exists:members,id'],
            'skills' => ['nullable', 'array'],
            'skills.*' => ['string', 'max:80'],
            'expertise' => ['nullable', 'array'],
            'availability' => ['nullable', 'array'],
            'preferences' => ['nullable', 'array'],
            'experience' => ['nullable', 'array'],
            'certifications' => ['nullable', 'array'],
            'training' => ['nullable', 'array'],
            'service_history' => ['nullable', 'array'],
            'volunteer_hours' => ['nullable', 'numeric', 'min:0'],
            'restricted_notes' => ['nullable', 'string', 'max:2000'],
            'effective_from' => ['nullable', 'date'],
        ])->validate();

        $member = Member::query()->findOrFail($validated['member_id']);
        $this->assertMemberEligible($member);
        $this->assertMemberInScope($actor, $member);

        if (VolunteerProfile::query()->where('member_id', $member->id)->exists()) {
            throw ValidationException::withMessages(['member_id' => ['A volunteer profile already exists for this member.']]);
        }

        return DB::transaction(function () use ($actor, $member, $validated): VolunteerProfile {
            $profile = VolunteerProfile::create([
                'member_id' => $member->id,
                'branch_id' => $member->branch_id,
                'skills' => $validated['skills'] ?? $member->skills ?? [],
                'expertise' => $this->normalizeExpertise($validated['expertise'] ?? []),
                'availability' => $this->normalizeAvailability($validated['availability'] ?? []),
                'preferences' => $validated['preferences'] ?? [],
                'experience' => $validated['experience'] ?? [],
                'certifications' => $this->markVerifiedEntries($validated['certifications'] ?? []),
                'training' => $this->markVerifiedEntries($validated['training'] ?? []),
                'service_history' => $validated['service_history'] ?? [],
                'volunteer_hours' => $validated['volunteer_hours'] ?? 0,
                'restricted_notes' => $validated['restricted_notes'] ?? null,
                'status' => VolunteerProfile::STATUS_ACTIVE,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->recordChange(
                $profile,
                'profile',
                VolunteerProfileChange::SOURCE_COORDINATOR,
                null,
                ['status' => VolunteerProfile::STATUS_ACTIVE],
                VolunteerProfileChange::STATUS_VERIFIED,
                $actor,
                Carbon::parse($validated['effective_from'] ?? now()->toDateString()),
            );

            $this->audit($actor, 'volunteer_profile.created', $profile);

            return $profile->fresh(['member:id,first_name,last_name,membership_id']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateProfile(User $actor, VolunteerProfile $profile, array $payload, bool $selfService = false): VolunteerProfile
    {
        if ($selfService) {
            $this->assertSelfAccess($actor, $profile);
        } else {
            $this->assertCan($actor, 'volunteers.manage');
            $this->assertCanView($actor, $profile);
        }

        $allowedFields = $selfService
            ? config('volunteer_profiles.self_service_fields', [])
            : config('volunteer_profiles.coordinator_fields', []);

        $rules = $this->validationRules($allowedFields);
        $validated = validator($payload, $rules)->validate();
        $effectiveFrom = Carbon::parse($validated['effective_from'] ?? now()->toDateString());

        return DB::transaction(function () use ($actor, $profile, $validated, $selfService, $effectiveFrom): VolunteerProfile {
            $updates = [];
            $pendingFields = [];

            foreach ($validated as $field => $value) {
                if ($field === 'effective_from') {
                    continue;
                }

                if (! in_array($field, $selfService ? config('volunteer_profiles.self_service_fields', []) : config('volunteer_profiles.coordinator_fields', []), true)) {
                    continue;
                }

                $normalized = $this->normalizeFieldValue($field, $value, $selfService);

                if ($selfService && in_array($field, config('volunteer_profiles.verification_required_fields', []), true)) {
                    $pendingFields[$field] = $normalized;
                    continue;
                }

                $previous = $profile->{$field};
                if ($previous == $normalized) {
                    continue;
                }

                $updates[$field] = $normalized;
                $this->recordChange(
                    $profile,
                    $field,
                    $selfService ? VolunteerProfileChange::SOURCE_SELF : VolunteerProfileChange::SOURCE_COORDINATOR,
                    is_array($previous) ? $previous : ['value' => $previous],
                    is_array($normalized) ? $normalized : ['value' => $normalized],
                    $selfService ? VolunteerProfileChange::STATUS_APPLIED : VolunteerProfileChange::STATUS_VERIFIED,
                    $actor,
                    $effectiveFrom,
                );
            }

            foreach ($pendingFields as $field => $normalized) {
                $previous = $profile->{$field};
                $this->recordChange(
                    $profile,
                    $field,
                    VolunteerProfileChange::SOURCE_SELF,
                    is_array($previous) ? $previous : ['value' => $previous],
                    $normalized,
                    VolunteerProfileChange::STATUS_PENDING,
                    $actor,
                    $effectiveFrom,
                );
            }

            if ($updates !== [] || $pendingFields !== []) {
                $profile->fill(array_merge($updates, ['updated_by' => $actor->id]));
                $profile->save();
                $this->audit($actor, $selfService ? 'volunteer_profile.self_updated' : 'volunteer_profile.updated', $profile);
            }

            return $profile->fresh(['member:id,first_name,last_name,membership_id', 'changes']);
        });
    }

    public function verifyPendingChange(User $actor, VolunteerProfileChange $change, bool $approve, ?string $reason = null): VolunteerProfile
    {
        $this->assertCan($actor, 'volunteers.manage');

        $profile = $change->profile ?? VolunteerProfile::query()->findOrFail($change->volunteer_profile_id);
        $this->assertCanView($actor, $profile);

        if ($change->verification_status !== VolunteerProfileChange::STATUS_PENDING) {
            throw ValidationException::withMessages(['change' => ['Only pending changes can be verified.']]);
        }

        return DB::transaction(function () use ($actor, $profile, $change, $approve, $reason): VolunteerProfile {
            if ($approve) {
                $value = $this->markVerifiedEntries($change->new_value ?? []);
                $profile->update([
                    $change->field => $value,
                    'updated_by' => $actor->id,
                ]);

                $change->update([
                    'verification_status' => VolunteerProfileChange::STATUS_VERIFIED,
                    'verified_by' => $actor->id,
                    'verified_at' => now(),
                ]);
            } else {
                $change->update([
                    'verification_status' => VolunteerProfileChange::STATUS_REJECTED,
                    'verified_by' => $actor->id,
                    'verified_at' => now(),
                ]);
            }

            $this->audit($actor, $approve ? 'volunteer_profile.change_verified' : 'volunteer_profile.change_rejected', $profile, [
                'field' => $change->field,
                'reason' => $reason,
            ]);

            return $profile->fresh(['member:id,first_name,last_name,membership_id', 'changes']);
        });
    }

    /**
     * @return array{expiring_certifications: array<int, array<string, mixed>>, unavailable_periods: array<int, array<string, mixed>>}
     */
    public function listAlerts(User $actor): array
    {
        $this->assertCan($actor, 'volunteers.read');

        $profiles = $this->listProfiles($actor, ['status' => VolunteerProfile::STATUS_ACTIVE]);
        $warningDays = (int) config('volunteer_profiles.certification_expiry_warning_days', 30);
        $today = now()->startOfDay();
        $warningUntil = $today->copy()->addDays($warningDays);

        $expiring = [];
        $unavailable = [];

        foreach ($profiles as $profile) {
            foreach ($profile->certifications ?? [] as $index => $cert) {
                $expiresAt = ! empty($cert['expires_at']) ? Carbon::parse($cert['expires_at']) : null;
                if ($expiresAt !== null && $expiresAt->betweenIncluded($today, $warningUntil)) {
                    $expiring[] = [
                        'profile_id' => $profile->id,
                        'member_id' => $profile->member_id,
                        'member_name' => $profile->member?->fullName(),
                        'certification' => $cert['name'] ?? 'Certification',
                        'expires_at' => $expiresAt->toDateString(),
                        'index' => $index,
                    ];
                }
            }

            foreach ($profile->availability['unavailable_periods'] ?? [] as $index => $period) {
                $from = ! empty($period['from']) ? Carbon::parse($period['from']) : null;
                $to = ! empty($period['to']) ? Carbon::parse($period['to']) : null;
                if ($from !== null && $to !== null && $today->betweenIncluded($from, $to)) {
                    $unavailable[] = [
                        'profile_id' => $profile->id,
                        'member_id' => $profile->member_id,
                        'member_name' => $profile->member?->fullName(),
                        'from' => $from->toDateString(),
                        'to' => $to->toDateString(),
                        'reason' => $period['reason'] ?? null,
                        'index' => $index,
                    ];
                }
            }
        }

        return [
            'expiring_certifications' => $expiring,
            'unavailable_periods' => $unavailable,
        ];
    }

    public function profileForMember(User $actor): VolunteerProfile
    {
        $member = $this->resolveMember($actor);
        $profile = VolunteerProfile::query()->where('member_id', $member->id)->first();

        if ($profile === null) {
            throw ValidationException::withMessages([
                'profile' => ['No volunteer profile exists for your member record yet.'],
            ]);
        }

        return $this->showProfile($actor, $profile);
    }

    public function formatProfile(VolunteerProfile $profile, User $actor, bool $coordinatorView = false): array
    {
        $canManage = $this->authorization->allows($actor, 'volunteers.manage');
        $isSelf = $profile->member?->user_id === $actor->id;
        $includeRestricted = $canManage && $coordinatorView;

        $teams = ServiceTeamAssignment::query()
            ->with(['team:id,name,status'])
            ->where('member_id', $profile->member_id)
            ->whereIn('status', config('team_assignments.active_statuses', []))
            ->get()
            ->map(fn (ServiceTeamAssignment $assignment) => [
                'team_id' => $assignment->service_team_id,
                'team_name' => $assignment->team?->name,
                'team_role' => $assignment->team_role,
                'shift_label' => $assignment->shift_label,
                'status' => $assignment->status,
                'effective_from' => $assignment->effective_from?->toDateString(),
            ])
            ->values()
            ->all();

        $pendingChanges = $profile->relationLoaded('changes')
            ? $profile->changes->where('verification_status', VolunteerProfileChange::STATUS_PENDING)->values()
            : collect();

        $data = [
            'id' => $profile->id,
            'member_id' => $profile->member_id,
            'branch_id' => $profile->branch_id,
            'status' => $profile->status,
            'skills' => $profile->skills ?? [],
            'expertise' => $profile->expertise ?? [],
            'availability' => $profile->availability ?? [],
            'preferences' => $profile->preferences ?? [],
            'experience' => $profile->experience ?? [],
            'certifications' => $this->visibleCertifications($profile->certifications ?? [], $isSelf, $canManage),
            'training' => $this->visibleTraining($profile->training ?? [], $isSelf, $canManage),
            'service_history' => $profile->service_history ?? [],
            'volunteer_hours' => (float) $profile->volunteer_hours,
            'teams' => $teams,
            'member' => $profile->relationLoaded('member') && $profile->member
                ? [
                    'id' => $profile->member->id,
                    'full_name' => $profile->member->fullName(),
                    'membership_id' => $profile->member->membership_id,
                    'lifecycle_status' => $profile->member->lifecycle_status,
                ]
                : null,
            'pending_changes' => $pendingChanges->map(fn (VolunteerProfileChange $change) => [
                'id' => $change->id,
                'field' => $change->field,
                'verification_status' => $change->verification_status,
                'effective_from' => $change->effective_from?->toDateString(),
                'submitted_at' => $change->created_at?->toIso8601String(),
            ])->values()->all(),
            'alerts' => [
                'expiring_certifications' => $this->profileExpiringCertifications($profile),
                'unavailable_now' => $this->profileUnavailableNow($profile),
            ],
        ];

        if ($includeRestricted) {
            $data['restricted_notes'] = $profile->restricted_notes;
        }

        return $data;
    }

    /**
     * @param  array<int, mixed>|null  $previous
     * @param  array<int, mixed>|null  $new
     */
    private function recordChange(
        VolunteerProfile $profile,
        string $field,
        string $source,
        ?array $previous,
        ?array $new,
        string $verificationStatus,
        User $actor,
        Carbon $effectiveFrom,
    ): void {
        VolunteerProfileChange::create([
            'volunteer_profile_id' => $profile->id,
            'field' => $field,
            'change_source' => $source,
            'previous_value' => $previous,
            'new_value' => $new,
            'verification_status' => $verificationStatus,
            'effective_from' => $effectiveFrom->toDateString(),
            'actor_id' => $actor->id,
            'verified_by' => $verificationStatus === VolunteerProfileChange::STATUS_VERIFIED ? $actor->id : null,
            'verified_at' => $verificationStatus === VolunteerProfileChange::STATUS_VERIFIED ? now() : null,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  string[]  $fields
     * @return array<string, mixed>
     */
    private function validationRules(array $fields): array
    {
        $rules = ['effective_from' => ['nullable', 'date']];

        foreach ($fields as $field) {
            $rules[$field] = match ($field) {
                'skills' => ['nullable', 'array'],
                'expertise', 'availability', 'preferences', 'experience', 'certifications', 'training', 'service_history' => ['nullable', 'array'],
                'volunteer_hours' => ['nullable', 'numeric', 'min:0'],
                'restricted_notes' => ['nullable', 'string', 'max:2000'],
                'status' => ['nullable', 'string', 'in:' . implode(',', config('volunteer_profiles.statuses', []))],
                default => ['nullable'],
            };
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>|array<int, mixed>|float|string|null
     */
    private function normalizeFieldValue(string $field, mixed $value, bool $selfService): mixed
    {
        return match ($field) {
            'skills' => array_values(array_filter((array) $value)),
            'expertise' => $this->normalizeExpertise((array) $value),
            'availability' => $this->normalizeAvailability((array) $value),
            'certifications', 'training' => $selfService
                ? $this->markSelfDeclaredEntries((array) $value)
                : $this->markVerifiedEntries((array) $value),
            'volunteer_hours' => (float) $value,
            'restricted_notes' => $value !== null ? (string) $value : null,
            'status' => (string) $value,
            default => $value,
        };
    }

    /**
     * @param  array<int, mixed>  $entries
     * @return array<int, array<string, mixed>>
     */
    private function normalizeExpertise(array $entries): array
    {
        return array_values(array_map(fn (array $entry) => [
            'area' => $entry['area'] ?? '',
            'level' => $entry['level'] ?? 'intermediate',
            'years' => isset($entry['years']) ? (int) $entry['years'] : null,
        ], array_filter($entries, 'is_array')));
    }

    /**
     * @param  array<string, mixed>  $availability
     * @return array<string, mixed>
     */
    private function normalizeAvailability(array $availability): array
    {
        $weekly = array_values(array_filter($availability['weekly'] ?? [], 'is_array'));
        $periods = array_values(array_map(fn (array $period) => [
            'from' => $period['from'] ?? null,
            'to' => $period['to'] ?? null,
            'reason' => $period['reason'] ?? null,
        ], array_filter($availability['unavailable_periods'] ?? [], 'is_array')));

        return [
            'weekly' => $weekly,
            'unavailable_periods' => $periods,
        ];
    }

    /**
     * @param  array<int, mixed>  $entries
     * @return array<int, array<string, mixed>>
     */
    private function markSelfDeclaredEntries(array $entries): array
    {
        return array_values(array_map(fn (array $entry) => array_merge($entry, [
            'verification_status' => 'pending_verification',
        ]), array_filter($entries, 'is_array')));
    }

    /**
     * @param  array<int, mixed>  $entries
     * @return array<int, array<string, mixed>>
     */
    private function markVerifiedEntries(array $entries): array
    {
        return array_values(array_map(fn (array $entry) => array_merge($entry, [
            'verification_status' => 'verified',
            'verified_at' => now()->toIso8601String(),
        ]), array_filter($entries, 'is_array')));
    }

    /**
     * @param  array<int, array<string, mixed>>  $certifications
     * @return array<int, array<string, mixed>>
     */
    private function visibleCertifications(array $certifications, bool $isSelf, bool $canManage): array
    {
        return array_values(array_map(function (array $cert) use ($isSelf, $canManage) {
            if (! $isSelf && ! $canManage && ($cert['verification_status'] ?? '') !== 'verified') {
                $cert['name'] = '[Pending verification]';
                unset($cert['issuer'], $cert['expires_at']);
            }

            return $cert;
        }, $certifications));
    }

    /**
     * @param  array<int, array<string, mixed>>  $training
     * @return array<int, array<string, mixed>>
     */
    private function visibleTraining(array $training, bool $isSelf, bool $canManage): array
    {
        return array_values(array_map(function (array $entry) use ($isSelf, $canManage) {
            if (! $isSelf && ! $canManage && ($entry['verification_status'] ?? '') !== 'verified') {
                $entry['name'] = '[Pending verification]';
                unset($entry['provider'], $entry['completed_at']);
            }

            return $entry;
        }, $training));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function profileExpiringCertifications(VolunteerProfile $profile): array
    {
        $warningDays = (int) config('volunteer_profiles.certification_expiry_warning_days', 30);
        $today = now()->startOfDay();
        $warningUntil = $today->copy()->addDays($warningDays);
        $alerts = [];

        foreach ($profile->certifications ?? [] as $cert) {
            $expiresAt = ! empty($cert['expires_at']) ? Carbon::parse($cert['expires_at']) : null;
            if ($expiresAt !== null && $expiresAt->betweenIncluded($today, $warningUntil)) {
                $alerts[] = [
                    'name' => $cert['name'] ?? 'Certification',
                    'expires_at' => $expiresAt->toDateString(),
                ];
            }
        }

        return $alerts;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function profileUnavailableNow(VolunteerProfile $profile): array
    {
        $today = now()->startOfDay();
        $periods = [];

        foreach ($profile->availability['unavailable_periods'] ?? [] as $period) {
            $from = ! empty($period['from']) ? Carbon::parse($period['from']) : null;
            $to = ! empty($period['to']) ? Carbon::parse($period['to']) : null;
            if ($from !== null && $to !== null && $today->betweenIncluded($from, $to)) {
                $periods[] = $period;
            }
        }

        return $periods;
    }

    private function resolveMember(User $user): Member
    {
        $member = Member::query()->where('user_id', $user->id)->first();
        if ($member === null) {
            throw ValidationException::withMessages(['member' => ['No member profile is linked to your account.']]);
        }

        return $member;
    }

    private function assertMemberEligible(Member $member): void
    {
        if ($member->isArchived()) {
            throw ValidationException::withMessages(['member_id' => ['Archived members cannot have volunteer profiles.']]);
        }

        if (! in_array($member->lifecycle_status, config('volunteer_profiles.eligible_lifecycle_statuses', []), true)) {
            throw ValidationException::withMessages(['member_id' => ['Member is not eligible for a volunteer profile.']]);
        }
    }

    private function assertSelfAccess(User $actor, VolunteerProfile $profile): void
    {
        $member = $this->resolveMember($actor);
        if ((int) $profile->member_id !== (int) $member->id) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function assertCan(User $actor, string $action): void
    {
        if (! $this->authorization->allows($actor, $action)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function assertCanView(User $actor, VolunteerProfile $profile): void
    {
        if ($profile->member?->user_id === $actor->id) {
            return;
        }

        $this->assertCan($actor, 'volunteers.read');

        try {
            BranchScope::for($actor)->assertIncludes((int) $profile->branch_id);
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

    private function applyBranchScope(Builder $query, User $actor): void
    {
        if ($actor->isChurchWide()) {
            return;
        }

        try {
            $scope = BranchScope::for($actor);
            $query->whereIn('branch_id', $scope->branchIds());
        } catch (BranchScopeException) {
            $query->whereRaw('1 = 0');
        }
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function audit(User $actor, string $action, VolunteerProfile $profile, ?array $metadata = null): void
    {
        $this->audit->record(
            actor: $actor,
            action: $action,
            category: AuditEvent::CATEGORY_BUSINESS,
            module: 'volunteers',
            branchId: $profile->branch_id,
            subjectType: VolunteerProfile::class,
            subjectId: $profile->id,
            before: null,
            after: array_filter([
                'member_id' => $profile->member_id,
                'status' => $profile->status,
                'metadata' => $metadata,
            ]),
        );
    }
}
