<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Household;
use App\Models\HouseholdMembership;
use App\Models\Member;
use App\Models\MemberDuplicateFlag;
use App\Models\MemberLifecycleHistory;
use App\Models\MemberLifecyclePendingTransition;
use App\Models\MemberMerge;
use App\Models\MemberNotification;
use App\Models\MemberProfileChangeRequest;
use App\Models\MemberProfileHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Story 2.5: duplicate detection, review flags, and safe member merges.
 */
class MemberDuplicateService
{
    /** @var string[] */
    private const MERGEABLE_FIELDS = [
        'first_name', 'last_name', 'preferred_name', 'email', 'phone', 'date_of_birth', 'gender',
        'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country',
        'occupation', 'photo_path', 'emergency_contact',
        'spiritual_gifts', 'skills', 'ministry_interests', 'communication_preferences',
        'restricted_summaries', 'consent_data_processing', 'consent_directory',
    ];

    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
    ) {
    }

    /**
     * @return Collection<int, array{member: Member, confidence: string, reason: string, signals: array<string, mixed>}>
     */
    public function findMatchesForPayload(array $data, ?int $excludeMemberId = null): Collection
    {
        $matches = collect();

        foreach (config('members.duplicate_rules', []) as $rule) {
            if (isset($rule['field'])) {
                $this->matchByField($matches, $data, $rule['field'], $rule['confidence'], $excludeMemberId);
            } elseif (isset($rule['fields'])) {
                $this->matchByFields($matches, $data, $rule['fields'], $rule['confidence'], $excludeMemberId);
            }
        }

        return $matches->unique(fn (array $row) => $row['member']->id)->values();
    }

    /**
     * @return Collection<int, array{member: Member, confidence: string, reason: string, signals: array<string, mixed>}>
     */
    public function findMatchesForMember(Member $member): Collection
    {
        if ($member->merged_into_id !== null) {
            return collect();
        }

        $payload = $member->only([
            'first_name', 'last_name', 'email', 'phone', 'date_of_birth', 'membership_id',
        ]);

        $matches = $this->findMatchesForPayload($payload, $member->id);

        $householdMatch = $this->findHouseholdFamilyMatch($member);
        if ($householdMatch !== null) {
            $exists = $matches->contains(fn (array $row) => $row['member']->id === $householdMatch['member']->id);
            if (! $exists) {
                $matches->push($householdMatch);
            }
        }

        return $matches->values();
    }

    /**
     * @return Collection<int, MemberDuplicateFlag>
     */
    public function listFlags(User $actor, string $status = MemberDuplicateFlag::STATUS_PENDING): Collection
    {
        $this->assertCan($actor, 'members.duplicates.review');

        $query = MemberDuplicateFlag::query()
            ->with(['memberA.branch:id,name', 'memberB.branch:id,name'])
            ->where('status', $status)
            ->orderByDesc('created_at');

        $this->applyMemberScope($query, $actor, 'member_a_id');
        $this->applyMemberScope($query, $actor, 'member_b_id');

        return $query->get();
    }

    public function compare(User $actor, MemberDuplicateFlag $flag): array
    {
        $this->assertCan($actor, 'members.duplicates.review');
        $this->assertFlagInScope($actor, $flag);

        $memberA = $flag->memberA;
        $memberB = $flag->memberB;

        return [
            'flag' => $this->formatFlag($flag),
            'member_a' => $this->formatMemberForCompare($memberA),
            'member_b' => $this->formatMemberForCompare($memberB),
            'mergeable_fields' => self::MERGEABLE_FIELDS,
            'conflicts' => $this->detectConflicts($memberA, $memberB),
        ];
    }

    /**
     * @return MemberDuplicateFlag[]
     */
    public function scanAndFlagMember(Member $member, string $source = 'scan'): array
    {
        if ($member->merged_into_id !== null) {
            return [];
        }

        $created = [];

        foreach ($this->findMatchesForMember($member) as $match) {
            $flag = $this->createOrRefreshFlag(
                $member,
                $match['member'],
                $match['confidence'],
                $match['reason'],
                $match['signals'] ?? [],
                $source,
            );

            if ($flag !== null) {
                $created[] = $flag;
            }
        }

        return $created;
    }

    public function dismissFlag(User $actor, MemberDuplicateFlag $flag): MemberDuplicateFlag
    {
        $this->assertCan($actor, 'members.duplicates.review');
        $this->assertFlagInScope($actor, $flag);

        $flag->update([
            'status' => MemberDuplicateFlag::STATUS_DISMISSED,
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
        ]);

        $this->audit->record(
            actor: $actor,
            action: 'member.duplicate.dismissed',
            category: AuditEvent::CATEGORY_BUSINESS,
            module: 'members',
            branchId: $flag->memberA->branch_id,
            subjectType: MemberDuplicateFlag::class,
            subjectId: $flag->id,
            metadata: [
                'member_a_id' => $flag->member_a_id,
                'member_b_id' => $flag->member_b_id,
            ],
        );

        return $flag->fresh(['memberA', 'memberB']);
    }

    /**
     * @param  array<string, 'survivor'|'merged'>  $fieldResolutions
     */
    public function merge(
        User $actor,
        int $survivorId,
        int $mergedId,
        array $fieldResolutions,
        ?int $flagId = null,
    ): Member {
        $this->assertCan($actor, 'members.duplicates.merge');

        if ($survivorId === $mergedId) {
            throw ValidationException::withMessages([
                'merged_member_id' => ['Survivor and merged member must be different records.'],
            ]);
        }

        $survivor = Member::findOrFail($survivorId);
        $merged = Member::findOrFail($mergedId);

        $this->assertMemberInScope($actor, $survivor);
        $this->assertMemberInScope($actor, $merged);

        if ($survivor->merged_into_id !== null || $merged->merged_into_id !== null) {
            throw ValidationException::withMessages([
                'member' => ['One or both records have already been merged.'],
            ]);
        }

        $conflicts = $this->detectConflicts($survivor, $merged);
        if ($conflicts !== []) {
            throw new MemberMergeConflictException(
                'Merge blocked due to conflicting records.',
                $conflicts,
            );
        }

        $resolvedFields = $this->resolveFieldValues($survivor, $merged, $fieldResolutions);

        return DB::transaction(function () use ($actor, $survivor, $merged, $resolvedFields, $fieldResolutions, $flagId) {
            $retiredMembershipId = $merged->membership_id;

            $this->relinkHistory($survivor, $merged);
            $this->relinkHouseholds($survivor, $merged);

            $survivor->fill($resolvedFields);
            $survivor->updated_by = $actor->id;
            $survivor->save();

            $merged->update([
                'merged_into_id' => $survivor->id,
                'merged_at' => now(),
                'membership_status' => 'archived',
                'archived_at' => now(),
                'updated_by' => $actor->id,
            ]);

            MemberMerge::create([
                'survivor_id' => $survivor->id,
                'merged_member_id' => $merged->id,
                'retired_membership_id' => $retiredMembershipId,
                'field_resolutions' => $fieldResolutions,
                'merged_by' => $actor->id,
                'created_at' => now(),
            ]);

            if ($flagId !== null) {
                MemberDuplicateFlag::where('id', $flagId)->update([
                    'status' => MemberDuplicateFlag::STATUS_MERGED,
                    'reviewed_by' => $actor->id,
                    'reviewed_at' => now(),
                ]);
            }

            MemberDuplicateFlag::query()
                ->where('status', MemberDuplicateFlag::STATUS_PENDING)
                ->where(function (Builder $q) use ($merged): void {
                    $q->where('member_a_id', $merged->id)
                        ->orWhere('member_b_id', $merged->id);
                })
                ->update([
                    'status' => MemberDuplicateFlag::STATUS_MERGED,
                    'reviewed_by' => $actor->id,
                    'reviewed_at' => now(),
                ]);

            MemberProfileHistory::create([
                'member_id' => $survivor->id,
                'actor_id' => $actor->id,
                'action' => 'merged',
                'before_values' => ['merged_member_id' => $merged->id],
                'after_values' => [
                    'retired_membership_id' => $retiredMembershipId,
                    'field_resolutions' => $fieldResolutions,
                ],
                'created_at' => now(),
            ]);

            $this->audit->record(
                actor: $actor,
                action: 'member.merged',
                category: AuditEvent::CATEGORY_BUSINESS,
                module: 'members',
                branchId: $survivor->branch_id,
                subjectType: Member::class,
                subjectId: $survivor->id,
                metadata: [
                    'merged_member_id' => $merged->id,
                    'retired_membership_id' => $retiredMembershipId,
                ],
            );

            return $survivor->fresh(['branch:id,name']);
        });
    }

    public function isMembershipIdRetired(string $membershipId): bool
    {
        return MemberMerge::where('retired_membership_id', $membershipId)->exists();
    }

    /**
     * @param  Collection<int, array{member: Member, confidence: string, reason: string, signals?: array<string, mixed>}>  $matches
     */
    private function matchByField(
        Collection $matches,
        array $data,
        string $field,
        string $confidence,
        ?int $excludeMemberId,
    ): void {
        $value = $data[$field] ?? null;
        if ($value === null || $value === '') {
            return;
        }

        if ($field === 'phone') {
            $normalized = $this->normalizePhone((string) $value);
            $candidates = Member::query()
                ->whereNotNull('phone')
                ->whereNull('merged_into_id')
                ->whereNull('archived_at')
                ->when($excludeMemberId, fn (Builder $q) => $q->where('id', '!=', $excludeMemberId))
                ->get()
                ->filter(fn (Member $m) => $this->normalizePhone((string) $m->phone) === $normalized);

            foreach ($candidates as $member) {
                $matches->push([
                    'member' => $member,
                    'confidence' => $confidence,
                    'reason' => $field,
                    'signals' => ['phone' => $value],
                ]);
            }

            return;
        }

        $query = Member::query()
            ->where($field, $value)
            ->whereNull('merged_into_id')
            ->whereNull('archived_at');

        if ($excludeMemberId !== null) {
            $query->where('id', '!=', $excludeMemberId);
        }

        foreach ($query->get() as $member) {
            $matches->push([
                'member' => $member,
                'confidence' => $confidence,
                'reason' => $field,
                'signals' => [$field => $value],
            ]);
        }
    }

    /**
     * @param  string[]  $fields
     */
    private function matchByFields(
        Collection $matches,
        array $data,
        array $fields,
        string $confidence,
        ?int $excludeMemberId,
    ): void {
        foreach ($fields as $field) {
            if (empty($data[$field])) {
                return;
            }
        }

        $query = Member::query()
            ->whereNull('merged_into_id')
            ->whereNull('archived_at');

        foreach ($fields as $field) {
            if ($field === 'date_of_birth') {
                $query->whereDate('date_of_birth', $data[$field]);
            } else {
                $query->where($field, $data[$field]);
            }
        }

        if ($excludeMemberId !== null) {
            $query->where('id', '!=', $excludeMemberId);
        }

        $reason = implode('_and_', $fields);

        foreach ($query->get() as $member) {
            $matches->push([
                'member' => $member,
                'confidence' => $confidence,
                'reason' => $reason,
                'signals' => array_intersect_key($data, array_flip($fields)),
            ]);
        }
    }

    /**
     * @return array{member: Member, confidence: string, reason: string, signals: array<string, mixed>}|null
     */
    private function findHouseholdFamilyMatch(Member $member): ?array
    {
        $membership = HouseholdMembership::activeForMember($member->id);
        if ($membership === null) {
            return null;
        }

        $siblings = HouseholdMembership::query()
            ->where('household_id', $membership->household_id)
            ->whereNull('ended_at')
            ->where('member_id', '!=', $member->id)
            ->with('member')
            ->get();

        foreach ($siblings as $row) {
            $other = $row->member;
            if ($other === null || $other->merged_into_id !== null) {
                continue;
            }

            $sameName = $other->first_name === $member->first_name
                && $other->last_name === $member->last_name;
            $sameContact = ($member->email && $other->email === $member->email)
                || ($member->phone && $this->normalizePhone((string) $other->phone) === $this->normalizePhone((string) $member->phone));

            if ($sameName || $sameContact) {
                return [
                    'member' => $other,
                    'confidence' => 'medium',
                    'reason' => 'household_family',
                    'signals' => ['household_id' => $membership->household_id],
                ];
            }
        }

        return null;
    }

    private function createOrRefreshFlag(
        Member $left,
        Member $right,
        string $confidence,
        string $reason,
        array $signals,
        string $source,
    ): ?MemberDuplicateFlag {
        [$memberAId, $memberBId] = MemberDuplicateFlag::pairIds($left->id, $right->id);

        $existing = MemberDuplicateFlag::query()
            ->where('member_a_id', $memberAId)
            ->where('member_b_id', $memberBId)
            ->first();

        if ($existing !== null) {
            if ($existing->status === MemberDuplicateFlag::STATUS_PENDING) {
                $existing->update([
                    'confidence' => $confidence,
                    'match_reason' => $reason,
                    'match_signals' => $signals,
                    'source' => $source,
                ]);

                return $existing->fresh();
            }

            return null;
        }

        return MemberDuplicateFlag::create([
            'member_a_id' => $memberAId,
            'member_b_id' => $memberBId,
            'confidence' => $confidence,
            'match_reason' => $reason,
            'match_signals' => $signals,
            'source' => $source,
            'status' => MemberDuplicateFlag::STATUS_PENDING,
        ]);
    }

    /**
     * @return array<int, array{type: string, message: string}>
     */
    private function detectConflicts(Member $survivor, Member $merged): array
    {
        $conflicts = [];

        if ($survivor->user_id !== null && $merged->user_id !== null && $survivor->user_id !== $merged->user_id) {
            $conflicts[] = [
                'type' => 'linked_accounts',
                'message' => 'Both records are linked to different platform accounts.',
            ];
        }

        $survivorHousehold = HouseholdMembership::activeForMember($survivor->id);
        $mergedHousehold = HouseholdMembership::activeForMember($merged->id);

        if (
            $survivorHousehold !== null
            && $mergedHousehold !== null
            && $survivorHousehold->household_id !== $mergedHousehold->household_id
        ) {
            $conflicts[] = [
                'type' => 'household',
                'message' => 'Both records belong to different active households.',
            ];
        }

        $restrictedConflicts = $this->restrictedSummaryConflicts($survivor, $merged);
        foreach ($restrictedConflicts as $key) {
            $conflicts[] = [
                'type' => 'restricted_summary',
                'message' => "Conflicting restricted summary for \"{$key}\".",
            ];
        }

        return $conflicts;
    }

    /**
     * @return string[]
     */
    private function restrictedSummaryConflicts(Member $survivor, Member $merged): array
    {
        $a = $survivor->restricted_summaries ?? [];
        $b = $merged->restricted_summaries ?? [];
        $keys = array_unique(array_merge(array_keys($a), array_keys($b)));
        $conflicts = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $a) && array_key_exists($key, $b) && $a[$key] != $b[$key]) {
                $conflicts[] = $key;
            }
        }

        return $conflicts;
    }

    /**
     * @param  array<string, 'survivor'|'merged'>  $fieldResolutions
     * @return array<string, mixed>
     */
    private function resolveFieldValues(Member $survivor, Member $merged, array $fieldResolutions): array
    {
        $resolved = [];

        foreach (self::MERGEABLE_FIELDS as $field) {
            $choice = $fieldResolutions[$field] ?? 'survivor';
            $source = $choice === 'merged' ? $merged : $survivor;
            $resolved[$field] = $source->{$field};
        }

        if ($survivor->user_id === null && $merged->user_id !== null) {
            $resolved['user_id'] = $merged->user_id;
            $merged->user_id = null;
            $merged->save();
        }

        return $resolved;
    }

    private function relinkHistory(Member $survivor, Member $merged): void
    {
        MemberProfileHistory::where('member_id', $merged->id)->update(['member_id' => $survivor->id]);
        MemberLifecycleHistory::where('member_id', $merged->id)->update(['member_id' => $survivor->id]);
        MemberProfileChangeRequest::where('member_id', $merged->id)->update(['member_id' => $survivor->id]);
        MemberNotification::where('member_id', $merged->id)->update(['member_id' => $survivor->id]);
        MemberLifecyclePendingTransition::where('member_id', $merged->id)->update(['member_id' => $survivor->id]);
    }

    private function relinkHouseholds(Member $survivor, Member $merged): void
    {
        $survivorMembership = HouseholdMembership::activeForMember($survivor->id);
        $mergedMembership = HouseholdMembership::activeForMember($merged->id);

        if ($mergedMembership === null) {
            return;
        }

        if ($survivorMembership === null) {
            $mergedMembership->update(['member_id' => $survivor->id]);

            Household::where('head_member_id', $merged->id)->update(['head_member_id' => $survivor->id]);

            return;
        }

        if ($survivorMembership->household_id === $mergedMembership->household_id) {
            $mergedMembership->update(['ended_at' => now()]);

            return;
        }
    }

    private function formatFlag(MemberDuplicateFlag $flag): array
    {
        return [
            'id' => $flag->id,
            'member_a_id' => $flag->member_a_id,
            'member_b_id' => $flag->member_b_id,
            'confidence' => $flag->confidence,
            'match_reason' => $flag->match_reason,
            'match_signals' => $flag->match_signals,
            'source' => $flag->source,
            'status' => $flag->status,
            'created_at' => $flag->created_at?->toIso8601String(),
        ];
    }

    private function formatMemberForCompare(Member $member): array
    {
        return [
            'id' => $member->id,
            'membership_id' => $member->membership_id,
            'full_name' => $member->fullName(),
            'branch' => $member->branch ? ['id' => $member->branch->id, 'name' => $member->branch->name] : null,
            'fields' => collect(self::MERGEABLE_FIELDS)
                ->mapWithKeys(fn (string $field) => [$field => $member->{$field}])
                ->all(),
        ];
    }

    private function assertCan(User $actor, string $action): void
    {
        if (! $this->authorization->allows($actor, $action)) {
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

    private function assertFlagInScope(User $actor, MemberDuplicateFlag $flag): void
    {
        $flag->loadMissing(['memberA', 'memberB']);
        $this->assertMemberInScope($actor, $flag->memberA);
        $this->assertMemberInScope($actor, $flag->memberB);
    }

    /** @param  Builder<MemberDuplicateFlag>  $query */
    private function applyMemberScope(Builder $query, User $actor, string $column): void
    {
        if ($actor->isChurchWide()) {
            return;
        }

        try {
            $scope = BranchScope::for($actor);
            $branchIds = $scope->subtreeIds((int) $scope->branchId());
            $query->whereHas($column === 'member_a_id' ? 'memberA' : 'memberB', function (Builder $q) use ($branchIds): void {
                $q->whereIn('branch_id', $branchIds);
            });
        } catch (BranchScopeException) {
            $query->whereRaw('1 = 0');
        }
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }
}
