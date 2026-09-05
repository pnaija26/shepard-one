<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Member;
use App\Models\MemberDuplicateReview;
use App\Models\MemberProfileHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Story 2.1: register and maintain member profiles with scope, duplicates, and audit.
 */
class MemberService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
        private MemberLifecycleService $lifecycle,
        private MemberDuplicateService $duplicates,
        private OnboardingJourneyService $onboarding,
    ) {
    }

    /**
     * @return Collection<int, Member>
     */
    public function listFor(User $actor, array $filters = []): Collection
    {
        $this->assertCan($actor, 'members.read');

        $query = Member::query()
            ->with('branch:id,name')
            ->whereNull('merged_into_id')
            ->orderBy('last_name')
            ->orderBy('first_name');

        $this->applyBranchScope($query, $actor);

        if (! empty($filters['status'])) {
            $query->where('membership_status', $filters['status']);
        }

        if (! empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $query->where(function (Builder $q) use ($term): void {
                $q->where('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('membership_id', 'like', $term);
            });
        }

        return $query->get();
    }

    public function findFor(User $actor, int $memberId): Member
    {
        $this->assertCan($actor, 'members.read');

        $member = Member::with(['branch:id,name', 'history.actor:id,name'])
            ->findOrFail($memberId);

        $this->assertMemberInScope($actor, $member);

        return $member;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function register(User $actor, array $payload, bool $force = false): Member
    {
        $this->assertCan($actor, 'members.write', (int) $payload['branch_id']);

        $validated = $this->validateRegistration($payload);
        $validated = $this->filterWritablePayload($actor, $validated, (int) $validated['branch_id']);
        $matches = $this->duplicates->findMatchesForPayload($validated);

        if ($matches->isNotEmpty() && ! $force) {
            foreach ($matches as $match) {
                MemberDuplicateReview::create([
                    'matched_member_id' => $match['member']->id,
                    'confidence' => $match['confidence'],
                    'match_reason' => $match['reason'],
                    'submitted_payload' => $validated,
                    'status' => MemberDuplicateReview::STATUS_PENDING,
                ]);
            }

            throw new MemberDuplicateException(
                $matches->pluck('member')->all(),
                $validated,
            );
        }

        return DB::transaction(function () use ($actor, $validated) {
            $member = Member::create(array_merge($validated, [
                'membership_id' => $this->generateMembershipId(),
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]));

            $this->recordHistory($member, $actor, 'created', null, $this->snapshot($member));
            $this->auditMemberEvent($actor, 'member.created', $member);
            $this->lifecycle->recordInitialState($member, $actor);
            $this->duplicates->scanAndFlagMember($member, 'registration');
            $this->onboarding->handleEvent('member.registered', $member, $actor);

            return $member->fresh(['branch:id,name']);
        });
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function update(User $actor, Member $member, array $changes): Member
    {
        $this->assertCan($actor, 'members.write', $member->branch_id);
        $this->assertMemberInScope($actor, $member);

        if ($member->isArchived()) {
            throw ValidationException::withMessages([
                'member' => ['Archived members cannot be updated.'],
            ]);
        }

        $allowed = $this->writableFieldsFor($actor, $member->branch_id);
        $filtered = array_intersect_key($changes, array_flip($allowed));

        if ($filtered === []) {
            throw ValidationException::withMessages([
                'member' => ['No permitted fields were supplied for update.'],
            ]);
        }

        $before = $this->snapshot($member);
        $member->fill($filtered);
        $member->updated_by = $actor->id;
        $member->save();

        $after = $this->snapshot($member);
        $diffBefore = [];
        $diffAfter = [];
        foreach ($filtered as $key => $_value) {
            if (($before[$key] ?? null) != ($after[$key] ?? null)) {
                $diffBefore[$key] = $before[$key] ?? null;
                $diffAfter[$key] = $after[$key] ?? null;
            }
        }

        if ($diffAfter !== []) {
            $this->recordHistory($member, $actor, 'updated', $diffBefore, $diffAfter);
            $this->auditMemberEvent($actor, 'member.updated', $member, $diffBefore, $diffAfter);
            $this->duplicates->scanAndFlagMember($member->fresh(), 'profile_update');
        }

        return $member->fresh(['branch:id,name', 'history.actor:id,name']);
    }

    public function archive(User $actor, Member $member, ?string $reason = null): Member
    {
        $this->assertCan($actor, 'members.archive', $member->branch_id);
        $this->assertMemberInScope($actor, $member);

        $before = $this->snapshot($member);

        $member->update([
            'membership_status' => 'archived',
            'archived_at' => now(),
            'updated_by' => $actor->id,
        ]);

        $after = $this->snapshot($member->fresh());
        $this->recordHistory($member, $actor, 'archived', $before, $after);
        $this->auditMemberEvent($actor, 'member.archived', $member, $before, $after, ['reason' => $reason]);

        return $member->fresh(['branch:id,name', 'history.actor:id,name']);
    }

    public function formatForViewer(Member $member, User $actor): array
    {
        $data = [
            'id' => $member->id,
            'membership_id' => $member->membership_id,
            'branch_id' => $member->branch_id,
            'branch' => $member->branch ? [
                'id' => $member->branch->id,
                'name' => $member->branch->name,
            ] : null,
            'registration_channel' => $member->registration_channel,
            'first_name' => $member->first_name,
            'last_name' => $member->last_name,
            'preferred_name' => $member->preferred_name,
            'full_name' => $member->fullName(),
            'email' => $member->email,
            'phone' => $member->phone,
            'date_of_birth' => $member->date_of_birth?->format('Y-m-d'),
            'gender' => $member->gender,
            'address_line1' => $member->address_line1,
            'address_line2' => $member->address_line2,
            'city' => $member->city,
            'state' => $member->state,
            'postal_code' => $member->postal_code,
            'country' => $member->country,
            'occupation' => $member->occupation,
            'photo_path' => $member->photo_path,
            'emergency_contact' => $member->emergency_contact,
            'membership_status' => $member->membership_status,
            'lifecycle_stage' => $member->lifecycle_stage,
            'lifecycle_status' => $member->lifecycle_status,
            'lifecycle_policy' => $member->lifecycle_policy,
            'consent_data_processing' => $member->consent_data_processing,
            'consent_directory' => $member->consent_directory,
            'archived_at' => $member->archived_at?->toIso8601String(),
            'merged_into_id' => $member->merged_into_id,
            'merged_at' => $member->merged_at?->toIso8601String(),
            'created_at' => $member->created_at?->toIso8601String(),
            'updated_at' => $member->updated_at?->toIso8601String(),
        ];

        if ($this->authorization->allows($actor, 'members.preferences', $member->branch_id)) {
            $data['spiritual_gifts'] = $member->spiritual_gifts;
            $data['skills'] = $member->skills;
            $data['ministry_interests'] = $member->ministry_interests;
            $data['communication_preferences'] = $member->communication_preferences;
        }

        if ($this->authorization->allows($actor, 'members.sensitive', $member->branch_id)) {
            $data['restricted_summaries'] = $member->restricted_summaries;
        }

        if ($member->relationLoaded('history')) {
            $data['history'] = $member->history->map(fn (MemberProfileHistory $row) => [
                'id' => $row->id,
                'action' => $row->action,
                'actor' => $row->actor ? ['id' => $row->actor->id, 'name' => $row->actor->name] : null,
                'before_values' => $row->before_values,
                'after_values' => $row->after_values,
                'created_at' => $row->created_at?->toIso8601String(),
            ])->values();
        }

        return $data;
    }

    /**
     * @param  Member[]  $matches
     */
    public function formatDuplicateResponse(array $matches, array $preservedInput): array
    {
        return [
            'message' => 'Potential duplicate members found. Review required before creating a new record.',
            'duplicate_review_required' => true,
            'preserved_input' => $preservedInput,
            'potential_matches' => collect($matches)->map(fn (Member $m) => [
                'id' => $m->id,
                'membership_id' => $m->membership_id,
                'full_name' => $m->fullName(),
                'email' => $m->email,
                'phone' => $m->phone,
                'branch_id' => $m->branch_id,
            ])->values(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateRegistration(array $payload): array
    {
        $validator = validator($payload, [
            'branch_id' => ['required', 'integer', 'exists:organizations,id'],
            'registration_channel' => ['required', 'string', 'in:' . implode(',', config('members.registration_channels'))],
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'preferred_name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:32'],
            'date_of_birth' => ['nullable', 'date'],
            'gender' => ['nullable', 'string', 'max:32'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:32'],
            'country' => ['nullable', 'string', 'max:64'],
            'consent_data_processing' => ['required', 'accepted'],
            'consent_directory' => ['boolean'],
            'spiritual_gifts' => ['nullable', 'array'],
            'skills' => ['nullable', 'array'],
            'ministry_interests' => ['nullable', 'array'],
            'communication_preferences' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }

    private function generateMembershipId(): string
    {
        $prefix = config('members.membership_id_prefix', 'S1-M-');

        do {
            $sequence = Member::lockForUpdate()->count() + 1;
            $id = $prefix . str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
        } while (
            Member::where('membership_id', $id)->exists()
            || $this->duplicates->isMembershipIdRetired($id)
        );

        return $id;
    }

    /** @param  array<string, mixed>  $payload */
    private function filterWritablePayload(User $actor, array $payload, int $branchId): array
    {
        $allowed = $this->writableFieldsFor($actor, $branchId);
        $allowed[] = 'branch_id';
        $allowed[] = 'registration_channel';

        return array_intersect_key($payload, array_flip(array_unique($allowed)));
    }

    /** @return string[] */
    private function writableFieldsFor(User $actor, ?int $branchId = null): array
    {
        $fields = config('members.writable_fields.basic', []);

        if ($this->authorization->allows($actor, 'members.preferences', $branchId)) {
            $fields = array_merge($fields, config('members.writable_fields.preferences', []));
        }

        if ($this->authorization->allows($actor, 'members.sensitive', $branchId)) {
            $fields[] = 'restricted_summaries';
        }

        return array_values(array_unique($fields));
    }

    /** @return array<string, mixed> */
    private function snapshot(Member $member): array
    {
        return $member->only([
            'membership_id', 'branch_id', 'first_name', 'last_name', 'preferred_name',
            'email', 'phone', 'date_of_birth', 'gender', 'membership_status',
            'consent_data_processing', 'consent_directory', 'archived_at',
        ]);
    }

    private function recordHistory(
        Member $member,
        User $actor,
        string $action,
        ?array $before,
        ?array $after,
    ): void {
        MemberProfileHistory::create([
            'member_id' => $member->id,
            'actor_id' => $actor->id,
            'action' => $action,
            'before_values' => $before,
            'after_values' => $after,
            'created_at' => now(),
        ]);
    }

    private function auditMemberEvent(
        User $actor,
        string $action,
        Member $member,
        ?array $before = null,
        ?array $after = null,
        array $metadata = [],
    ): void {
        $this->audit->record(
            actor: $actor,
            action: $action,
            category: AuditEvent::CATEGORY_BUSINESS,
            module: 'members',
            branchId: $member->branch_id,
            subjectType: Member::class,
            subjectId: $member->id,
            before: $before,
            after: $after,
            metadata: $metadata,
        );
    }

    private function assertCan(User $actor, string $action, ?int $branchId = null): void
    {
        if (! $this->authorization->allows($actor, $action, $branchId)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function assertMemberInScope(User $actor, Member $member): void
    {
        if ($actor->isChurchWide()) {
            return;
        }

        try {
            BranchScope::for($actor)->assertIncludes($member->branch_id);
        } catch (BranchScopeException) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    /** @param  Builder<Member>  $query */
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
