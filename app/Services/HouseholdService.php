<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Household;
use App\Models\HouseholdMembership;
use App\Models\HouseholdRelationshipHistory;
use App\Models\Member;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Story 2.3: organize members into households with relationship governance.
 */
class HouseholdService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
        private MemberService $members,
    ) {
    }

    /**
     * @return Collection<int, Household>
     */
    public function listFor(User $actor): Collection
    {
        $this->assertCan($actor, 'households.read');

        $query = Household::query()->with(['branch:id,name', 'headMember:id,first_name,last_name'])
            ->orderBy('name');

        $this->applyBranchScope($query, $actor);

        return $query->get();
    }

    public function findFor(User $actor, int $householdId): Household
    {
        $this->assertCan($actor, 'households.read');

        $household = Household::with([
            'branch:id,name',
            'headMember',
            'activeMemberships.member.branch:id,name',
            'history.actor:id,name',
        ])->findOrFail($householdId);

        $this->assertHouseholdInScope($actor, $household);

        return $household;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(User $actor, array $payload): Household
    {
        $this->assertCan($actor, 'households.manage', (int) $payload['branch_id']);

        $validated = validator($payload, [
            'name' => ['required', 'string', 'max:191'],
            'branch_id' => ['required', 'integer', 'exists:organizations,id'],
            'members' => ['required', 'array', 'min:1'],
            'members.*.member_id' => ['required', 'integer', 'exists:members,id'],
            'members.*.relationship_type' => ['required', 'string', 'in:' . implode(',', config('households.relationship_types'))],
            'shared_phone' => ['nullable', 'string', 'max:32'],
            'shared_email' => ['nullable', 'email', 'max:191'],
            'shared_address' => ['nullable', 'array'],
        ])->validate();

        return DB::transaction(function () use ($actor, $validated) {
            $household = Household::create([
                'name' => $validated['name'],
                'branch_id' => $validated['branch_id'],
                'shared_phone' => $validated['shared_phone'] ?? null,
                'shared_email' => $validated['shared_email'] ?? null,
                'shared_address' => $validated['shared_address'] ?? null,
                'created_by' => $actor->id,
            ]);

            foreach ($validated['members'] as $row) {
                $this->addMember(
                    $actor,
                    $household,
                    (int) $row['member_id'],
                    (string) $row['relationship_type'],
                    recordHistory: false,
                );
            }

            $head = $household->activeMemberships()->where('relationship_type', HouseholdMembership::TYPE_HEAD)->first();
            if ($head !== null) {
                $household->update(['head_member_id' => $head->member_id]);
            }

            $this->recordHistory($household, null, $actor, 'household.created', null, [
                'member_count' => count($validated['members']),
            ]);
            $this->audit($actor, 'household.created', $household);

            return $household->fresh(['branch', 'headMember', 'activeMemberships.member']);
        });
    }

    public function addMember(
        User $actor,
        Household $household,
        int $memberId,
        string $relationshipType,
        bool $recordHistory = true,
    ): HouseholdMembership {
        $this->assertCan($actor, 'households.manage', $household->branch_id);
        $this->assertHouseholdInScope($actor, $household);

        $member = Member::findOrFail($memberId);
        $this->assertMemberBranchMatches($member, $household);

        $this->assertRelationshipAllowed($household, $memberId, $relationshipType);

        $membership = HouseholdMembership::create([
            'household_id' => $household->id,
            'member_id' => $memberId,
            'relationship_type' => $relationshipType,
            'started_at' => now(),
            'created_by' => $actor->id,
        ]);

        if ($relationshipType === HouseholdMembership::TYPE_HEAD) {
            $household->update(['head_member_id' => $memberId]);
        }

        if ($recordHistory) {
            $this->recordHistory($household, $member, $actor, 'member.added', $relationshipType);
            $this->audit($actor, 'household.member.added', $household, null, [
                'member_id' => $memberId,
                'relationship_type' => $relationshipType,
            ]);
        }

        return $membership;
    }

    public function changeRelationship(
        User $actor,
        Household $household,
        Member $member,
        string $newRelationshipType,
    ): HouseholdMembership {
        $this->assertCan($actor, 'households.manage', $household->branch_id);
        $this->assertHouseholdInScope($actor, $household);

        $membership = $this->activeMembership($household, $member->id);
        $previous = $membership->relationship_type;

        if ($previous === $newRelationshipType) {
            throw new HouseholdConflictException('Member already holds this relationship in the household.');
        }

        if ($previous === HouseholdMembership::TYPE_HEAD) {
            $household->update(['head_member_id' => null]);
        }

        $this->assertRelationshipAllowed($household, $member->id, $newRelationshipType, $membership->id);

        $membership->update(['relationship_type' => $newRelationshipType]);

        if ($newRelationshipType === HouseholdMembership::TYPE_HEAD) {
            $household->update(['head_member_id' => $member->id]);
        }

        $this->recordHistory($household, $member, $actor, 'relationship.changed', $newRelationshipType, [
            'previous_relationship_type' => $previous,
        ]);
        $this->audit($actor, 'household.relationship.changed', $household, null, [
            'member_id' => $member->id,
            'from' => $previous,
            'to' => $newRelationshipType,
        ]);

        return $membership->fresh();
    }

    public function removeMember(User $actor, Household $household, Member $member, ?string $reason = null): void
    {
        $this->assertCan($actor, 'households.manage', $household->branch_id);
        $this->assertHouseholdInScope($actor, $household);

        $membership = $this->activeMembership($household, $member->id);
        $previous = $membership->relationship_type;

        $membership->update(['ended_at' => now()]);

        if ($household->head_member_id === $member->id) {
            $household->update(['head_member_id' => null]);
        }

        $this->recordHistory($household, $member, $actor, 'member.removed', $previous, [
            'reason' => $reason,
        ]);
        $this->audit($actor, 'household.member.removed', $household, null, [
            'member_id' => $member->id,
            'relationship_type' => $previous,
            'reason' => $reason,
        ]);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function updateHousehold(User $actor, Household $household, array $changes, bool $confirmOverwrite = false): Household
    {
        $this->assertCan($actor, 'households.manage', $household->branch_id);
        $this->assertHouseholdInScope($actor, $household);

        $allowed = ['name', 'shared_phone', 'shared_email', 'shared_address'];
        $filtered = array_intersect_key($changes, array_flip($allowed));

        if (isset($filtered['shared_phone']) || isset($filtered['shared_email']) || isset($filtered['shared_address'])) {
            $conflicts = $this->detectContactConflicts($household, $filtered);
            if ($conflicts !== [] && ! $confirmOverwrite) {
                throw new HouseholdContactOverwriteException($conflicts);
            }
        }

        $before = $household->only($allowed);
        $household->fill($filtered);
        $household->save();

        if ($confirmOverwrite && isset($filtered['shared_phone'])) {
            $this->propagateSharedField($household, 'phone', $filtered['shared_phone']);
        }
        if ($confirmOverwrite && isset($filtered['shared_email'])) {
            $this->propagateSharedField($household, 'email', $filtered['shared_email']);
        }

        $this->recordHistory($household, null, $actor, 'household.updated', null, [
            'before' => $before,
            'after' => $household->only($allowed),
            'confirm_overwrite' => $confirmOverwrite,
        ]);
        $this->audit($actor, 'household.updated', $household, $before, $household->only($allowed));

        return $household->fresh(['branch', 'headMember', 'activeMemberships.member']);
    }

    public function formatForViewer(Household $household, User $actor): array
    {
        $canSensitive = $this->authorization->allows($actor, 'members.sensitive', $household->branch_id);

        $members = $household->activeMemberships->map(function (HouseholdMembership $membership) use ($actor, $canSensitive) {
            $member = $membership->member;
            $contact = [
                'email' => $member->email,
                'phone' => $member->phone,
                'address_line1' => $member->address_line1,
                'city' => $member->city,
            ];

            if (! $canSensitive && $member->restricted_summaries) {
                unset($contact['email'], $contact['phone']);
            }

            return [
                'member_id' => $member->id,
                'membership_id' => $member->membership_id,
                'full_name' => $member->fullName(),
                'relationship_type' => $membership->relationship_type,
                'started_at' => $membership->started_at?->toIso8601String(),
                'contact' => $contact,
            ];
        })->values();

        $data = [
            'id' => $household->id,
            'name' => $household->name,
            'branch_id' => $household->branch_id,
            'branch' => $household->branch ? ['id' => $household->branch->id, 'name' => $household->branch->name] : null,
            'head_member_id' => $household->head_member_id,
            'shared_phone' => $household->shared_phone,
            'shared_email' => $household->shared_email,
            'shared_address' => $household->shared_address,
            'members' => $members,
            'milestones' => $household->milestones ?? [],
            'created_at' => $household->created_at?->toIso8601String(),
        ];

        if ($canSensitive) {
            $data['attendance_summary'] = $household->attendance_summary ?? [];
            $data['events_summary'] = $household->events_summary ?? [];
            $data['teams_summary'] = $household->teams_summary ?? [];
            $data['welfare_references'] = $household->welfare_references ?? [];
        }

        if ($household->relationLoaded('history')) {
            $data['history'] = $household->history->map(fn (HouseholdRelationshipHistory $row) => [
                'id' => $row->id,
                'action' => $row->action,
                'member_id' => $row->member_id,
                'relationship_type' => $row->relationship_type,
                'previous_relationship_type' => $row->previous_relationship_type,
                'actor' => $row->actor ? ['id' => $row->actor->id, 'name' => $row->actor->name] : null,
                'detail' => $row->detail,
                'created_at' => $row->created_at?->toIso8601String(),
            ])->values();
        }

        return $data;
    }

    private function assertRelationshipAllowed(
        Household $household,
        int $memberId,
        string $relationshipType,
        ?int $ignoreMembershipId = null,
    ): void {
        if (! in_array($relationshipType, config('households.relationship_types', []), true)) {
            throw ValidationException::withMessages([
                'relationship_type' => ['Invalid relationship type.'],
            ]);
        }

        $existingElsewhere = HouseholdMembership::query()
            ->where('member_id', $memberId)
            ->whereNull('ended_at')
            ->when($ignoreMembershipId, fn (Builder $q) => $q->where('id', '!=', $ignoreMembershipId))
            ->where('household_id', '!=', $household->id)
            ->exists();

        if ($existingElsewhere) {
            throw new HouseholdConflictException('Member already belongs to another active household.');
        }

        $duplicate = $household->activeMemberships()
            ->where('member_id', $memberId)
            ->when($ignoreMembershipId, fn (Builder $q) => $q->where('id', '!=', $ignoreMembershipId))
            ->exists();

        if ($duplicate) {
            throw new HouseholdConflictException('Member already has an active relationship in this household.');
        }

        if (in_array($relationshipType, config('households.singular_roles', []), true)) {
            $roleTaken = $household->activeMemberships()
                ->where('relationship_type', $relationshipType)
                ->when($ignoreMembershipId, fn (Builder $q) => $q->where('id', '!=', $ignoreMembershipId))
                ->exists();

            if ($roleTaken) {
                throw new HouseholdConflictException("This household already has an active {$relationshipType}.");
            }
        }

        if ($relationshipType === HouseholdMembership::TYPE_CHILD) {
            $headId = $household->head_member_id;
            if ($headId !== null && $headId === $memberId) {
                throw new HouseholdConflictException('Household head cannot also be recorded as a child.');
            }
        }
    }

    private function activeMembership(Household $household, int $memberId): HouseholdMembership
    {
        $membership = $household->activeMemberships()->where('member_id', $memberId)->first();

        if ($membership === null) {
            throw new HouseholdConflictException('Member is not actively linked to this household.');
        }

        return $membership;
    }

    private function assertMemberBranchMatches(Member $member, Household $household): void
    {
        if ((int) $member->branch_id !== (int) $household->branch_id) {
            throw new HouseholdConflictException('Member branch must match the household branch.');
        }
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<int, array<string, mixed>>
     */
    private function detectContactConflicts(Household $household, array $changes): array
    {
        $conflicts = [];
        $memberIds = $household->activeMemberships()->pluck('member_id');

        foreach (Member::whereIn('id', $memberIds)->get() as $member) {
            if (isset($changes['shared_phone']) && $member->phone && $member->phone !== $changes['shared_phone']) {
                $conflicts[] = ['member_id' => $member->id, 'field' => 'phone', 'current' => $member->phone];
            }
            if (isset($changes['shared_email']) && $member->email && $member->email !== $changes['shared_email']) {
                $conflicts[] = ['member_id' => $member->id, 'field' => 'email', 'current' => $member->email];
            }
        }

        return $conflicts;
    }

    private function propagateSharedField(Household $household, string $field, mixed $value): void
    {
        $memberIds = $household->activeMemberships()->pluck('member_id');
        Member::whereIn('id', $memberIds)->update([$field => $value]);
    }

    private function recordHistory(
        Household $household,
        ?Member $member,
        User $actor,
        string $action,
        ?string $relationshipType,
        array $detail = [],
    ): void {
        HouseholdRelationshipHistory::create([
            'household_id' => $household->id,
            'member_id' => $member?->id,
            'action' => $action,
            'relationship_type' => $relationshipType,
            'previous_relationship_type' => $detail['previous_relationship_type'] ?? null,
            'actor_id' => $actor->id,
            'detail' => $detail ?: null,
            'created_at' => now(),
        ]);
    }

    private function audit(
        User $actor,
        string $action,
        Household $household,
        ?array $before = null,
        ?array $after = null,
    ): void {
        $this->audit->record(
            actor: $actor,
            action: $action,
            category: AuditEvent::CATEGORY_BUSINESS,
            module: 'households',
            branchId: $household->branch_id,
            subjectType: Household::class,
            subjectId: $household->id,
            before: $before,
            after: $after,
        );
    }

    private function assertCan(User $actor, string $action, ?int $branchId = null): void
    {
        if (! $this->authorization->allows($actor, $action, $branchId)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function assertHouseholdInScope(User $actor, Household $household): void
    {
        if ($actor->isChurchWide()) {
            return;
        }

        try {
            BranchScope::for($actor)->assertIncludes($household->branch_id);
        } catch (BranchScopeException) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    /** @param  Builder<Household>  $query */
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
