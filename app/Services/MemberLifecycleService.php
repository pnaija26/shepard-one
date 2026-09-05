<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Member;
use App\Models\MemberLifecycleHistory;
use App\Models\MemberLifecyclePendingTransition;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Story 2.4: configurable member lifecycle stages, statuses, and transitions.
 */
class MemberLifecycleService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
        private OnboardingJourneyService $onboarding,
    ) {
    }

    public function stateFor(User $actor, Member $member): array
    {
        $this->assertCan($actor, 'members.lifecycle.read', $member->branch_id);
        $member = $this->loadMember($actor, $member->id);

        return $this->formatState($member);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function requestTransition(User $actor, Member $member, array $payload): array
    {
        $this->assertCan($actor, 'members.lifecycle.manage', $member->branch_id);
        $member = $this->loadMember($actor, $member->id);

        $validated = validator($payload, [
            'to_stage' => ['nullable', 'string', 'in:' . implode(',', config('members.lifecycle.stages', []))],
            'to_status' => ['nullable', 'string', 'in:' . implode(',', config('members.statuses', []))],
            'effective_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:2000'],
            'milestone' => ['nullable', 'array'],
            'evidence' => ['nullable', 'array'],
        ])->validate();

        if (empty($validated['to_stage']) && empty($validated['to_status'])) {
            throw ValidationException::withMessages([
                'transition' => ['Provide a target stage and/or status.'],
            ]);
        }

        $toStage = $validated['to_stage'] ?? $member->lifecycle_stage;
        $toStatus = $validated['to_status'] ?? $member->lifecycle_status;

        if ($toStage === $member->lifecycle_stage && $toStatus === $member->lifecycle_status) {
            throw new MemberLifecycleTransitionException('Member is already in the requested lifecycle state.');
        }

        $rule = $this->resolveRule($member, $toStage, $toStatus);
        $missing = $this->missingRequirements($rule, $validated);

        if ($missing !== []) {
            throw new MemberLifecycleTransitionException(
                'Required information is missing for this lifecycle transition.',
                $missing,
            );
        }

        if ($rule['requires_approval'] ?? false) {
            $pending = MemberLifecyclePendingTransition::create([
                'member_id' => $member->id,
                'to_stage' => $validated['to_stage'] ?? null,
                'to_status' => $validated['to_status'] ?? null,
                'effective_date' => $validated['effective_date'],
                'reason' => $validated['reason'],
                'milestone' => $validated['milestone'] ?? null,
                'evidence' => $validated['evidence'] ?? null,
                'status' => MemberLifecyclePendingTransition::STATUS_PENDING,
                'requested_by' => $actor->id,
            ]);

            return [
                'status' => 'pending_approval',
                'pending_transition_id' => $pending->id,
                'current' => $this->currentState($member),
            ];
        }

        $member = $this->applyTransition($actor, $member, $toStage, $toStatus, $validated);

        return [
            'status' => 'applied',
            'current' => $this->currentState($member),
        ];
    }

    /**
     * @return Collection<int, MemberLifecyclePendingTransition>
     */
    public function listPending(User $actor): Collection
    {
        $this->assertCan($actor, 'members.lifecycle.approve');

        $query = MemberLifecyclePendingTransition::query()
            ->with(['member.branch:id,name', 'requester:id,name'])
            ->where('status', MemberLifecyclePendingTransition::STATUS_PENDING)
            ->orderByDesc('created_at');

        if (! $actor->isChurchWide()) {
            try {
                $scope = BranchScope::for($actor);
                $query->whereHas('member', fn ($q) => $q->whereIn('branch_id', $scope->subtreeIds((int) $scope->branchId())));
            } catch (BranchScopeException) {
                return collect();
            }
        }

        return $query->get();
    }

    public function approvePending(User $actor, MemberLifecyclePendingTransition $pending): array
    {
        $this->assertCan($actor, 'members.lifecycle.approve', $pending->member?->branch_id);
        $this->assertPending($pending);

        return DB::transaction(function () use ($actor, $pending) {
            $member = $pending->member;
            $toStage = $pending->to_stage ?? $member->lifecycle_stage;
            $toStatus = $pending->to_status ?? $member->lifecycle_status;

            $member = $this->applyTransition($actor, $member, $toStage, $toStatus, [
                'effective_date' => $pending->effective_date->format('Y-m-d'),
                'reason' => $pending->reason,
                'milestone' => $pending->milestone,
                'evidence' => $pending->evidence,
            ]);

            $pending->update([
                'status' => MemberLifecyclePendingTransition::STATUS_APPROVED,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
            ]);

            return [
                'status' => 'approved',
                'current' => $this->currentState($member),
            ];
        });
    }

    public function rejectPending(User $actor, MemberLifecyclePendingTransition $pending, ?string $reason = null): array
    {
        $this->assertCan($actor, 'members.lifecycle.approve', $pending->member?->branch_id);
        $this->assertPending($pending);

        $pending->update([
            'status' => MemberLifecyclePendingTransition::STATUS_REJECTED,
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
            'decision_reason' => $reason,
        ]);

        $this->audit->record(
            actor: $actor,
            action: 'member.lifecycle.rejected',
            category: AuditEvent::CATEGORY_BUSINESS,
            module: 'members',
            branchId: $pending->member->branch_id,
            subjectType: Member::class,
            subjectId: $pending->member_id,
            metadata: ['pending_transition_id' => $pending->id, 'reason' => $reason],
        );

        return [
            'status' => 'rejected',
            'current' => $this->currentState($pending->member),
        ];
    }

    public function recordInitialState(Member $member, User $actor, string $stage = 'visitor', string $status = 'active'): void
    {
        $member->update([
            'lifecycle_stage' => $stage,
            'lifecycle_status' => $status,
            'membership_status' => $status,
            'lifecycle_policy' => $this->policyForStatus($status),
        ]);

        MemberLifecycleHistory::create([
            'member_id' => $member->id,
            'stage' => $stage,
            'status' => $status,
            'effective_date' => now()->toDateString(),
            'reason' => 'Initial registration',
            'policy_applied' => $this->policyForStatus($status),
            'actor_id' => $actor->id,
            'created_at' => now(),
        ]);
    }

    public function formatState(Member $member): array
    {
        $history = MemberLifecycleHistory::query()
            ->where('member_id', $member->id)
            ->with('actor:id,name')
            ->orderByDesc('created_at')
            ->get();

        $pending = MemberLifecyclePendingTransition::query()
            ->where('member_id', $member->id)
            ->where('status', MemberLifecyclePendingTransition::STATUS_PENDING)
            ->orderByDesc('created_at')
            ->get();

        return [
            'current' => $this->currentState($member),
            'history' => $history->map(fn (MemberLifecycleHistory $row) => [
                'id' => $row->id,
                'stage' => $row->stage,
                'status' => $row->status,
                'previous_stage' => $row->previous_stage,
                'previous_status' => $row->previous_status,
                'effective_date' => $row->effective_date?->format('Y-m-d'),
                'reason' => $row->reason,
                'milestone' => $row->milestone,
                'evidence' => $row->evidence,
                'policy_applied' => $row->policy_applied,
                'actor' => $row->actor ? ['id' => $row->actor->id, 'name' => $row->actor->name] : null,
                'created_at' => $row->created_at?->toIso8601String(),
            ])->values(),
            'pending_transitions' => $pending->map(fn (MemberLifecyclePendingTransition $row) => [
                'id' => $row->id,
                'to_stage' => $row->to_stage,
                'to_status' => $row->to_status,
                'effective_date' => $row->effective_date?->format('Y-m-d'),
                'reason' => $row->reason,
            ])->values(),
            'config' => [
                'stages' => config('members.lifecycle.stages', []),
                'statuses' => config('members.statuses', []),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyTransition(
        User $actor,
        Member $member,
        string $toStage,
        string $toStatus,
        array $payload,
    ): Member {
        $previousStage = $member->lifecycle_stage;
        $previousStatus = $member->lifecycle_status;
        $policy = $this->policyForStatus($toStatus);

        $updates = [
            'lifecycle_stage' => $toStage,
            'lifecycle_status' => $toStatus,
            'membership_status' => $toStatus,
            'lifecycle_policy' => $policy,
            'updated_by' => $actor->id,
        ];

        if ($toStatus === 'archived') {
            $updates['archived_at'] = now();
        } elseif ($previousStatus === 'archived' && $toStatus === 'active') {
            $updates['archived_at'] = null;
        }

        $member->update($updates);

        MemberLifecycleHistory::create([
            'member_id' => $member->id,
            'stage' => $toStage,
            'status' => $toStatus,
            'previous_stage' => $previousStage,
            'previous_status' => $previousStatus,
            'effective_date' => $payload['effective_date'],
            'reason' => $payload['reason'],
            'milestone' => $payload['milestone'] ?? null,
            'evidence' => $payload['evidence'] ?? null,
            'policy_applied' => $policy,
            'actor_id' => $actor->id,
            'created_at' => now(),
        ]);

        $this->audit->record(
            actor: $actor,
            action: 'member.lifecycle.transitioned',
            category: AuditEvent::CATEGORY_BUSINESS,
            module: 'members',
            branchId: $member->branch_id,
            subjectType: Member::class,
            subjectId: $member->id,
            before: ['stage' => $previousStage, 'status' => $previousStatus],
            after: ['stage' => $toStage, 'status' => $toStatus],
            metadata: [
                'milestone' => $payload['milestone'] ?? null,
                'policy' => $policy,
            ],
        );

        if ($toStage !== $previousStage) {
            $this->onboarding->handleEvent('member.lifecycle.' . $toStage, $member->fresh(), $actor);
        }

        return $member->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveRule(Member $member, string $toStage, string $toStatus): array
    {
        if ($toStage !== $member->lifecycle_stage) {
            foreach (config('members.lifecycle.stage_rules', []) as $rule) {
                if ($rule['from'] === $member->lifecycle_stage && $rule['to'] === $toStage) {
                    return $rule;
                }
            }

            throw new MemberLifecycleTransitionException(
                "Transition from {$member->lifecycle_stage} to {$toStage} is not permitted.",
            );
        }

        foreach (config('members.lifecycle.status_rules', []) as $rule) {
            if ($rule['to'] === $toStatus) {
                return $rule;
            }
        }

        throw new MemberLifecycleTransitionException(
            "Transition to status {$toStatus} is not permitted.",
        );
    }

    /**
     * @param  array<string, mixed>  $rule
     * @param  array<string, mixed>  $payload
     * @return string[]
     */
    private function missingRequirements(array $rule, array $payload): array
    {
        $missing = [];
        foreach ($rule['requires'] ?? [] as $field) {
            if ($field === 'reason' && empty($payload['reason'])) {
                $missing[] = 'reason';
            }
            if ($field === 'milestone' && empty($payload['milestone'])) {
                $missing[] = 'milestone';
            }
            if ($field === 'evidence' && empty($payload['evidence'])) {
                $missing[] = 'evidence';
            }
        }

        return $missing;
    }

    /** @return array<string, mixed> */
    private function policyForStatus(string $status): array
    {
        return config("members.lifecycle.status_policies.{$status}", [
            'communications' => 'enabled',
            'permissions' => 'full',
        ]);
    }

    /** @return array<string, mixed> */
    private function currentState(Member $member): array
    {
        return [
            'stage' => $member->lifecycle_stage,
            'status' => $member->lifecycle_status,
            'membership_status' => $member->membership_status,
            'policy' => $member->lifecycle_policy,
        ];
    }

    private function assertCan(User $actor, string $action, ?int $branchId = null): void
    {
        if (! $this->authorization->allows($actor, $action, $branchId)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function assertPending(MemberLifecyclePendingTransition $pending): void
    {
        if ($pending->status !== MemberLifecyclePendingTransition::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'transition' => ['This lifecycle transition has already been decided.'],
            ]);
        }
    }

    private function loadMember(User $actor, int $memberId): Member
    {
        if (! $this->authorization->allows($actor, 'members.read')) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }

        $member = Member::with('branch:id,name')->findOrFail($memberId);
        $this->assertMemberInScope($actor, $member);

        return $member;
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
}
