<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Member;
use App\Models\ServiceTeam;
use App\Models\ServiceTeamAssignment;
use App\Models\TeamRosterSlot;
use App\Models\User;
use App\Models\VolunteerProfile;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Story 5.9: ranked volunteer recommendations for open duties.
 */
class VolunteerRecommendationService
{
    public function __construct(
        private AuthorizationService $authorization,
        private ServiceTeamAssignmentService $assignments,
        private AuditService $audit,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function recommend(User $actor, ServiceTeam $team, array $payload): array
    {
        $this->assertCan($actor, 'volunteers.recommend');
        $this->assertTeamInScope($actor, $team);

        $duty = $this->validateDutyPayload($payload, $team);
        $profiles = $this->candidateProfiles($team);

        $recommendations = [];
        foreach ($profiles as $profile) {
            $member = $profile->member;
            if ($member === null) {
                continue;
            }

            $recommendations[] = $this->scoreCandidate($team, $profile, $member, $duty);
        }

        usort($recommendations, function (array $left, array $right): int {
            if ($left['eligible'] !== $right['eligible']) {
                return $left['eligible'] ? -1 : 1;
            }

            return $right['score'] <=> $left['score'];
        });

        $recommendations = array_slice($recommendations, 0, (int) config('volunteer_recommendations.max_results', 20));
        foreach ($recommendations as $index => &$entry) {
            $entry['rank'] = $index + 1;
        }
        unset($entry);

        $limitations = [];
        if ($recommendations === []) {
            $limitations[] = config('volunteer_recommendations.limitation_messages.no_candidates');
        } elseif (! collect($recommendations)->contains(fn (array $item) => $item['eligible'])) {
            $limitations[] = config('volunteer_recommendations.limitation_messages.no_candidates');
        }

        return [
            'duty' => $duty,
            'recommendations' => $recommendations,
            'limitations' => $limitations,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function confirmRecommendation(User $actor, ServiceTeam $team, array $payload): ServiceTeamAssignment
    {
        $this->assertCan($actor, 'teams.assignments.manage');
        $this->assertTeamInScope($actor, $team);

        $duty = $this->validateDutyPayload($payload, $team);

        $assignmentPayload = validator($payload, [
            'member_id' => ['required', 'integer', 'exists:members,id'],
            'team_role' => ['nullable', 'string', 'in:' . implode(',', config('team_assignments.roles', []))],
            'sub_team' => ['nullable', 'string', 'max:120'],
            'override' => ['nullable', 'boolean'],
            'override_reason' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:500'],
        ])->validate();

        $member = Member::query()->findOrFail($assignmentPayload['member_id']);
        $profile = VolunteerProfile::query()->where('member_id', $member->id)->first();

        if ($profile === null) {
            throw ValidationException::withMessages(['member_id' => ['A volunteer profile is required before assignment.']]);
        }

        $preview = $this->scoreCandidate($team, $profile, $member, $duty);
        if (! $preview['eligible'] && ! ($assignmentPayload['override'] ?? false)) {
            throw ValidationException::withMessages([
                'member_id' => [$preview['limitations'][0] ?? 'This volunteer cannot be safely assigned without override.'],
                'conflicts' => $preview['conflicts'],
            ]);
        }

        $assignment = $this->assignments->assignMember($actor, $team, [
            'member_id' => $member->id,
            'team_role' => $assignmentPayload['team_role'] ?? 'member',
            'sub_team' => $assignmentPayload['sub_team'] ?? null,
            'shift_label' => $duty['shift_label'],
            'responsibilities' => [$duty['duty_label']],
            'effective_from' => $duty['shift_date'],
            'effective_to' => $duty['shift_date'],
            'override' => $assignmentPayload['override'] ?? false,
            'override_reason' => $assignmentPayload['override_reason'] ?? null,
            'notes' => $assignmentPayload['notes'] ?? null,
        ]);

        $this->audit($actor, 'volunteer_recommendation.confirmed', $team, $member, $duty);

        return $assignment;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateDutyPayload(array $payload, ServiceTeam $team): array
    {
        $validated = validator($payload, [
            'duty_label' => ['required', 'string', 'max:160'],
            'shift_label' => ['nullable', 'string', 'max:120'],
            'shift_date' => ['required', 'date'],
            'shift_start' => ['nullable', 'date_format:H:i'],
            'shift_end' => ['nullable', 'date_format:H:i'],
            'day_of_week' => ['nullable', 'string', 'max:16'],
            'required_skills' => ['nullable', 'array'],
            'required_skills.*' => ['string', 'max:80'],
            'required_training' => ['nullable', 'array'],
            'required_training.*' => ['string', 'max:120'],
        ])->validate();

        $shiftDate = Carbon::parse($validated['shift_date']);

        return [
            'duty_label' => $validated['duty_label'],
            'shift_label' => $validated['shift_label'] ?? $validated['duty_label'],
            'shift_date' => $shiftDate->toDateString(),
            'shift_start' => $validated['shift_start'] ?? null,
            'shift_end' => $validated['shift_end'] ?? null,
            'day_of_week' => strtolower($validated['day_of_week'] ?? $shiftDate->format('l')),
            'required_skills' => array_values($validated['required_skills'] ?? $team->required_skills ?? []),
            'required_training' => array_values($validated['required_training'] ?? []),
            'branch_id' => $team->branch_id,
        ];
    }

    /**
     * @return Collection<int, VolunteerProfile>
     */
    private function candidateProfiles(ServiceTeam $team): Collection
    {
        return VolunteerProfile::query()
            ->with(['member:id,first_name,last_name,membership_id,lifecycle_status,branch_id,skills'])
            ->where('branch_id', $team->branch_id)
            ->where('status', VolunteerProfile::STATUS_ACTIVE)
            ->orderByDesc('updated_at')
            ->limit(200)
            ->get();
    }

    /**
     * @param  array<string, mixed>  $duty
     * @return array<string, mixed>
     */
    private function scoreCandidate(ServiceTeam $team, VolunteerProfile $profile, Member $member, array $duty): array
    {
        $skills = array_values(array_unique(array_merge($profile->skills ?? [], $member->skills ?? [])));
        $requiredSkills = array_values(array_unique(array_merge(
            $duty['required_skills'],
            $team->required_skills ?? [],
        )));
        $skillsMatched = array_values(array_intersect($requiredSkills, $skills));
        $skillsMissing = array_values(array_diff($requiredSkills, $skills));

        $trainingMatched = [];
        $trainingMissing = [];
        foreach ($duty['required_training'] as $trainingName) {
            if ($this->hasVerifiedTraining($profile, $trainingName)) {
                $trainingMatched[] = $trainingName;
            } else {
                $trainingMissing[] = $trainingName;
            }
        }

        $availabilityMatch = $this->matchesAvailability($profile, $duty);
        $unavailable = $this->isUnavailable($profile, $duty['shift_date']);
        $stale = $this->isProfileStale($profile);
        $rosterConflict = $this->hasRosterConflict($member, $duty);

        $assignmentPayload = [
            'shift_label' => $duty['shift_label'],
            'effective_from' => $duty['shift_date'],
        ];
        $conflicts = $this->assignments->previewConflicts($team, $member, $assignmentPayload);

        $score = 0;
        $reasons = [];

        $score += count($skillsMatched) * (int) config('volunteer_recommendations.scores.skill_match', 25);
        if ($skillsMatched !== []) {
            $reasons[] = 'Matched skills: ' . implode(', ', $skillsMatched);
        }

        $score += count($trainingMatched) * (int) config('volunteer_recommendations.scores.training_match', 20);
        if ($trainingMatched !== []) {
            $reasons[] = 'Verified training: ' . implode(', ', $trainingMatched);
        }

        if ($availabilityMatch) {
            $score += (int) config('volunteer_recommendations.scores.availability_match', 25);
            $reasons[] = 'Available on ' . ucfirst($duty['day_of_week']);
        }

        $years = collect($profile->expertise ?? [])->max('years') ?? 0;
        if ($years >= 2) {
            $score += (int) config('volunteer_recommendations.scores.experience_bonus', 10);
            $reasons[] = 'Relevant service experience recorded';
        }

        $hoursBonus = min((float) $profile->volunteer_hours, (int) config('volunteer_recommendations.scores.hours_bonus_cap', 10));
        $score += (int) $hoursBonus;

        $limitations = [];
        if ($skillsMissing !== []) {
            $score -= count($skillsMissing) * (int) config('volunteer_recommendations.penalties.missing_skill', 15);
            $limitations[] = config('volunteer_recommendations.limitation_messages.missing_skills');
        }

        if ($trainingMissing !== []) {
            $score -= count($trainingMissing) * (int) config('volunteer_recommendations.penalties.missing_training', 20);
            $limitations[] = config('volunteer_recommendations.limitation_messages.missing_training');
        }

        if ($unavailable) {
            $score -= (int) config('volunteer_recommendations.penalties.unavailable', 40);
            $limitations[] = config('volunteer_recommendations.limitation_messages.unavailable');
        }

        if ($rosterConflict || in_array('shift_conflict', $conflicts, true)) {
            $score -= (int) config('volunteer_recommendations.penalties.scheduling_conflict', 35);
            $limitations[] = config('volunteer_recommendations.limitation_messages.scheduling_conflict');
        }

        if ($stale) {
            $score -= (int) config('volunteer_recommendations.penalties.stale_profile', 10);
            $limitations[] = config('volunteer_recommendations.limitation_messages.stale_profile');
        }

        foreach ($conflicts as $conflict) {
            if ($conflict === 'missing_skills' && $skillsMissing === []) {
                continue;
            }

            $limitations[] = $this->conflictLimitation($conflict);
        }

        $blocking = in_array('ineligible_member', $conflicts, true)
            || in_array('branch_mismatch', $conflicts, true)
            || $unavailable
            || $skillsMissing !== []
            || $trainingMissing !== []
            || $rosterConflict
            || in_array('shift_conflict', $conflicts, true)
            || in_array('duplicate_assignment', $conflicts, true);

        $requiresOverride = $conflicts !== [] && ! $blocking;

        return [
            'member_id' => $member->id,
            'volunteer_profile_id' => $profile->id,
            'display_name' => $this->publicMemberLabel($member),
            'membership_id' => $member->membership_id,
            'score' => max(0, $score),
            'eligible' => ! $blocking && ! $requiresOverride,
            'requires_override' => $requiresOverride || ($blocking && $conflicts !== []),
            'reasons' => $reasons,
            'limitations' => array_values(array_unique($limitations)),
            'conflicts' => $conflicts,
            'match' => [
                'skills_matched' => $skillsMatched,
                'skills_missing' => $skillsMissing,
                'training_matched' => $trainingMatched,
                'training_missing' => $trainingMissing,
                'availability_match' => $availabilityMatch,
                'profile_stale' => $stale,
                'roster_conflict' => $rosterConflict,
            ],
        ];
    }

    private function hasVerifiedTraining(VolunteerProfile $profile, string $trainingName): bool
    {
        foreach ($profile->training ?? [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $name = strtolower((string) ($entry['name'] ?? ''));
            $status = $entry['verification_status'] ?? 'self_declared';

            if ($name === strtolower($trainingName) && $status === 'verified') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $duty
     */
    private function matchesAvailability(VolunteerProfile $profile, array $duty): bool
    {
        $weekly = $profile->availability['weekly'] ?? [];
        $day = strtolower($duty['day_of_week']);

        foreach ($weekly as $slot) {
            if (! is_array($slot)) {
                continue;
            }

            if (strtolower((string) ($slot['day'] ?? '')) !== $day) {
                continue;
            }

            if ($duty['shift_start'] === null || $duty['shift_end'] === null) {
                return true;
            }

            $start = (string) ($slot['start'] ?? '');
            $end = (string) ($slot['end'] ?? '');

            if ($start !== '' && $end !== '' && $start <= $duty['shift_start'] && $end >= $duty['shift_end']) {
                return true;
            }
        }

        return false;
    }

    private function isUnavailable(VolunteerProfile $profile, string $shiftDate): bool
    {
        $target = Carbon::parse($shiftDate)->startOfDay();

        foreach ($profile->availability['unavailable_periods'] ?? [] as $period) {
            if (! is_array($period)) {
                continue;
            }

            $from = ! empty($period['from']) ? Carbon::parse($period['from'])->startOfDay() : null;
            $to = ! empty($period['to']) ? Carbon::parse($period['to'])->endOfDay() : null;

            if ($from !== null && $to !== null && $target->between($from, $to)) {
                return true;
            }
        }

        return false;
    }

    private function isProfileStale(VolunteerProfile $profile): bool
    {
        $staleDays = (int) config('volunteer_recommendations.profile_stale_days', 180);

        return $profile->updated_at === null
            || $profile->updated_at->lt(now()->subDays($staleDays));
    }

    /**
     * @param  array<string, mixed>  $duty
     */
    private function hasRosterConflict(Member $member, array $duty): bool
    {
        return TeamRosterSlot::query()
            ->where('member_id', $member->id)
            ->whereDate('shift_date', $duty['shift_date'])
            ->whereHas('roster', fn (Builder $q) => $q->where('status', 'published'))
            ->whereNotIn('status', ['cancelled', 'substituted'])
            ->exists();
    }

    private function publicMemberLabel(Member $member): string
    {
        $lastInitial = $member->last_name !== null && $member->last_name !== ''
            ? mb_substr($member->last_name, 0, 1) . '.'
            : '';

        return trim($member->first_name . ' ' . $lastInitial);
    }

    private function conflictLimitation(string $conflict): string
    {
        return match ($conflict) {
            'ineligible_member' => config('volunteer_recommendations.limitation_messages.ineligible_member'),
            'shift_conflict', 'duplicate_assignment' => config('volunteer_recommendations.limitation_messages.scheduling_conflict'),
            'missing_skills' => config('volunteer_recommendations.limitation_messages.missing_skills'),
            default => 'Assignment safeguards flagged this volunteer.',
        };
    }

    private function assertCan(User $actor, string $action): void
    {
        if (! $this->authorization->allows($actor, $action)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function assertTeamInScope(User $actor, ServiceTeam $team): void
    {
        if ($actor->isChurchWide()) {
            return;
        }

        try {
            BranchScope::for($actor)->assertIncludes((int) $team->branch_id);
        } catch (BranchScopeException) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    /**
     * @param  array<string, mixed>  $duty
     */
    private function audit(User $actor, string $action, ServiceTeam $team, Member $member, array $duty): void
    {
        $this->audit->record(
            actor: $actor,
            action: $action,
            category: AuditEvent::CATEGORY_BUSINESS,
            module: 'volunteers',
            branchId: $team->branch_id,
            subjectType: ServiceTeam::class,
            subjectId: $team->id,
            before: null,
            after: [
                'member_id' => $member->id,
                'duty_label' => $duty['duty_label'],
                'shift_date' => $duty['shift_date'],
            ],
        );
    }
}
