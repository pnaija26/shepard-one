<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Member;
use App\Models\MemberNotification;
use App\Models\MemberProfileChangeRequest;
use App\Models\MemberProfileHistory;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Story 2.2: member self-service profile updates with approval workflow.
 */
class MemberSelfServiceService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
        private MemberService $members,
    ) {
    }

    public function profileFor(User $user): array
    {
        $member = $this->resolveMember($user);

        return $this->formatSelfProfile($member, $user);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array{applied: array<int, array<string, mixed>>, pending: array<int, array<string, mixed>>, rejected: array<int, array<string, mixed>>}
     */
    public function updateProfile(User $user, array $changes): array
    {
        $member = $this->resolveMember($user);

        if ($member->isArchived()) {
            throw ValidationException::withMessages([
                'profile' => ['Your member profile is archived and cannot be updated.'],
            ]);
        }

        $result = ['applied' => [], 'pending' => [], 'rejected' => []];

        foreach ($changes as $field => $value) {
            $field = (string) $field;

            if ($this->isForbiddenField($field)) {
                $result['rejected'][] = [
                    'field' => $field,
                    'status' => 'rejected',
                    'message' => 'This field cannot be changed through self-service.',
                ];
                continue;
            }

            if (! $this->isSelfServiceField($field)) {
                $result['rejected'][] = [
                    'field' => $field,
                    'status' => 'rejected',
                    'message' => 'This field is not available for self-service updates.',
                ];
                continue;
            }

            $this->validateField($field, $value);

            if ($this->requiresApproval($field)) {
                $request = $this->queueChange($member, $user, $field, $value);
                $result['pending'][] = [
                    'field' => $field,
                    'status' => 'pending_approval',
                    'request_id' => $request->id,
                    'proposed_value' => $value,
                ];
                continue;
            }

            $before = [$field => $member->{$field}];
            $member->{$field} = $value;
            $member->updated_by = $user->id;
            $member->save();
            $after = [$field => $member->{$field}];

            $this->recordHistory($member, $user, 'self_updated', $before, $after);
            $this->auditChange($user, 'member.self_updated', $member, $before, $after);

            $result['applied'][] = [
                'field' => $field,
                'status' => 'applied',
                'value' => $member->{$field},
            ];
        }

        if ($result['applied'] === [] && $result['pending'] === [] && $result['rejected'] !== []) {
            throw ValidationException::withMessages([
                'profile' => ['No permitted profile changes were submitted.'],
            ]);
        }

        return $result;
    }

    /**
     * @return Collection<int, MemberProfileChangeRequest>
     */
    public function listPendingReviews(User $officer, ?int $branchId = null): Collection
    {
        $this->assertCanReview($officer);

        $query = MemberProfileChangeRequest::query()
            ->with(['member.branch:id,name', 'submitter:id,name,email'])
            ->where('status', MemberProfileChangeRequest::STATUS_PENDING)
            ->orderByDesc('created_at');

        if (! $officer->isChurchWide()) {
            try {
                $scope = BranchScope::for($officer);
                $query->whereHas('member', fn ($q) => $q->whereIn('branch_id', $scope->subtreeIds((int) $scope->branchId())));
            } catch (BranchScopeException) {
                return collect();
            }
        } elseif ($branchId !== null) {
            $query->whereHas('member', fn ($q) => $q->where('branch_id', $branchId));
        }

        return $query->get();
    }

    public function approveChange(User $officer, MemberProfileChangeRequest $request): MemberProfileChangeRequest
    {
        $this->assertCanReview($officer, $request->member);
        $this->assertPending($request);

        return DB::transaction(function () use ($officer, $request) {
            $member = $request->member;
            $field = $request->field_name;
            $before = [$field => $member->{$field}];
            $proposed = $request->proposed_value['value'] ?? $request->proposed_value;

            $this->validateField($field, $proposed);

            $member->{$field} = $proposed;
            $member->updated_by = $officer->id;
            $member->save();

            $after = [$field => $member->{$field}];

            $request->update([
                'status' => MemberProfileChangeRequest::STATUS_APPROVED,
                'reviewed_by' => $officer->id,
                'reviewed_at' => now(),
            ]);

            $this->recordHistory($member, $officer, 'change_approved', $before, $after);
            $this->auditChange($officer, 'member.profile_change.approved', $member, $before, $after, [
                'request_id' => $request->id,
            ]);
            $this->notifyMember($member, 'profile_change_approved', "Your {$field} change was approved.", [
                'request_id' => $request->id,
                'field' => $field,
            ]);
            $request->update(['member_notified_at' => now()]);

            return $request->fresh(['member', 'submitter', 'reviewer']);
        });
    }

    public function rejectChange(User $officer, MemberProfileChangeRequest $request, ?string $reason = null): MemberProfileChangeRequest
    {
        $this->assertCanReview($officer, $request->member);
        $this->assertPending($request);

        return DB::transaction(function () use ($officer, $request, $reason) {
            $member = $request->member;
            $field = $request->field_name;
            $before = [$field => $member->{$field}];

            $request->update([
                'status' => MemberProfileChangeRequest::STATUS_REJECTED,
                'reviewed_by' => $officer->id,
                'reviewed_at' => now(),
                'decision_reason' => $reason,
            ]);

            $this->auditChange($officer, 'member.profile_change.rejected', $member, $before, $before, [
                'request_id' => $request->id,
                'reason' => $reason,
            ]);
            $this->notifyMember($member, 'profile_change_rejected', "Your {$field} change was not approved.", [
                'request_id' => $request->id,
                'field' => $field,
                'reason' => $reason,
            ]);
            $request->update(['member_notified_at' => now()]);

            return $request->fresh(['member', 'submitter', 'reviewer']);
        });
    }

    public function formatChangeRequest(MemberProfileChangeRequest $request): array
    {
        return [
            'id' => $request->id,
            'member_id' => $request->member_id,
            'member' => $request->member ? [
                'id' => $request->member->id,
                'full_name' => $request->member->fullName(),
                'membership_id' => $request->member->membership_id,
            ] : null,
            'field_name' => $request->field_name,
            'current_value' => $request->current_value,
            'proposed_value' => $request->proposed_value,
            'status' => $request->status,
            'submitted_by' => $request->submitter ? [
                'id' => $request->submitter->id,
                'name' => $request->submitter->name,
            ] : null,
            'reviewed_by' => $request->reviewer ? [
                'id' => $request->reviewer->id,
                'name' => $request->reviewer->name,
            ] : null,
            'reviewed_at' => $request->reviewed_at?->toIso8601String(),
            'decision_reason' => $request->decision_reason,
            'created_at' => $request->created_at?->toIso8601String(),
        ];
    }

    private function formatSelfProfile(Member $member, User $user): array
    {
        $profile = $this->members->formatForViewer($member, $user);

        $pending = MemberProfileChangeRequest::query()
            ->where('member_id', $member->id)
            ->where('status', MemberProfileChangeRequest::STATUS_PENDING)
            ->get()
            ->map(fn (MemberProfileChangeRequest $r) => [
                'field' => $r->field_name,
                'status' => 'pending_approval',
                'request_id' => $r->id,
                'proposed_value' => $r->proposed_value['value'] ?? $r->proposed_value,
            ])
            ->values()
            ->all();

        $notifications = MemberNotification::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn (MemberNotification $n) => [
                'id' => $n->id,
                'type' => $n->type,
                'message' => $n->message,
                'read_at' => $n->read_at?->toIso8601String(),
                'created_at' => $n->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $profile['field_policies'] = [
            'immediate' => config('members.self_service.immediate', []),
            'approval_required' => config('members.self_service.approval_required', []),
        ];
        $profile['pending_changes'] = $pending;
        $profile['notifications'] = $notifications;

        return $profile;
    }

    private function resolveMember(User $user): Member
    {
        $member = Member::query()->where('user_id', $user->id)->first();

        if ($member === null) {
            throw new AuthorizationException('No member profile is linked to your account.');
        }

        return $member;
    }

    private function queueChange(Member $member, User $user, string $field, mixed $value): MemberProfileChangeRequest
    {
        MemberProfileChangeRequest::query()
            ->where('member_id', $member->id)
            ->where('field_name', $field)
            ->where('status', MemberProfileChangeRequest::STATUS_PENDING)
            ->update(['status' => MemberProfileChangeRequest::STATUS_REJECTED, 'decision_reason' => 'Superseded by a newer request.']);

        return MemberProfileChangeRequest::create([
            'member_id' => $member->id,
            'field_name' => $field,
            'current_value' => ['value' => $member->{$field}],
            'proposed_value' => ['value' => $value],
            'status' => MemberProfileChangeRequest::STATUS_PENDING,
            'submitted_by' => $user->id,
        ]);
    }

    private function notifyMember(Member $member, string $type, string $message, array $metadata = []): void
    {
        if ($member->user_id === null) {
            return;
        }

        MemberNotification::create([
            'member_id' => $member->id,
            'user_id' => $member->user_id,
            'type' => $type,
            'message' => $message,
            'metadata' => $metadata,
        ]);
    }

    private function validateField(string $field, mixed $value): void
    {
        $rules = match ($field) {
            'email' => ['nullable', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:32'],
            'photo_path' => ['nullable', 'string', 'max:255'],
            'occupation' => ['nullable', 'string', 'max:120'],
            'preferred_name' => ['nullable', 'string', 'max:120'],
            'emergency_contact' => ['nullable', 'array'],
            'address_line1', 'address_line2' => ['nullable', 'string', 'max:255'],
            'city', 'state' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:32'],
            'country' => ['nullable', 'string', 'max:64'],
            default => ['nullable'],
        };

        validator(['value' => $value], ['value' => $rules])->validate();
    }

    private function isForbiddenField(string $field): bool
    {
        return in_array($field, config('members.self_service.forbidden', []), true);
    }

    private function isSelfServiceField(string $field): bool
    {
        return $this->requiresApproval($field)
            || in_array($field, config('members.self_service.immediate', []), true);
    }

    private function requiresApproval(string $field): bool
    {
        return in_array($field, config('members.self_service.approval_required', []), true);
    }

    private function assertCanReview(User $officer, ?Member $member = null): void
    {
        if (! $this->authorization->allows($officer, 'members.changes.review', $member?->branch_id)) {
            throw new AuthorizationException('Forbidden.');
        }

        if ($member !== null && ! $officer->isChurchWide()) {
            try {
                BranchScope::for($officer)->assertIncludes($member->branch_id);
            } catch (BranchScopeException) {
                throw new AuthorizationException('Forbidden.');
            }
        }
    }

    private function assertPending(MemberProfileChangeRequest $request): void
    {
        if ($request->status !== MemberProfileChangeRequest::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'request' => ['This profile change has already been decided.'],
            ]);
        }
    }

    private function recordHistory(Member $member, User $actor, string $action, ?array $before, ?array $after): void
    {
        MemberProfileHistory::create([
            'member_id' => $member->id,
            'actor_id' => $actor->id,
            'action' => $action,
            'before_values' => $before,
            'after_values' => $after,
            'created_at' => now(),
        ]);
    }

    private function auditChange(
        User $actor,
        string $action,
        Member $member,
        ?array $before,
        ?array $after,
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
}
