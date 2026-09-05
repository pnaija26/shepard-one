<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\ChurchEvent;
use App\Models\ChurchEventCloseSnapshot;
use App\Models\ChurchService;
use App\Models\GatheringFeedback;
use App\Models\GatheringFeedbackActivity;
use App\Models\Member;
use App\Models\MemberNotification;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Story 4.5: submit, route, and manage gathering feedback.
 */
class GatheringFeedbackService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
    ) {
    }

    /**
     * @return Collection<int, GatheringFeedback>
     */
    public function listFeedback(User $actor, array $filters = []): Collection
    {
        $this->assertCan($actor, 'feedback.read');

        $query = GatheringFeedback::query()
            ->with(['branch:id,name', 'assignee:id,name'])
            ->orderByDesc('created_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['assigned_team'])) {
            $query->where('assigned_team', $filters['assigned_team']);
        }

        if (! empty($filters['gathering_key']) && ! empty($filters['gathering_id'])) {
            $type = config('feedback.gathering_models.' . $filters['gathering_key']);
            if ($type !== null) {
                $query->where('gathering_type', $type)
                    ->where('gathering_id', $filters['gathering_id']);
            }
        }

        $this->applyBranchScope($query, $actor);

        return $query->limit(200)->get();
    }

    public function showFeedback(User $actor, GatheringFeedback $feedback): GatheringFeedback
    {
        $this->assertCanView($actor, $feedback);

        return $feedback->load([
            'branch:id,name',
            'assignee:id,name',
            'activities.actor:id,name',
            'activities.assignee:id,name',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function submitFeedback(User $actor, array $payload): GatheringFeedback
    {
        if (! $this->authorization->allows($actor, 'feedback.submit')) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }

        if ($this->hasProhibitedAttachments($payload)) {
            throw new GatheringFeedbackException(
                'Attachments are not accepted for feedback submissions.',
                'attachment_rejected',
                422,
                'Submit text feedback only.',
            );
        }

        $validated = $this->validateSubmitPayload($payload);
        $gathering = $this->resolveGathering($validated['gathering_key'], (int) $validated['gathering_id']);
        $this->assertGatheringEligible($gathering);

        $member = Member::query()->where('user_id', $actor->id)->first();
        if ($member !== null) {
            $this->assertMemberInGatheringScope($member, $gathering);
        }

        $identityMode = $validated['identity_mode']
            ?? config('feedback.identity_policy.default_mode', 'identified');

        if ($identityMode === 'anonymous' && ! config('feedback.identity_policy.allow_anonymous', true)) {
            throw new GatheringFeedbackException(
                'Anonymous feedback is not enabled for this gathering.',
                'anonymous_not_allowed',
                422,
            );
        }

        $routing = config('feedback.category_routing.' . $validated['category'], null);
        if ($routing === null) {
            throw ValidationException::withMessages(['category' => ['Unsupported feedback category.']]);
        }

        $moderation = $this->evaluateModeration($validated['body']);

        return DB::transaction(function () use ($actor, $validated, $gathering, $member, $identityMode, $routing, $moderation): GatheringFeedback {
            $feedback = GatheringFeedback::create([
                'gathering_type' => $gathering::class,
                'gathering_id' => $gathering->id,
                'branch_id' => (int) $gathering->branch_id,
                'category' => $validated['category'],
                'body' => $validated['body'],
                'rating' => $validated['rating'] ?? null,
                'identity_mode' => $identityMode,
                'submitter_type' => $identityMode === 'identified' && $member !== null ? Member::class : null,
                'submitter_id' => $identityMode === 'identified' && $member !== null ? $member->id : null,
                'submitter_display_name' => $identityMode === 'anonymous'
                    ? 'Anonymous participant'
                    : ($member?->fullName() ?? $actor->name),
                'assigned_team' => $routing['team'],
                'status' => $moderation['status'],
                'moderation_reason' => $moderation['reason'],
                'consent_feedback_notifications' => (bool) ($validated['consent_feedback_notifications'] ?? false),
                'submitted_by' => $actor->id,
            ]);

            $this->incrementGatheringFeedbackCount($gathering);

            $this->audit->record(
                actor: $actor,
                action: 'feedback.submitted',
                category: AuditEvent::CATEGORY_BUSINESS,
                module: 'feedback',
                branchId: $feedback->branch_id,
                subjectType: $gathering::class,
                subjectId: $gathering->id,
                after: [
                    'feedback_id' => $feedback->id,
                    'category' => $feedback->category,
                    'assigned_team' => $feedback->assigned_team,
                    'status' => $feedback->status,
                ],
            );

            return $feedback->fresh(['branch:id,name']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function recordActivity(User $actor, GatheringFeedback $feedback, array $payload): GatheringFeedbackActivity
    {
        $this->assertCanManage($actor, $feedback);

        $validated = validator($payload, [
            'activity_type' => ['required', 'string', 'in:' . implode(',', config('feedback.activity_types', []))],
            'notes' => ['nullable', 'string', 'max:2000'],
            'assignee_id' => ['nullable', 'integer', 'exists:users,id'],
            'notify_submitter' => ['nullable', 'boolean'],
        ])->validate();

        return DB::transaction(function () use ($actor, $feedback, $validated): GatheringFeedbackActivity {
            $before = ['status' => $feedback->status, 'assignee_id' => $feedback->assignee_id];
            $status = $this->resolveStatusForActivity($validated['activity_type'], $feedback->status);

            $feedback->update([
                'status' => $status,
                'assignee_id' => $validated['assignee_id'] ?? $feedback->assignee_id,
                'closed_at' => $status === GatheringFeedback::STATUS_CLOSED ? now() : $feedback->closed_at,
            ]);

            $activity = GatheringFeedbackActivity::create([
                'gathering_feedback_id' => $feedback->id,
                'activity_type' => $validated['activity_type'],
                'notes' => $validated['notes'] ?? null,
                'assignee_id' => $validated['assignee_id'] ?? null,
                'notify_submitter' => (bool) ($validated['notify_submitter'] ?? false),
                'actor_id' => $actor->id,
                'created_at' => now(),
            ]);

            $this->audit->record(
                actor: $actor,
                action: 'feedback.activity_recorded',
                category: AuditEvent::CATEGORY_BUSINESS,
                module: 'feedback',
                branchId: $feedback->branch_id,
                subjectType: GatheringFeedback::class,
                subjectId: $feedback->id,
                before: $before,
                after: [
                    'status' => $feedback->status,
                    'activity_type' => $activity->activity_type,
                    'assignee_id' => $feedback->assignee_id,
                ],
            );

            if ($activity->notify_submitter) {
                $this->notifySubmitter($feedback->fresh(), $activity);
            } elseif ($status === GatheringFeedback::STATUS_CLOSED) {
                $this->notifySubmitterOnClose($feedback->fresh());
            }

            return $activity->load(['actor:id,name', 'assignee:id,name']);
        });
    }

    public function formatFeedback(GatheringFeedback $feedback, bool $includeSubmitter = false): array
    {
        $routing = config('feedback.category_routing.' . $feedback->category, []);

        $data = [
            'id' => $feedback->id,
            'gathering_type' => $feedback->gathering_type,
            'gathering_id' => $feedback->gathering_id,
            'branch_id' => $feedback->branch_id,
            'category' => $feedback->category,
            'category_label' => config('feedback.categories.' . $feedback->category, $feedback->category),
            'body' => $feedback->body,
            'rating' => $feedback->rating,
            'identity_mode' => $feedback->identity_mode,
            'assigned_team' => $feedback->assigned_team,
            'assigned_team_label' => $routing['label'] ?? $feedback->assigned_team,
            'status' => $feedback->status,
            'moderation_reason' => $feedback->moderation_reason,
            'assignee' => $feedback->assignee ? [
                'id' => $feedback->assignee->id,
                'name' => $feedback->assignee->name,
            ] : null,
            'created_at' => $feedback->created_at?->toIso8601String(),
            'closed_at' => $feedback->closed_at?->toIso8601String(),
            'activities' => $feedback->relationLoaded('activities')
                ? $feedback->activities->map(fn (GatheringFeedbackActivity $activity) => [
                    'id' => $activity->id,
                    'activity_type' => $activity->activity_type,
                    'notes' => $activity->notes,
                    'actor' => $activity->actor?->name,
                    'assignee' => $activity->assignee?->name,
                    'notify_submitter' => $activity->notify_submitter,
                    'created_at' => $activity->created_at?->toIso8601String(),
                ])->values()->all()
                : [],
        ];

        if ($includeSubmitter || $feedback->identity_mode === 'identified') {
            $data['submitter_display_name'] = $feedback->submitter_display_name;
        } else {
            $data['submitter_display_name'] = 'Anonymous participant';
        }

        return $data;
    }

    /**
     * @return array{status: string, reason: string|null}
     */
    public function evaluateModeration(string $body): array
    {
        $lower = mb_strtolower($body);

        foreach (config('feedback.moderation.prohibited_keywords', []) as $keyword) {
            if (str_contains($lower, mb_strtolower($keyword))) {
                if (config('feedback.moderation.hold_on_match', true)) {
                    return [
                        'status' => GatheringFeedback::STATUS_MODERATION_HOLD,
                        'reason' => 'content_review',
                    ];
                }

                return [
                    'status' => GatheringFeedback::STATUS_REJECTED,
                    'reason' => 'prohibited_content',
                ];
            }
        }

        return ['status' => GatheringFeedback::STATUS_SUBMITTED, 'reason' => null];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateSubmitPayload(array $payload): array
    {
        return validator($payload, [
            'gathering_key' => ['required', 'string', 'in:' . implode(',', array_keys(config('feedback.gathering_models', [])))],
            'gathering_id' => ['required', 'integer', 'min:1'],
            'category' => ['required', 'string', 'in:' . implode(',', array_keys(config('feedback.categories', [])))],
            'body' => ['required', 'string', 'min:10', 'max:5000'],
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'identity_mode' => ['nullable', 'string', 'in:' . implode(',', config('feedback.identity_modes', []))],
            'consent_feedback_notifications' => ['nullable', 'boolean'],
        ])->validate();
    }

    private function resolveGathering(string $key, int $id): Model
    {
        $modelClass = config('feedback.gathering_models.' . $key);

        if ($modelClass === null) {
            throw ValidationException::withMessages(['gathering_key' => ['Unsupported gathering type.']]);
        }

        $gathering = $modelClass::query()->find($id);

        if ($gathering === null) {
            throw new GatheringFeedbackException(
                'The referenced gathering could not be found.',
                'invalid_gathering',
                404,
                'Check the gathering reference and try again.',
            );
        }

        return $gathering;
    }

    private function assertGatheringEligible(Model $gathering): void
    {
        if ($gathering instanceof ChurchService) {
            if ($gathering->status === ChurchService::STATUS_CANCELLED) {
                throw new GatheringFeedbackException('Feedback is not accepted for cancelled services.', 'invalid_gathering', 422);
            }

            if ($gathering->status !== ChurchService::STATUS_PUBLISHED) {
                throw new GatheringFeedbackException('Feedback is only accepted after the service has been held.', 'invalid_gathering', 422);
            }

            if ($gathering->service_date->isFuture()) {
                throw new GatheringFeedbackException('Feedback opens after the service has taken place.', 'invalid_gathering', 422);
            }

            return;
        }

        if ($gathering instanceof ChurchEvent) {
            if ($gathering->status === ChurchEvent::STATUS_CANCELLED) {
                throw new GatheringFeedbackException('Feedback is not accepted for cancelled events.', 'invalid_gathering', 422);
            }

            if (! in_array($gathering->status, [ChurchEvent::STATUS_COMPLETED, ChurchEvent::STATUS_CLOSED, ChurchEvent::STATUS_PUBLISHED], true)) {
                throw new GatheringFeedbackException('Feedback is only accepted for completed gatherings.', 'invalid_gathering', 422);
            }

            if ($gathering->event_date->isFuture() && ! in_array($gathering->status, [ChurchEvent::STATUS_COMPLETED, ChurchEvent::STATUS_CLOSED], true)) {
                throw new GatheringFeedbackException('Feedback opens after the event has taken place.', 'invalid_gathering', 422);
            }
        }
    }

    private function assertMemberInGatheringScope(Member $member, Model $gathering): void
    {
        if ((int) $member->branch_id !== (int) $gathering->branch_id) {
            throw new GatheringFeedbackException(
                'You can only submit feedback for gatherings in your branch.',
                'wrong_branch',
                422,
            );
        }
    }

    private function incrementGatheringFeedbackCount(Model $gathering): void
    {
        if ($gathering instanceof ChurchEvent) {
            ChurchEventCloseSnapshot::query()
                ->where('church_event_id', $gathering->id)
                ->increment('feedback_count');
        }
    }

    private function resolveStatusForActivity(string $activityType, string $currentStatus): string
    {
        return match ($activityType) {
            'acknowledged' => GatheringFeedback::STATUS_ACKNOWLEDGED,
            'action_taken' => GatheringFeedback::STATUS_IN_PROGRESS,
            'reassigned' => GatheringFeedback::STATUS_REASSIGNED,
            'closed' => GatheringFeedback::STATUS_CLOSED,
            default => $currentStatus,
        };
    }

    private function notifySubmitter(GatheringFeedback $feedback, GatheringFeedbackActivity $activity): void
    {
        if ($feedback->identity_mode !== 'identified' || $feedback->submitter_id === null) {
            return;
        }

        if (config('feedback.notifications.require_consent', true) && ! $feedback->consent_feedback_notifications) {
            return;
        }

        $member = Member::query()->find($feedback->submitter_id);
        if ($member === null || $member->user_id === null) {
            return;
        }

        MemberNotification::create([
            'member_id' => $member->id,
            'user_id' => $member->user_id,
            'type' => 'feedback.update',
            'message' => 'Your feedback has an update from the ' . ($activity->activity_type === 'closed' ? 'team' : 'assigned team') . '.',
            'metadata' => [
                'feedback_id' => $feedback->id,
                'activity_type' => $activity->activity_type,
            ],
        ]);
    }

    private function notifySubmitterOnClose(GatheringFeedback $feedback): void
    {
        if (! config('feedback.notifications.notify_on_close', true)) {
            return;
        }

        if ($feedback->identity_mode !== 'identified' || $feedback->submitter_id === null) {
            return;
        }

        if (config('feedback.notifications.require_consent', true) && ! $feedback->consent_feedback_notifications) {
            return;
        }

        $member = Member::query()->find($feedback->submitter_id);
        if ($member === null || $member->user_id === null) {
            return;
        }

        MemberNotification::create([
            'member_id' => $member->id,
            'user_id' => $member->user_id,
            'type' => 'feedback.closed',
            'message' => 'Thank you. Your feedback has been reviewed and closed.',
            'metadata' => ['feedback_id' => $feedback->id],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hasProhibitedAttachments(array $payload): bool
    {
        return isset($payload['attachment'])
            || isset($payload['attachments'])
            || isset($payload['file'])
            || isset($payload['files']);
    }

    private function assertCan(User $actor, string $action): void
    {
        if (! $this->authorization->allows($actor, $action)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function assertCanView(User $actor, GatheringFeedback $feedback): void
    {
        if ($this->authorization->allows($actor, 'feedback.read')) {
            $this->assertFeedbackInScope($actor, $feedback);

            return;
        }

        if ($feedback->submitted_by === $actor->id && $this->authorization->allows($actor, 'feedback.submit')) {
            return;
        }

        throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
    }

    private function assertCanManage(User $actor, GatheringFeedback $feedback): void
    {
        $this->assertCan($actor, 'feedback.manage');
        $this->assertFeedbackInScope($actor, $feedback);
    }

    private function assertFeedbackInScope(User $actor, GatheringFeedback $feedback): void
    {
        if ($actor->isChurchWide()) {
            return;
        }

        try {
            BranchScope::for($actor)->assertIncludes((int) $feedback->branch_id);
        } catch (BranchScopeException) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    /** @param  Builder<GatheringFeedback>  $query */
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
