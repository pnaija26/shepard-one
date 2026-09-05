<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\ChurchGroup;
use App\Models\ChurchGroupJoinRequest;
use App\Models\ChurchGroupMembership;
use App\Models\ChurchGroupMembershipHistory;
use App\Models\Member;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Story 6.1: create and organize church groups with governed membership.
 */
class ChurchGroupService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
    ) {
    }

    /**
     * @return Collection<int, ChurchGroup>
     */
    public function listGroups(User $actor, array $filters = []): Collection
    {
        $this->assertCan($actor, 'groups.read');

        $query = ChurchGroup::query()
            ->with('branch:id,name')
            ->withCount(['activeMemberships as active_member_count'])
            ->orderBy('name');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['group_type'])) {
            $query->where('group_type', $filters['group_type']);
        }

        $this->applyBranchScope($query, $actor);

        return $query->limit(200)->get();
    }

    public function showGroup(User $actor, ChurchGroup $group): ChurchGroup
    {
        $this->assertCan($actor, 'groups.read');
        $this->assertGroupInScope($actor, $group);

        return $group->load([
            'branch:id,name',
            'activeMemberships.member:id,first_name,last_name,membership_id,lifecycle_status',
            'joinRequests' => fn ($q) => $q->where('status', ChurchGroupJoinRequest::STATUS_PENDING)->with('member:id,first_name,last_name'),
            'history' => fn ($q) => $q->orderByDesc('created_at')->limit(30),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createGroup(User $actor, array $payload): ChurchGroup
    {
        $this->assertCan($actor, 'groups.manage');

        $validated = $this->validateGroupPayload($payload);
        $this->assertBranchWritable($actor, (int) $validated['branch_id']);
        $this->assertLeadersValid($validated['leaders'], (int) $validated['branch_id']);

        return DB::transaction(function () use ($actor, $validated): ChurchGroup {
            $group = ChurchGroup::create([
                ...$this->mapGroupAttributes($validated),
                'status' => ChurchGroup::STATUS_DRAFT,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->recordHistory($group, null, $actor, 'group.created', null, ['name' => $group->name]);
            $this->audit($actor, 'church_group.created', $group);

            return $group->fresh(['branch:id,name']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateGroup(User $actor, ChurchGroup $group, array $payload): ChurchGroup
    {
        $this->assertCan($actor, 'groups.manage');
        $this->assertGroupInScope($actor, $group);

        if ($group->status === ChurchGroup::STATUS_ARCHIVED) {
            throw ValidationException::withMessages(['group' => ['Archived groups cannot be edited.']]);
        }

        $validated = $this->validateGroupPayload($payload, $group->id);
        $this->assertBranchWritable($actor, (int) $validated['branch_id']);
        $this->assertLeadersValid($validated['leaders'], (int) $validated['branch_id']);

        return DB::transaction(function () use ($actor, $group, $validated): ChurchGroup {
            $group->update([
                ...$this->mapGroupAttributes($validated),
                'updated_by' => $actor->id,
            ]);

            $this->recordHistory($group, null, $actor, 'group.updated');
            $this->audit($actor, 'church_group.updated', $group);

            return $group->fresh(['branch:id,name']);
        });
    }

    public function activateGroup(User $actor, ChurchGroup $group): ChurchGroup
    {
        $this->assertCan($actor, 'groups.manage');
        $this->assertGroupInScope($actor, $group);

        if ($group->leaders === []) {
            throw ValidationException::withMessages(['leaders' => ['At least one leader is required before activation.']]);
        }

        $group->update([
            'status' => ChurchGroup::STATUS_ACTIVE,
            'updated_by' => $actor->id,
        ]);

        $this->recordHistory($group, null, $actor, 'group.activated');
        $this->audit($actor, 'church_group.activated', $group);

        return $group->fresh(['branch:id,name']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function assignMember(User $actor, ChurchGroup $group, array $payload): ChurchGroupMembership
    {
        $this->assertCan($actor, 'groups.members.manage');
        $this->assertGroupInScope($actor, $group);
        $this->assertGroupAssignable($group);

        $validated = validator($payload, [
            'member_id' => ['required', 'integer', 'exists:members,id'],
            'role' => ['required', 'string', 'in:' . implode(',', config('church_groups.membership_roles', []))],
            'effective_from' => ['nullable', 'date'],
        ])->validate();

        $member = Member::query()->findOrFail($validated['member_id']);
        $this->assertMemberEligible($group, $member);
        $this->assertCapacityAvailable($group);

        if ($this->hasActiveMembership($group, $member->id)) {
            throw ValidationException::withMessages(['member_id' => ['Member already belongs to this group.']]);
        }

        return DB::transaction(function () use ($actor, $group, $member, $validated): ChurchGroupMembership {
            $membership = ChurchGroupMembership::create([
                'church_group_id' => $group->id,
                'member_id' => $member->id,
                'role' => $validated['role'],
                'status' => ChurchGroupMembership::STATUS_ACTIVE,
                'effective_from' => $validated['effective_from'] ?? now()->toDateString(),
                'assigned_by' => $actor->id,
                'approved_by' => $actor->id,
                'approved_at' => now(),
            ]);

            $this->recordHistory($group, $member, $actor, 'member.assigned', $validated['role']);
            $this->audit($actor, 'church_group.member.assigned', $group, $member);

            return $membership->load('member:id,first_name,last_name,membership_id');
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function transferMember(User $actor, ChurchGroup $group, ChurchGroupMembership $membership, array $payload): ChurchGroupMembership
    {
        $this->assertCan($actor, 'groups.members.manage');
        $this->assertGroupInScope($actor, $group);

        if ($membership->church_group_id !== $group->id) {
            throw ValidationException::withMessages(['membership' => ['Membership does not belong to this group.']]);
        }

        if (! in_array($membership->status, [ChurchGroupMembership::STATUS_ACTIVE, ChurchGroupMembership::STATUS_PENDING], true)) {
            throw ValidationException::withMessages(['membership' => ['Only active memberships can be transferred.']]);
        }

        $validated = validator($payload, [
            'target_group_id' => ['required', 'integer', 'exists:church_groups,id'],
            'role' => ['nullable', 'string', 'in:' . implode(',', config('church_groups.membership_roles', []))],
            'effective_from' => ['nullable', 'date'],
        ])->validate();

        $target = ChurchGroup::query()->findOrFail($validated['target_group_id']);
        $this->assertGroupInScope($actor, $target);
        $this->assertGroupAssignable($target);

        if ((int) $target->branch_id !== (int) $group->branch_id) {
            throw ValidationException::withMessages(['target_group_id' => ['Transfers must remain within the same branch.']]);
        }

        $member = Member::query()->findOrFail($membership->member_id);
        $this->assertMemberEligible($target, $member);
        $this->assertCapacityAvailable($target, $membership->id);

        return DB::transaction(function () use ($actor, $group, $target, $membership, $member, $validated): ChurchGroupMembership {
            $membership->update([
                'status' => ChurchGroupMembership::STATUS_TRANSFERRED,
                'effective_to' => now()->toDateString(),
                'transfer_to_group_id' => $target->id,
                'removed_at' => now(),
            ]);

            $this->recordHistory($group, $member, $actor, 'member.transferred', $membership->role, [
                'target_group_id' => $target->id,
            ]);

            $newMembership = ChurchGroupMembership::create([
                'church_group_id' => $target->id,
                'member_id' => $member->id,
                'role' => $validated['role'] ?? ChurchGroupMembership::ROLE_MEMBER,
                'status' => ChurchGroupMembership::STATUS_ACTIVE,
                'effective_from' => $validated['effective_from'] ?? now()->toDateString(),
                'assigned_by' => $actor->id,
                'approved_by' => $actor->id,
                'approved_at' => now(),
            ]);

            $this->recordHistory($target, $member, $actor, 'member.transferred_in', $newMembership->role, [
                'from_group_id' => $group->id,
            ]);
            $this->audit($actor, 'church_group.member.transferred', $group, $member, ['target_group_id' => $target->id]);

            return $newMembership->load('member:id,first_name,last_name,membership_id');
        });
    }

    public function removeMember(User $actor, ChurchGroup $group, ChurchGroupMembership $membership, array $payload = []): ChurchGroupMembership
    {
        $this->assertCan($actor, 'groups.members.manage');
        $this->assertGroupInScope($actor, $group);

        if ($membership->church_group_id !== $group->id) {
            throw ValidationException::withMessages(['membership' => ['Membership does not belong to this group.']]);
        }

        $validated = validator($payload, [
            'reason' => ['nullable', 'string', 'max:500'],
        ])->validate();

        return DB::transaction(function () use ($actor, $group, $membership, $validated): ChurchGroupMembership {
            $member = Member::query()->findOrFail($membership->member_id);

            $membership->update([
                'status' => ChurchGroupMembership::STATUS_REMOVED,
                'effective_to' => now()->toDateString(),
                'removed_at' => now(),
            ]);

            $this->recordHistory($group, $member, $actor, 'member.removed', $membership->role, [
                'reason' => $validated['reason'] ?? null,
            ]);
            $this->audit($actor, 'church_group.member.removed', $group, $member);

            return $membership->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function submitJoinRequest(User $actor, ChurchGroup $group, array $payload): ChurchGroupJoinRequest
    {
        $this->assertCan($actor, 'groups.join_requests.submit');
        $this->assertGroupInScope($actor, $group);
        $this->assertGroupAssignable($group);

        $validated = validator($payload, [
            'member_id' => ['required', 'integer', 'exists:members,id'],
            'message' => ['nullable', 'string', 'max:1000'],
        ])->validate();

        $member = Member::query()->findOrFail($validated['member_id']);
        $this->assertMemberEligible($group, $member);

        if ($this->hasActiveMembership($group, $member->id)) {
            throw ValidationException::withMessages(['member_id' => ['Member is already in this group.']]);
        }

        $pending = ChurchGroupJoinRequest::query()
            ->where('church_group_id', $group->id)
            ->where('member_id', $member->id)
            ->where('status', ChurchGroupJoinRequest::STATUS_PENDING)
            ->exists();

        if ($pending) {
            throw ValidationException::withMessages(['member_id' => ['A join request is already pending for this member.']]);
        }

        return ChurchGroupJoinRequest::create([
            'church_group_id' => $group->id,
            'member_id' => $member->id,
            'status' => ChurchGroupJoinRequest::STATUS_PENDING,
            'message' => $validated['message'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function reviewJoinRequest(User $actor, ChurchGroupJoinRequest $request, array $payload): array
    {
        $this->assertCan($actor, 'groups.join_requests.review');
        $group = $request->group ?? ChurchGroup::query()->findOrFail($request->church_group_id);
        $this->assertGroupInScope($actor, $group);

        if ($request->status !== ChurchGroupJoinRequest::STATUS_PENDING) {
            throw ValidationException::withMessages(['status' => ['Only pending join requests can be reviewed.']]);
        }

        $validated = validator($payload, [
            'decision' => ['required', 'string', 'in:approved,rejected'],
            'role' => ['nullable', 'string', 'in:' . implode(',', config('church_groups.membership_roles', []))],
            'review_notes' => ['nullable', 'string', 'max:1000'],
        ])->validate();

        if ($validated['decision'] === 'rejected' && empty($validated['review_notes'])) {
            throw ValidationException::withMessages(['review_notes' => ['Review notes are required when rejecting a join request.']]);
        }

        $member = Member::query()->findOrFail($request->member_id);

        return DB::transaction(function () use ($actor, $request, $group, $member, $validated): array {
            $request->update([
                'status' => $validated['decision'] === 'approved'
                    ? ChurchGroupJoinRequest::STATUS_APPROVED
                    : ChurchGroupJoinRequest::STATUS_REJECTED,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'review_notes' => $validated['review_notes'] ?? null,
            ]);

            if ($validated['decision'] === 'rejected') {
                $this->recordHistory($group, $member, $actor, 'join_request.rejected', null, [
                    'join_request_id' => $request->id,
                ]);

                return [
                    'decision' => 'rejected',
                    'join_request' => $request->fresh(),
                ];
            }

            $this->assertCapacityAvailable($group);

            $membership = ChurchGroupMembership::create([
                'church_group_id' => $group->id,
                'member_id' => $member->id,
                'role' => $validated['role'] ?? ChurchGroupMembership::ROLE_MEMBER,
                'status' => ChurchGroupMembership::STATUS_ACTIVE,
                'effective_from' => now()->toDateString(),
                'join_request_id' => $request->id,
                'assigned_by' => $actor->id,
                'approved_by' => $actor->id,
                'approved_at' => now(),
            ]);

            $this->recordHistory($group, $member, $actor, 'join_request.approved', $membership->role, [
                'join_request_id' => $request->id,
            ]);
            $this->audit($actor, 'church_group.join_request.approved', $group, $member);

            return [
                'decision' => 'approved',
                'membership' => $membership->load('member:id,first_name,last_name,membership_id'),
            ];
        });
    }

    public function formatGroup(ChurchGroup $group): array
    {
        return [
            'id' => $group->id,
            'name' => $group->name,
            'group_type' => $group->group_type,
            'description' => $group->description,
            'status' => $group->status,
            'branch_id' => $group->branch_id,
            'leaders' => $group->leaders ?? [],
            'meeting_pattern' => $group->meeting_pattern ?? [],
            'capacity' => $group->capacity,
            'eligibility' => $group->eligibility ?? config('church_groups.default_eligibility'),
            'communication_settings' => $group->communication_settings ?? [],
            'reporting_settings' => $group->reporting_settings ?? [],
            'active_member_count' => $group->active_member_count
                ?? $group->activeMemberships()->count(),
            'branch' => $group->relationLoaded('branch') && $group->branch
                ? ['id' => $group->branch->id, 'name' => $group->branch->name]
                : null,
            'memberships' => $group->relationLoaded('activeMemberships')
                ? $group->activeMemberships->map(fn (ChurchGroupMembership $membership) => $this->formatMembership($membership))->values()->all()
                : [],
            'pending_join_requests' => $group->relationLoaded('joinRequests')
                ? $group->joinRequests->map(fn (ChurchGroupJoinRequest $request) => [
                    'id' => $request->id,
                    'member_id' => $request->member_id,
                    'member_name' => $request->member?->fullName(),
                    'message' => $request->message,
                    'status' => $request->status,
                ])->values()->all()
                : [],
            'history' => $group->relationLoaded('history')
                ? $group->history->map(fn (ChurchGroupMembershipHistory $entry) => [
                    'id' => $entry->id,
                    'member_id' => $entry->member_id,
                    'change_type' => $entry->change_type,
                    'role' => $entry->role,
                    'metadata' => $entry->metadata,
                    'created_at' => $entry->created_at?->toIso8601String(),
                ])->values()->all()
                : [],
        ];
    }

    public function formatMembership(ChurchGroupMembership $membership): array
    {
        return [
            'id' => $membership->id,
            'member_id' => $membership->member_id,
            'role' => $membership->role,
            'status' => $membership->status,
            'effective_from' => $membership->effective_from?->toDateString(),
            'effective_to' => $membership->effective_to?->toDateString(),
            'member' => $membership->relationLoaded('member') && $membership->member
                ? [
                    'id' => $membership->member->id,
                    'full_name' => $membership->member->fullName(),
                    'membership_id' => $membership->member->membership_id,
                ]
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateGroupPayload(array $payload, ?int $groupId = null): array
    {
        return validator($payload, [
            'name' => ['required', 'string', 'max:160'],
            'branch_id' => ['required', 'integer', 'exists:organizations,id'],
            'group_type' => ['required', 'string', 'in:' . implode(',', config('church_groups.types', []))],
            'description' => ['nullable', 'string', 'max:2000'],
            'leaders' => ['required', 'array', 'min:1'],
            'leaders.*.user_id' => ['required', 'integer', 'exists:users,id'],
            'leaders.*.role' => ['required', 'string', 'in:' . implode(',', config('church_groups.leader_roles', []))],
            'meeting_pattern' => ['required', 'array'],
            'meeting_pattern.frequency' => ['required', 'string', 'in:' . implode(',', config('church_groups.meeting_frequencies', []))],
            'meeting_pattern.day' => ['required', 'string', 'max:16'],
            'meeting_pattern.start_time' => ['required', 'date_format:H:i'],
            'meeting_pattern.end_time' => ['required', 'date_format:H:i', 'after:meeting_pattern.start_time'],
            'meeting_pattern.venue' => ['nullable', 'string', 'max:160'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'eligibility' => ['nullable', 'array'],
            'communication_settings' => ['nullable', 'array'],
            'reporting_settings' => ['nullable', 'array'],
        ])->validate();
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function mapGroupAttributes(array $validated): array
    {
        return [
            'branch_id' => $validated['branch_id'],
            'name' => $validated['name'],
            'group_type' => $validated['group_type'],
            'description' => $validated['description'] ?? null,
            'leaders' => $validated['leaders'],
            'meeting_pattern' => $validated['meeting_pattern'],
            'capacity' => $validated['capacity'] ?? null,
            'eligibility' => array_merge(
                config('church_groups.default_eligibility', []),
                $validated['eligibility'] ?? [],
            ),
            'communication_settings' => $validated['communication_settings'] ?? [
                'allow_member_posts' => true,
                'notify_leaders_on_join_request' => true,
            ],
            'reporting_settings' => $validated['reporting_settings'] ?? [
                'requires_weekly_report' => false,
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $leaders
     */
    private function assertLeadersValid(array $leaders, int $branchId): void
    {
        $leaderIds = collect($leaders)->pluck('user_id')->unique();
        if ($leaderIds->count() !== count($leaders)) {
            throw ValidationException::withMessages(['leaders' => ['Duplicate leaders are not allowed.']]);
        }

        foreach ($leaderIds as $userId) {
            $user = User::query()->find($userId);
            if ($user === null) {
                throw ValidationException::withMessages(['leaders' => ['Leader account could not be found.']]);
            }

            if ($user->branch_id !== null && (int) $user->branch_id !== $branchId) {
                throw ValidationException::withMessages(['leaders' => ['Leaders must belong to the group branch scope.']]);
            }
        }
    }

    private function assertMemberEligible(ChurchGroup $group, Member $member): void
    {
        if ((int) $member->branch_id !== (int) $group->branch_id) {
            throw ValidationException::withMessages(['member_id' => ['Member must belong to the same branch as the group.']]);
        }

        if (! in_array($member->lifecycle_status, config('church_groups.eligible_lifecycle_statuses', []), true)) {
            throw ValidationException::withMessages(['member_id' => ['Member lifecycle status is not eligible for group membership.']]);
        }

        $eligibility = array_merge(config('church_groups.default_eligibility', []), $group->eligibility ?? []);

        if (($eligibility['requires_consent'] ?? false) && ! $member->consent_data_processing) {
            throw ValidationException::withMessages(['member_id' => ['Member has not provided required data-processing consent.']]);
        }

        if (($eligibility['requires_safeguarding_clearance'] ?? false)
            && ! in_array($member->lifecycle_stage, ['member', 'leader'], true)) {
            throw ValidationException::withMessages(['member_id' => ['Member does not meet safeguarding eligibility for this group.']]);
        }

        $stages = $eligibility['lifecycle_stages'] ?? [];
        if ($stages !== [] && ! in_array($member->lifecycle_stage, $stages, true)) {
            throw ValidationException::withMessages(['member_id' => ['Member lifecycle stage is not eligible for this group.']]);
        }

        if ($member->date_of_birth !== null) {
            $age = Carbon::parse($member->date_of_birth)->age;
            $minAge = $eligibility['min_age'] ?? null;
            $maxAge = $eligibility['max_age'] ?? null;

            if ($minAge !== null && $age < (int) $minAge) {
                throw ValidationException::withMessages(['member_id' => ['Member does not meet the minimum age requirement.']]);
            }

            if ($maxAge !== null && $age > (int) $maxAge) {
                throw ValidationException::withMessages(['member_id' => ['Member exceeds the maximum age requirement.']]);
            }
        }
    }

    private function assertCapacityAvailable(ChurchGroup $group, ?int $ignoreMembershipId = null): void
    {
        if ($group->capacity === null) {
            return;
        }

        $count = ChurchGroupMembership::query()
            ->where('church_group_id', $group->id)
            ->whereIn('status', [ChurchGroupMembership::STATUS_ACTIVE, ChurchGroupMembership::STATUS_PENDING])
            ->when($ignoreMembershipId, fn (Builder $q) => $q->where('id', '!=', $ignoreMembershipId))
            ->count();

        if ($count >= (int) $group->capacity) {
            throw ValidationException::withMessages(['capacity' => ['Group capacity has been reached.']]);
        }
    }

    private function hasActiveMembership(ChurchGroup $group, int $memberId): bool
    {
        return ChurchGroupMembership::query()
            ->where('church_group_id', $group->id)
            ->where('member_id', $memberId)
            ->whereIn('status', config('church_groups.active_membership_statuses', []))
            ->exists();
    }

    private function assertGroupAssignable(ChurchGroup $group): void
    {
        if ($group->status !== ChurchGroup::STATUS_ACTIVE) {
            throw ValidationException::withMessages(['group' => ['Membership changes are only allowed for active groups.']]);
        }
    }

    private function recordHistory(
        ChurchGroup $group,
        ?Member $member,
        User $actor,
        string $changeType,
        ?string $role = null,
        ?array $metadata = null,
    ): void {
        ChurchGroupMembershipHistory::create([
            'church_group_id' => $group->id,
            'member_id' => $member?->id,
            'change_type' => $changeType,
            'role' => $role,
            'metadata' => $metadata,
            'actor_id' => $actor->id,
            'created_at' => now(),
        ]);
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

    private function assertGroupInScope(User $actor, ChurchGroup $group): void
    {
        if ($actor->isChurchWide()) {
            return;
        }

        try {
            BranchScope::for($actor)->assertIncludes((int) $group->branch_id);
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
            $query->whereIn('branch_id', $scope->subtreeIds((int) $scope->branchId()));
        } catch (BranchScopeException) {
            $query->whereRaw('1 = 0');
        }
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function audit(User $actor, string $action, ChurchGroup $group, ?Member $member = null, ?array $metadata = null): void
    {
        $this->audit->record(
            actor: $actor,
            action: $action,
            category: AuditEvent::CATEGORY_BUSINESS,
            module: 'groups',
            branchId: $group->branch_id,
            subjectType: ChurchGroup::class,
            subjectId: $group->id,
            before: null,
            after: array_filter([
                'name' => $group->name,
                'status' => $group->status,
                'member_id' => $member?->id,
                'metadata' => $metadata,
            ]),
        );
    }
}
