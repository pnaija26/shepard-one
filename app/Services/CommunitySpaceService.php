<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\CommunitySpace;
use App\Models\CommunitySpaceIntegration;
use App\Models\CommunitySpaceMembership;
use App\Models\CommunitySpaceMessage;
use App\Models\CommunitySpaceModerationEvent;
use App\Models\Member;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Story 10.6: operate moderated community communication spaces.
 */
class CommunitySpaceService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
    ) {
    }

    /**
     * @return Collection<int, CommunitySpace>
     */
    public function list(User $actor): Collection
    {
        $this->assertCan($actor, 'community_spaces.read');

        $query = CommunitySpace::query()
            ->with(['branch:id,name'])
            ->where('status', CommunitySpace::STATUS_ACTIVE)
            ->orderByDesc('id');

        $this->applyAccessibleSpaces($query, $actor);

        return $query->limit(100)->get();
    }

    public function show(User $actor, CommunitySpace $space): CommunitySpace
    {
        $this->assertCan($actor, 'community_spaces.read');
        $this->assertCanAccessSpace($actor, $space);

        return $space->load([
            'branch:id,name',
            'memberships' => fn ($q) => $q->where('status', '!=', CommunitySpaceMembership::STATUS_LEFT)->limit(100),
            'memberships.user:id,name,email',
            'integrations',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(User $actor, array $payload): CommunitySpace
    {
        $this->assertCan($actor, 'community_spaces.manage');

        $validated = validator($payload, [
            'name' => ['required', 'string', 'min:3', 'max:160'],
            'space_type' => ['required', 'string', 'in:' . implode(',', config('community_spaces.space_types', []))],
            'branch_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'description' => ['nullable', 'string', 'max:2000'],
            'retention_days' => [
                'nullable',
                'integer',
                'min:' . (int) config('community_spaces.min_retention_days', 30),
                'max:' . (int) config('community_spaces.max_retention_days', 1825),
            ],
            'requires_consent' => ['nullable', 'boolean'],
            'moderator_user_ids' => ['nullable', 'array'],
            'moderator_user_ids.*' => ['integer', 'exists:users,id'],
        ])->validate();

        if (! empty($validated['branch_id'])) {
            $this->assertBranchWritable($actor, (int) $validated['branch_id']);
        }

        return DB::transaction(function () use ($actor, $validated): CommunitySpace {
            $space = CommunitySpace::create([
                'reference' => 'CS-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6)),
                'name' => $validated['name'],
                'space_type' => $validated['space_type'],
                'branch_id' => $validated['branch_id'] ?? null,
                'status' => CommunitySpace::STATUS_ACTIVE,
                'retention_days' => $validated['retention_days'] ?? (int) config('community_spaces.default_retention_days', 365),
                'requires_consent' => $validated['requires_consent'] ?? true,
                'description' => $validated['description'] ?? null,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->upsertMembership($space, $actor, CommunitySpaceMembership::ROLE_MODERATOR);

            foreach ($validated['moderator_user_ids'] ?? [] as $userId) {
                if ((int) $userId === $actor->id) {
                    continue;
                }
                $user = User::query()->find($userId);
                if ($user) {
                    $this->upsertMembership($space, $user, CommunitySpaceMembership::ROLE_MODERATOR);
                }
            }

            $this->audit($actor, 'community_space.created', $space, [
                'space_type' => $space->space_type,
            ]);

            return $space->fresh(['memberships']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function addMember(User $actor, CommunitySpace $space, array $payload): CommunitySpaceMembership
    {
        $this->assertCan($actor, 'community_spaces.manage');
        $this->assertCanAccessSpace($actor, $space);
        $this->assertModerator($actor, $space);

        $validated = validator($payload, [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'role' => ['nullable', 'string', 'in:member,moderator'],
        ])->validate();

        $user = User::query()->findOrFail($validated['user_id']);
        $membership = $this->upsertMembership(
            $space,
            $user,
            $validated['role'] ?? CommunitySpaceMembership::ROLE_MEMBER,
        );

        $this->audit($actor, 'community_space.member_added', $space, [
            'user_id' => $user->id,
            'role' => $membership->role,
        ]);

        return $membership;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function postMessage(User $actor, CommunitySpace $space, array $payload): CommunitySpaceMessage
    {
        $this->assertCan($actor, 'community_spaces.post');
        $this->assertCanAccessSpace($actor, $space);

        $membership = $this->membershipFor($actor, $space);
        if ($membership === null || $membership->status === CommunitySpaceMembership::STATUS_LEFT) {
            throw new CommunitySpaceException('You are not a participant in this space.', 'not_participant', 403);
        }
        if (in_array($membership->status, [CommunitySpaceMembership::STATUS_MUTED, CommunitySpaceMembership::STATUS_BANNED], true)) {
            throw new CommunitySpaceException('You cannot post while moderated.', 'membership_restricted', 403);
        }

        if ($space->requires_consent) {
            $member = $this->memberForUser($actor);
            if ($member && ! $member->consent_data_processing) {
                throw new CommunitySpaceException('Consent is required to post in this space.', 'missing_consent', 422);
            }
        }

        $types = array_keys(config('community_spaces.message_types', []));
        $validated = validator($payload, [
            'message_type' => ['required', 'string', 'in:' . implode(',', $types)],
            'body' => ['nullable', 'string'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*.name' => ['required_with:attachments', 'string', 'max:180'],
            'attachments.*.mime' => ['required_with:attachments', 'string', 'max:120'],
            'attachments.*.size_bytes' => ['required_with:attachments', 'integer', 'min:1'],
            'attachments.*.storage_key' => ['nullable', 'string', 'max:255'],
            'poll_options' => ['nullable', 'array'],
            'poll_options.*' => ['string', 'min:1', 'max:120'],
            'is_sensitive' => ['nullable', 'boolean'],
        ])->validate();

        $typeConfig = config('community_spaces.message_types.' . $validated['message_type']);
        if (! empty($typeConfig['requires_moderator']) && $membership->role !== CommunitySpaceMembership::ROLE_MODERATOR) {
            throw new CommunitySpaceException('Only moderators may post announcements.', 'moderator_required', 403);
        }

        $body = $validated['body'] ?? '';
        $maxLen = (int) ($typeConfig['max_body_length'] ?? 5000);
        if (mb_strlen($body) > $maxLen) {
            throw new CommunitySpaceException('Message body exceeds the allowed length.', 'body_too_long', 422, [
                'max' => $maxLen,
            ]);
        }

        foreach (config('community_spaces.unsafe_markup_patterns', []) as $pattern) {
            if ($body !== '' && preg_match($pattern, $body)) {
                throw new CommunitySpaceException('Message contains unsafe markup.', 'unsafe_markup', 422);
            }
        }

        $attachments = $this->validateAttachments($validated['message_type'], $validated['attachments'] ?? [], $typeConfig);
        $pollOptions = null;
        if ($validated['message_type'] === 'poll') {
            $options = array_values($validated['poll_options'] ?? []);
            $min = (int) ($typeConfig['min_options'] ?? 2);
            $max = (int) ($typeConfig['max_options'] ?? 8);
            if (count($options) < $min || count($options) > $max) {
                throw new CommunitySpaceException('Poll options are invalid.', 'invalid_poll', 422, [
                    'min' => $min,
                    'max' => $max,
                ]);
            }
            $pollOptions = $options;
        }

        if (in_array($validated['message_type'], ['image', 'document', 'voice_note'], true) && $attachments === []) {
            throw new CommunitySpaceException('This message type requires an attachment.', 'attachment_required', 422);
        }

        $member = $this->memberForUser($actor);
        $message = CommunitySpaceMessage::create([
            'community_space_id' => $space->id,
            'sender_user_id' => $actor->id,
            'sender_member_id' => $member?->id,
            'message_type' => $validated['message_type'],
            'body' => $body !== '' ? $body : null,
            'attachments' => $attachments ?: null,
            'poll_options' => $pollOptions,
            'status' => CommunitySpaceMessage::STATUS_VISIBLE,
            'is_sensitive' => (bool) ($validated['is_sensitive'] ?? false),
            'retain_until' => now()->addDays((int) $space->retention_days),
        ]);

        $this->audit($actor, 'community_space.message_posted', $space, [
            'message_id' => $message->id,
            'message_type' => $message->message_type,
        ]);

        return $message->load('sender:id,name,email');
    }

    /**
     * @return Collection<int, CommunitySpaceMessage>
     */
    public function listMessages(User $actor, CommunitySpace $space, int $limit = 50): Collection
    {
        $this->assertCan($actor, 'community_spaces.read');
        $this->assertCanAccessSpace($actor, $space);

        $isModerator = $this->isModerator($actor, $space);

        $query = CommunitySpaceMessage::query()
            ->where('community_space_id', $space->id)
            ->with('sender:id,name,email')
            ->orderByDesc('is_pinned')
            ->orderByDesc('created_at');

        if (! $isModerator) {
            $query->where('status', '!=', CommunitySpaceMessage::STATUS_REMOVED);
        }

        return $query->limit(min($limit, 100))->get();
    }

    /**
     * Search excludes removed (and sensitive-removed) content from results for all users.
     *
     * @return Collection<int, CommunitySpaceMessage>
     */
    public function search(User $actor, CommunitySpace $space, string $query): Collection
    {
        $this->assertCan($actor, 'community_spaces.read');
        $this->assertCanAccessSpace($actor, $space);

        $term = trim($query);
        if ($term === '' || mb_strlen($term) < 2) {
            throw new CommunitySpaceException('Search query must be at least 2 characters.', 'invalid_query', 422);
        }

        $max = (int) config('community_spaces.search.max_results', 50);

        return CommunitySpaceMessage::query()
            ->where('community_space_id', $space->id)
            ->where('status', CommunitySpaceMessage::STATUS_VISIBLE)
            ->where(function (Builder $q) use ($term): void {
                $q->where('body', 'like', '%' . $term . '%');
            })
            ->with('sender:id,name,email')
            ->orderByDesc('created_at')
            ->limit($max)
            ->get();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function pinMessage(User $actor, CommunitySpace $space, CommunitySpaceMessage $message, array $payload = []): CommunitySpaceMessage
    {
        $this->assertModeratorAction($actor, $space, $message);

        $pin = ! array_key_exists('pinned', $payload) || (bool) $payload['pinned'];
        $message->update([
            'is_pinned' => $pin,
            'pinned_at' => $pin ? now() : null,
            'pinned_by' => $pin ? $actor->id : null,
        ]);

        $this->recordModeration($space, $actor, $pin ? 'pin' : 'unpin', $message, null, $payload['reason'] ?? null);
        $this->audit($actor, $pin ? 'community_space.message_pinned' : 'community_space.message_unpinned', $space, [
            'message_id' => $message->id,
        ]);

        return $message->fresh('sender:id,name,email');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function restrictMessage(User $actor, CommunitySpace $space, CommunitySpaceMessage $message, array $payload = []): CommunitySpaceMessage
    {
        $this->assertModeratorAction($actor, $space, $message);

        $message->update([
            'status' => CommunitySpaceMessage::STATUS_RESTRICTED,
        ]);

        $this->recordModeration($space, $actor, 'restrict', $message, null, $payload['reason'] ?? null);
        $this->audit($actor, 'community_space.message_restricted', $space, [
            'message_id' => $message->id,
        ]);

        return $message->fresh('sender:id,name,email');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function removeMessage(User $actor, CommunitySpace $space, CommunitySpaceMessage $message, array $payload = []): CommunitySpaceMessage
    {
        $this->assertModeratorAction($actor, $space, $message);

        $reason = $payload['reason'] ?? 'Removed by moderator';
        $message->update([
            'status' => CommunitySpaceMessage::STATUS_REMOVED,
            'body' => $message->is_sensitive ? null : $message->body,
            'attachments' => $message->is_sensitive ? null : $message->attachments,
            'poll_options' => $message->is_sensitive ? null : $message->poll_options,
            'removed_at' => now(),
            'removed_by' => $actor->id,
            'removal_reason' => $reason,
            'is_pinned' => false,
            'pinned_at' => null,
            'pinned_by' => null,
        ]);

        $this->recordModeration($space, $actor, 'remove', $message, null, $reason, [
            'sensitive_cleared' => (bool) $message->is_sensitive,
        ]);
        $this->audit($actor, 'community_space.message_removed', $space, [
            'message_id' => $message->id,
            'sensitive' => (bool) $message->is_sensitive,
        ]);

        return $message->fresh('sender:id,name,email');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function reportMessage(User $actor, CommunitySpace $space, CommunitySpaceMessage $message, array $payload): CommunitySpaceModerationEvent
    {
        $this->assertCan($actor, 'community_spaces.post');
        $this->assertCanAccessSpace($actor, $space);
        $this->assertMessageInSpace($space, $message);

        if ($this->membershipFor($actor, $space) === null) {
            throw new CommunitySpaceException('You are not a participant in this space.', 'not_participant', 403);
        }

        $validated = validator($payload, [
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ])->validate();

        $event = $this->recordModeration($space, $actor, 'report', $message, null, $validated['reason']);
        $this->audit($actor, 'community_space.message_reported', $space, [
            'message_id' => $message->id,
        ]);

        return $event;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function moderateParticipant(User $actor, CommunitySpace $space, User $target, array $payload): CommunitySpaceMembership
    {
        $this->assertCan($actor, 'community_spaces.moderate');
        $this->assertCanAccessSpace($actor, $space);
        $this->assertModerator($actor, $space);

        $validated = validator($payload, [
            'action' => ['required', 'string', 'in:mute,unmute,ban,unban'],
            'reason' => ['nullable', 'string', 'max:255'],
        ])->validate();

        $membership = $this->membershipFor($target, $space);
        if ($membership === null) {
            throw new CommunitySpaceException('Target user is not a participant.', 'not_participant', 422);
        }

        $status = match ($validated['action']) {
            'mute' => CommunitySpaceMembership::STATUS_MUTED,
            'ban' => CommunitySpaceMembership::STATUS_BANNED,
            'unmute', 'unban' => CommunitySpaceMembership::STATUS_ACTIVE,
        };

        $membership->update([
            'status' => $status,
            'moderated_at' => now(),
            'moderated_by' => $actor->id,
            'moderation_reason' => $validated['reason'] ?? null,
        ]);

        $this->recordModeration($space, $actor, $validated['action'], null, $target, $validated['reason'] ?? null);
        $this->audit($actor, 'community_space.participant_moderated', $space, [
            'target_user_id' => $target->id,
            'action' => $validated['action'],
            'status' => $status,
        ]);

        return $membership->fresh();
    }

    /**
     * Configure an approved external messaging integration with documented boundaries.
     *
     * @param  array<string, mixed>  $payload
     */
    public function configureIntegration(User $actor, CommunitySpace $space, array $payload): CommunitySpaceIntegration
    {
        $this->assertCan($actor, 'community_spaces.integrate');
        $this->assertCanAccessSpace($actor, $space);

        $approved = config('community_spaces.approved_integrations', []);
        $validated = validator($payload, [
            'provider' => ['required', 'string', 'max:64'],
            'enabled' => ['nullable', 'boolean'],
            'consent_documented' => ['required', 'boolean'],
            'identity_mapping' => ['required', 'array'],
            'moderation_boundary' => ['required', 'string', 'min:10', 'max:2000'],
            'config' => ['nullable', 'array'],
        ])->validate();

        if (! isset($approved[$validated['provider']])) {
            throw new CommunitySpaceException(
                'Provider is not an approved integration. Unsupported parallel messaging is not allowed.',
                'unsupported_integration',
                422,
                ['approved' => array_keys($approved)],
            );
        }

        $rules = $approved[$validated['provider']];
        if (! empty($rules['requires_consent']) && empty($validated['consent_documented'])) {
            throw new CommunitySpaceException('Documented consent is required for this integration.', 'consent_required', 422);
        }
        if (! empty($rules['requires_identity_mapping']) && empty($validated['identity_mapping'])) {
            throw new CommunitySpaceException('Identity mapping is required for this integration.', 'identity_mapping_required', 422);
        }
        if (! empty($rules['requires_moderation_boundary']) && trim($validated['moderation_boundary']) === '') {
            throw new CommunitySpaceException('Moderation boundary documentation is required.', 'moderation_boundary_required', 422);
        }

        // Strip any secret-looking keys from config.
        $config = $validated['config'] ?? [];
        foreach (array_keys($config) as $key) {
            if (preg_match('/secret|token|password|api_key|credential/i', (string) $key)) {
                unset($config[$key]);
            }
        }

        $integration = CommunitySpaceIntegration::query()->updateOrCreate(
            [
                'community_space_id' => $space->id,
                'provider' => $validated['provider'],
            ],
            [
                'enabled' => (bool) ($validated['enabled'] ?? false),
                'consent_documented' => (bool) $validated['consent_documented'],
                'identity_mapping' => $validated['identity_mapping'],
                'moderation_boundary' => $validated['moderation_boundary'],
                'config' => $config ?: null,
                'configured_by' => $actor->id,
                'configured_at' => now(),
            ],
        );

        $this->audit($actor, 'community_space.integration_configured', $space, [
            'provider' => $integration->provider,
            'enabled' => $integration->enabled,
        ]);

        return $integration;
    }

    /**
     * @return array{purged: int}
     */
    public function purgeExpired(User $actor, ?int $branchId = null): array
    {
        $this->assertCan($actor, 'community_spaces.manage');

        $query = CommunitySpaceMessage::query()
            ->whereNotNull('retain_until')
            ->where('retain_until', '<', now())
            ->where('status', '!=', CommunitySpaceMessage::STATUS_REMOVED);

        if ($branchId !== null) {
            $query->whereHas('space', fn (Builder $q) => $q->where('branch_id', $branchId));
        }

        $purged = 0;
        foreach ($query->limit(200)->get() as $message) {
            $message->update([
                'status' => CommunitySpaceMessage::STATUS_REMOVED,
                'body' => null,
                'attachments' => null,
                'poll_options' => null,
                'removed_at' => now(),
                'removal_reason' => 'retention_expired',
            ]);
            $purged++;
        }

        return ['purged' => $purged];
    }

    /**
     * @return array<string, mixed>
     */
    public function formatSpace(CommunitySpace $space): array
    {
        return [
            'id' => $space->id,
            'reference' => $space->reference,
            'name' => $space->name,
            'space_type' => $space->space_type,
            'branch_id' => $space->branch_id,
            'branch' => $space->relationLoaded('branch') ? $space->branch : null,
            'status' => $space->status,
            'retention_days' => $space->retention_days,
            'requires_consent' => $space->requires_consent,
            'description' => $space->description,
            'memberships' => $space->relationLoaded('memberships')
                ? $space->memberships->map(fn (CommunitySpaceMembership $m) => [
                    'id' => $m->id,
                    'user_id' => $m->user_id,
                    'user' => $m->relationLoaded('user') ? $m->user : null,
                    'role' => $m->role,
                    'status' => $m->status,
                    'joined_at' => $m->joined_at?->toIso8601String(),
                ])->values()->all()
                : [],
            'integrations' => $space->relationLoaded('integrations')
                ? $space->integrations->map(fn (CommunitySpaceIntegration $i) => [
                    'id' => $i->id,
                    'provider' => $i->provider,
                    'enabled' => $i->enabled,
                    'consent_documented' => $i->consent_documented,
                    'moderation_boundary' => $i->moderation_boundary,
                    'configured_at' => $i->configured_at?->toIso8601String(),
                ])->values()->all()
                : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formatMessage(CommunitySpaceMessage $message): array
    {
        $removed = $message->status === CommunitySpaceMessage::STATUS_REMOVED;

        return [
            'id' => $message->id,
            'community_space_id' => $message->community_space_id,
            'message_type' => $message->message_type,
            'body' => $removed ? null : $message->body,
            'attachments' => $removed ? null : $message->attachments,
            'poll_options' => $removed ? null : $message->poll_options,
            'status' => $message->status,
            'is_pinned' => $message->is_pinned,
            'is_sensitive' => $message->is_sensitive,
            'sender_user_id' => $message->sender_user_id,
            'sender' => $message->relationLoaded('sender') ? $message->sender : null,
            'created_at' => $message->created_at?->toIso8601String(),
            'pinned_at' => $message->pinned_at?->toIso8601String(),
            'removed_at' => $message->removed_at?->toIso8601String(),
            'preview' => $removed ? null : Str::limit(strip_tags((string) $message->body), 120),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $attachments
     * @param  array<string, mixed>  $typeConfig
     * @return array<int, array<string, mixed>>
     */
    private function validateAttachments(string $type, array $attachments, array $typeConfig): array
    {
        if ($attachments === []) {
            return [];
        }
        if (empty($typeConfig['allows_attachments'])) {
            throw new CommunitySpaceException('Attachments are not allowed for this message type.', 'attachments_not_allowed', 422);
        }

        $allowedMime = $typeConfig['allowed_mime'] ?? [];
        $maxBytes = (int) ($typeConfig['max_bytes'] ?? 0);
        $clean = [];

        foreach ($attachments as $index => $file) {
            $mime = (string) ($file['mime'] ?? '');
            $size = (int) ($file['size_bytes'] ?? 0);
            if ($allowedMime !== [] && ! in_array($mime, $allowedMime, true)) {
                throw new CommunitySpaceException("Attachment {$index} has an unsupported file type.", 'invalid_mime', 422, [
                    'allowed' => $allowedMime,
                ]);
            }
            if ($maxBytes > 0 && $size > $maxBytes) {
                throw new CommunitySpaceException("Attachment {$index} exceeds the size limit.", 'file_too_large', 422, [
                    'max_bytes' => $maxBytes,
                ]);
            }
            $clean[] = [
                'name' => (string) $file['name'],
                'mime' => $mime,
                'size_bytes' => $size,
                'storage_key' => $file['storage_key'] ?? null,
            ];
        }

        return $clean;
    }

    private function upsertMembership(CommunitySpace $space, User $user, string $role): CommunitySpaceMembership
    {
        $member = $this->memberForUser($user);

        return CommunitySpaceMembership::query()->updateOrCreate(
            [
                'community_space_id' => $space->id,
                'user_id' => $user->id,
            ],
            [
                'member_id' => $member?->id,
                'role' => $role,
                'status' => CommunitySpaceMembership::STATUS_ACTIVE,
                'joined_at' => now(),
                'moderated_at' => null,
                'moderated_by' => null,
                'moderation_reason' => null,
            ],
        );
    }

    private function membershipFor(User $user, CommunitySpace $space): ?CommunitySpaceMembership
    {
        return CommunitySpaceMembership::query()
            ->where('community_space_id', $space->id)
            ->where('user_id', $user->id)
            ->first();
    }

    private function memberForUser(User $user): ?Member
    {
        return Member::query()->where('user_id', $user->id)->whereNull('archived_at')->first();
    }

    private function assertModerator(User $actor, CommunitySpace $space): void
    {
        if ($this->isModerator($actor, $space)) {
            return;
        }

        throw new CommunitySpaceException('Moderator privileges required.', 'moderator_required', 403);
    }

    private function isModerator(User $actor, CommunitySpace $space): bool
    {
        $membership = $this->membershipFor($actor, $space);
        if (
            $membership !== null
            && $membership->role === CommunitySpaceMembership::ROLE_MODERATOR
            && $membership->status === CommunitySpaceMembership::STATUS_ACTIVE
        ) {
            return true;
        }

        return $actor->isChurchWide() && $this->authorization->allows($actor, 'community_spaces.moderate');
    }

    private function assertModeratorAction(User $actor, CommunitySpace $space, CommunitySpaceMessage $message): void
    {
        $this->assertCan($actor, 'community_spaces.moderate');
        $this->assertCanAccessSpace($actor, $space);
        $this->assertModerator($actor, $space);
        $this->assertMessageInSpace($space, $message);
    }

    private function assertMessageInSpace(CommunitySpace $space, CommunitySpaceMessage $message): void
    {
        if ((int) $message->community_space_id !== (int) $space->id) {
            throw new CommunitySpaceException('Message does not belong to this space.', 'message_mismatch', 404);
        }
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function recordModeration(
        CommunitySpace $space,
        User $actor,
        string $action,
        ?CommunitySpaceMessage $message = null,
        ?User $target = null,
        ?string $reason = null,
        array $details = [],
    ): CommunitySpaceModerationEvent {
        return CommunitySpaceModerationEvent::create([
            'community_space_id' => $space->id,
            'community_space_message_id' => $message?->id,
            'target_user_id' => $target?->id,
            'actor_user_id' => $actor->id,
            'action' => $action,
            'reason' => $reason,
            'details' => $details ?: null,
            'occurred_at' => now(),
        ]);
    }

    private function applyAccessibleSpaces(Builder $query, User $actor): void
    {
        if ($actor->isChurchWide() && $this->authorization->allows($actor, 'community_spaces.manage')) {
            $this->applyBranchScope($query, $actor);

            return;
        }

        $query->where(function (Builder $outer) use ($actor): void {
            $outer->whereHas('memberships', function (Builder $m) use ($actor): void {
                $m->where('user_id', $actor->id)
                    ->where('status', '!=', CommunitySpaceMembership::STATUS_LEFT);
            });
        });

        $this->applyBranchScope($query, $actor);
    }

    private function assertCanAccessSpace(User $actor, CommunitySpace $space): void
    {
        if ($space->branch_id !== null && ! $this->isInBranchScope($actor, (int) $space->branch_id)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }

        if ($actor->isChurchWide() && $this->authorization->allows($actor, 'community_spaces.manage')) {
            return;
        }

        $membership = $this->membershipFor($actor, $space);
        if ($membership === null || $membership->status === CommunitySpaceMembership::STATUS_LEFT) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function audit(User $actor, string $action, CommunitySpace $space, array $after = []): void
    {
        $this->audit->record(
            actor: $actor,
            action: $action,
            category: AuditEvent::CATEGORY_BUSINESS,
            module: 'communications',
            branchId: $space->branch_id,
            subjectType: CommunitySpace::class,
            subjectId: $space->id,
            after: array_merge([
                'reference' => $space->reference,
                'status' => $space->status,
            ], $after),
        );
    }

    private function applyBranchScope(Builder $query, User $actor): void
    {
        if ($actor->isChurchWide()) {
            return;
        }

        try {
            $scope = BranchScope::for($actor);
            $query->where(function (Builder $inner) use ($scope): void {
                $inner->whereNull('branch_id')
                    ->orWhereIn('branch_id', $scope->subtreeIds((int) $scope->branchId()));
            });
        } catch (BranchScopeException) {
            $query->whereRaw('1 = 0');
        }
    }

    private function assertBranchWritable(User $actor, int $branchId): void
    {
        if (! $this->isInBranchScope($actor, $branchId)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function isInBranchScope(User $actor, int $branchId): bool
    {
        if ($actor->isChurchWide()) {
            return true;
        }

        try {
            return BranchScope::for($actor)->includes($branchId);
        } catch (BranchScopeException) {
            return false;
        }
    }

    private function assertCan(User $actor, string $action): void
    {
        if (! $this->authorization->allows($actor, $action)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }
}
