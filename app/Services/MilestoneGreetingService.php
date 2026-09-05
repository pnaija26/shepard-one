<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Communication;
use App\Models\CommunicationDelivery;
use App\Models\CommunicationSuppression;
use App\Models\Member;
use App\Models\MemberMilestone;
use App\Models\MemberNotification;
use App\Models\MessageTemplate;
use App\Models\MilestoneGreetingConfig;
use App\Models\MilestoneGreetingEvaluation;
use App\Models\Organization;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Story 10.4: automate birthday and anniversary greetings once per period.
 */
class MilestoneGreetingService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
        private MessageTemplateService $templates,
        private CommunicationService $communications,
    ) {
    }

    /**
     * @return Collection<int, MilestoneGreetingConfig>
     */
    public function listConfigs(User $actor): Collection
    {
        $this->assertCan($actor, 'milestone_greetings.read');

        $query = MilestoneGreetingConfig::query()
            ->with(['branch:id,name', 'template:id,name,scenario,channel,status,current_version'])
            ->orderBy('milestone_type');
        $this->applyBranchScope($query, $actor);

        return $query->limit(100)->get();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function upsertConfig(User $actor, array $payload): MilestoneGreetingConfig
    {
        $this->assertCan($actor, 'milestone_greetings.manage');

        $validated = validator($payload, [
            'branch_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'milestone_type' => ['required', 'string', 'in:' . implode(',', array_keys(config('milestone_greetings.types', [])))],
            'message_template_id' => ['required', 'integer', 'exists:message_templates,id'],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => ['string', 'in:email,sms,push,in_app,external'],
            'enabled' => ['nullable', 'boolean'],
            'team_alerts_enabled' => ['nullable', 'boolean'],
            'team_alert_permission' => ['nullable', 'string', 'max:80'],
        ])->validate();

        if (! empty($validated['branch_id'])) {
            $this->assertBranchWritable($actor, (int) $validated['branch_id']);
        }

        $template = MessageTemplate::query()->findOrFail((int) $validated['message_template_id']);
        if ($template->status !== MessageTemplate::STATUS_PUBLISHED) {
            throw new MilestoneGreetingException('Greeting template must be published.', 'template_not_published', 422);
        }

        $expectedScenario = config('milestone_greetings.types.' . $validated['milestone_type'] . '.template_scenario');
        if ($expectedScenario && $template->scenario !== $expectedScenario && $template->scenario !== 'custom') {
            throw new MilestoneGreetingException(
                "Template scenario must be {$expectedScenario} or custom for this milestone.",
                'scenario_mismatch',
                422,
            );
        }

        $config = MilestoneGreetingConfig::query()->updateOrCreate(
            [
                'branch_id' => $validated['branch_id'] ?? null,
                'milestone_type' => $validated['milestone_type'],
            ],
            [
                'message_template_id' => $template->id,
                'channels' => array_values($validated['channels']),
                'enabled' => (bool) ($validated['enabled'] ?? true),
                'team_alerts_enabled' => (bool) ($validated['team_alerts_enabled'] ?? true),
                'team_alert_permission' => $validated['team_alert_permission'] ?? 'communications.read',
                'updated_by' => $actor->id,
                'created_by' => $actor->id,
            ],
        );

        $this->audit->record(
            actor: $actor,
            action: 'milestone_greeting.config_saved',
            category: AuditEvent::CATEGORY_BUSINESS,
            module: 'communications',
            branchId: $config->branch_id,
            subjectType: MilestoneGreetingConfig::class,
            subjectId: $config->id,
            after: [
                'milestone_type' => $config->milestone_type,
                'message_template_id' => $config->message_template_id,
                'enabled' => $config->enabled,
            ],
        );

        return $config->fresh(['branch', 'template']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function upsertMemberMilestone(User $actor, Member $member, array $payload): MemberMilestone
    {
        $this->assertCan($actor, 'milestone_greetings.manage');
        if (! $this->isInBranchScope($actor, (int) $member->branch_id)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }

        $types = array_keys(array_filter(
            config('milestone_greetings.types', []),
            fn ($meta) => ($meta['source'] ?? '') === 'milestone',
        ));

        $validated = validator($payload, [
            'type' => ['required', 'string', 'in:' . implode(',', $types)],
            'occurred_on' => ['required', 'date'],
            'active' => ['nullable', 'boolean'],
            'metadata' => ['nullable', 'array'],
        ])->validate();

        $occurred = Carbon::parse($validated['occurred_on']);
        if ($occurred->isFuture()) {
            throw new MilestoneGreetingException('Milestone date cannot be in the future.', 'invalid_date', 422);
        }

        $milestone = MemberMilestone::query()->updateOrCreate(
            [
                'member_id' => $member->id,
                'type' => $validated['type'],
            ],
            [
                'occurred_on' => $occurred->toDateString(),
                'active' => (bool) ($validated['active'] ?? true),
                'metadata' => $validated['metadata'] ?? null,
                'updated_by' => $actor->id,
                'created_by' => $actor->id,
            ],
        );

        return $milestone;
    }

    /**
     * Run detection for a given day (defaults to today).
     *
     * @return array{evaluated: int, sent: int, skipped: int, failed: int, list: array<int, array<string, mixed>>}
     */
    public function processWindow(User $actor, ?string $onDate = null, ?int $branchId = null): array
    {
        $this->assertCan($actor, 'milestone_greetings.process');

        $on = $onDate ? Carbon::parse($onDate)->startOfDay() : now()->startOfDay();
        $window = (int) config('milestone_greetings.detection_window_days', 0);
        $periodKey = $on->format('Y');

        $counts = ['evaluated' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0, 'list' => []];

        $configQuery = MilestoneGreetingConfig::query()
            ->with('template')
            ->where('enabled', true);
        if ($branchId !== null) {
            $configQuery->where(function (Builder $q) use ($branchId): void {
                $q->whereNull('branch_id')->orWhere('branch_id', $branchId);
            });
        } else {
            $this->applyBranchScope($configQuery, $actor);
        }

        foreach ($configQuery->get() as $config) {
            $candidates = $this->candidatesFor($config, $on, $window);
            foreach ($candidates as $candidate) {
                $counts['evaluated']++;
                $outcome = $this->evaluateOne($actor, $config, $candidate['member'], $candidate['occurred_on'], $periodKey, $on);
                $counts[$outcome['count_key']]++;
                if ($outcome['list_row'] !== null) {
                    $counts['list'][] = $outcome['list_row'];
                }
            }
        }

        $this->audit->record(
            actor: $actor,
            action: 'milestone_greeting.window_processed',
            category: AuditEvent::CATEGORY_BUSINESS,
            module: 'communications',
            branchId: $branchId,
            subjectType: MilestoneGreetingConfig::class,
            subjectId: null,
            after: [
                'on' => $on->toDateString(),
                'evaluated' => $counts['evaluated'],
                'sent' => $counts['sent'],
                'skipped' => $counts['skipped'],
                'failed' => $counts['failed'],
            ],
        );

        return $counts;
    }

    /**
     * Birthday/anniversary list with only approved fields.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForDate(User $actor, ?string $onDate = null, ?string $type = null): array
    {
        $this->assertCan($actor, 'milestone_greetings.read');

        $on = $onDate ? Carbon::parse($onDate)->startOfDay() : now()->startOfDay();
        $window = (int) config('milestone_greetings.detection_window_days', 0);

        $rows = [];
        $types = $type
            ? [$type => config('milestone_greetings.types.' . $type)]
            : config('milestone_greetings.types', []);

        foreach ($types as $milestoneType => $meta) {
            if (! is_array($meta)) {
                continue;
            }
            $fakeConfig = new MilestoneGreetingConfig([
                'milestone_type' => $milestoneType,
                'branch_id' => $actor->isChurchWide() ? null : $actor->branch_id,
            ]);
            foreach ($this->candidatesFor($fakeConfig, $on, $window) as $candidate) {
                $member = $candidate['member'];
                if (! $this->isInBranchScope($actor, (int) $member->branch_id)) {
                    continue;
                }
                $rows[] = $this->approvedListRow($member, $milestoneType, $candidate['occurred_on'], $on);
            }
        }

        usort($rows, fn ($a, $b) => strcmp($a['preferred_name'] ?? $a['first_name'], $b['preferred_name'] ?? $b['first_name']));

        return $rows;
    }

    /**
     * @return Collection<int, MilestoneGreetingEvaluation>
     */
    public function listEvaluations(User $actor, array $filters = []): Collection
    {
        $this->assertCan($actor, 'milestone_greetings.read');

        $query = MilestoneGreetingEvaluation::query()
            ->with(['member:id,first_name,preferred_name,membership_id,branch_id'])
            ->orderByDesc('evaluated_at')
            ->orderByDesc('id');

        if (! empty($filters['outcome'])) {
            $query->where('outcome', $filters['outcome']);
        }
        if (! empty($filters['milestone_type'])) {
            $query->where('milestone_type', $filters['milestone_type']);
        }
        if (! empty($filters['period_key'])) {
            $query->where('period_key', $filters['period_key']);
        }

        if (! $actor->isChurchWide()) {
            try {
                $scope = BranchScope::for($actor);
                $query->whereIn('branch_id', $scope->subtreeIds((int) $scope->branchId()));
            } catch (BranchScopeException) {
                $query->whereRaw('1 = 0');
            }
        }

        return $query->limit(100)->get();
    }

    /**
     * @return array<string, mixed>
     */
    public function formatConfig(MilestoneGreetingConfig $config): array
    {
        return [
            'id' => $config->id,
            'branch_id' => $config->branch_id,
            'branch' => $config->relationLoaded('branch') ? $config->branch : null,
            'milestone_type' => $config->milestone_type,
            'milestone_label' => config('milestone_greetings.types.' . $config->milestone_type . '.label', $config->milestone_type),
            'message_template_id' => $config->message_template_id,
            'template' => $config->relationLoaded('template') ? $config->template : null,
            'channels' => $config->channels,
            'enabled' => $config->enabled,
            'team_alerts_enabled' => $config->team_alerts_enabled,
            'team_alert_permission' => $config->team_alert_permission,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formatEvaluation(MilestoneGreetingEvaluation $evaluation): array
    {
        return [
            'id' => $evaluation->id,
            'member_id' => $evaluation->member_id,
            'member' => $evaluation->relationLoaded('member') ? [
                'id' => $evaluation->member?->id,
                'membership_id' => $evaluation->member?->membership_id,
                'first_name' => $evaluation->member?->first_name,
                'preferred_name' => $evaluation->member?->preferred_name,
            ] : null,
            'milestone_type' => $evaluation->milestone_type,
            'period_key' => $evaluation->period_key,
            'outcome' => $evaluation->outcome,
            'skip_reason' => $evaluation->skip_reason,
            'communication_id' => $evaluation->communication_id,
            'message_template_version_id' => $evaluation->message_template_version_id,
            'result' => $evaluation->result,
            'evaluated_at' => $evaluation->evaluated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<int, array{member: Member, occurred_on: Carbon}>
     */
    private function candidatesFor(MilestoneGreetingConfig $config, Carbon $on, int $window): array
    {
        $type = $config->milestone_type;
        $meta = config('milestone_greetings.types.' . $type);
        if (! is_array($meta)) {
            return [];
        }

        $dates = [];
        for ($offset = -$window; $offset <= $window; $offset++) {
            $dates[] = $on->copy()->addDays($offset);
        }

        $candidates = [];
        $branchFilter = $config->branch_id;

        if (($meta['source'] ?? '') === 'date_of_birth') {
            $query = Member::query()
                ->whereNull('archived_at')
                ->whereNull('merged_into_id')
                ->whereNotNull('date_of_birth');
            if ($branchFilter) {
                $query->where('branch_id', $branchFilter);
            }

            foreach ($query->limit(5000)->get() as $member) {
                $dob = $member->date_of_birth;
                if ($dob === null) {
                    continue;
                }
                foreach ($dates as $day) {
                    if ((int) $dob->month === (int) $day->month && (int) $dob->day === (int) $day->day) {
                        $candidates[] = ['member' => $member, 'occurred_on' => $dob->copy()];
                        break;
                    }
                }
            }

            return $candidates;
        }

        $query = MemberMilestone::query()
            ->with('member')
            ->where('type', $type)
            ->where('active', true);
        if ($branchFilter) {
            $query->whereHas('member', fn (Builder $q) => $q->where('branch_id', $branchFilter));
        }

        foreach ($query->limit(5000)->get() as $milestone) {
            $member = $milestone->member;
            if ($member === null || $member->archived_at !== null || $member->merged_into_id !== null) {
                continue;
            }
            $occurred = $milestone->occurred_on;
            foreach ($dates as $day) {
                if ((int) $occurred->month === (int) $day->month && (int) $occurred->day === (int) $day->day) {
                    $candidates[] = ['member' => $member, 'occurred_on' => $occurred->copy()];
                    break;
                }
            }
        }

        return $candidates;
    }

    /**
     * @return array{count_key: string, list_row: ?array<string, mixed>}
     */
    private function evaluateOne(
        User $actor,
        MilestoneGreetingConfig $config,
        Member $member,
        Carbon $occurredOn,
        string $periodKey,
        Carbon $on,
    ): array {
        $existing = MilestoneGreetingEvaluation::query()
            ->where('member_id', $member->id)
            ->where('milestone_type', $config->milestone_type)
            ->where('period_key', $periodKey)
            ->first();

        if ($existing !== null) {
            return [
                'count_key' => 'skipped',
                'list_row' => $existing->outcome === MilestoneGreetingEvaluation::OUTCOME_SENT
                    ? $this->approvedListRow($member, $config->milestone_type, $occurredOn, $on)
                    : null,
            ];
        }

        $skip = $this->skipReason($member, $config);
        if ($skip['reason'] !== null) {
            $this->recordEvaluation($actor, $config, $member, $periodKey, MilestoneGreetingEvaluation::OUTCOME_SKIPPED, $skip['reason']);

            return ['count_key' => 'skipped', 'list_row' => null];
        }

        $channels = $skip['channels'];

        try {
            $years = max(0, $occurredOn->diffInYears($on));
            $branchName = Organization::query()->where('id', $member->branch_id)->value('name') ?? '';
            $rendered = $this->templates->renderForSend($config->template, [
                'first_name' => $member->first_name,
                'last_name' => $member->last_name,
                'preferred_name' => $member->preferred_name ?: $member->first_name,
                'branch_name' => $branchName,
                'church_name' => 'ShepardOne Church',
                'date' => $on->toDateString(),
                'years' => (string) $years,
            ]);

            $communication = $this->communications->create($actor, [
                'name' => ucfirst($config->milestone_type) . ' greeting · ' . ($member->preferred_name ?: $member->first_name),
                'subject' => $rendered['subject'] ?: (ucfirst($config->milestone_type) . ' greeting'),
                'body' => $rendered['body'],
                'purpose' => 'engagement',
                'channels' => $channels,
                'audience_type' => 'members',
                'audience_params' => ['member_ids' => [$member->id]],
                'schedule_type' => 'immediate',
                'branch_id' => $member->branch_id,
                'source_type' => MilestoneGreetingConfig::class,
                'source_id' => $config->id,
            ]);

            CommunicationDelivery::query()
                ->where('communication_id', $communication->id)
                ->update(['message_template_version_id' => $rendered['version_id']]);

            $this->recordEvaluation(
                $actor,
                $config,
                $member,
                $periodKey,
                MilestoneGreetingEvaluation::OUTCOME_SENT,
                null,
                $communication->id,
                $rendered['version_id'],
                ['channels' => $channels],
            );

            if ($config->team_alerts_enabled) {
                $this->sendTeamAlerts($actor, $config, $member, $occurredOn, $on, $years);
            }

            return [
                'count_key' => 'sent',
                'list_row' => $this->approvedListRow($member, $config->milestone_type, $occurredOn, $on, $years),
            ];
        } catch (\Throwable $exception) {
            $this->recordEvaluation(
                $actor,
                $config,
                $member,
                $periodKey,
                MilestoneGreetingEvaluation::OUTCOME_FAILED,
                'send_failed',
                null,
                null,
                [
                    'error_class' => class_basename($exception),
                    'message' => Str::limit($exception->getMessage(), 160),
                ],
            );

            return ['count_key' => 'failed', 'list_row' => null];
        }
    }

    /**
     * @return array{reason: ?string, channels: array<int, string>}
     */
    private function skipReason(Member $member, MilestoneGreetingConfig $config): array
    {
        $excluded = config('milestone_greetings.excluded_lifecycle_statuses', []);
        if (in_array($member->lifecycle_status, $excluded, true)) {
            return ['reason' => 'excluded_status', 'channels' => []];
        }

        $policy = config('members.lifecycle.status_policies.' . ($member->lifecycle_status ?? 'active') . '.communications', 'enabled');
        if ($policy === 'none') {
            return ['reason' => 'lifecycle_blocked', 'channels' => []];
        }

        if (! $member->consent_data_processing) {
            return ['reason' => 'missing_consent', 'channels' => []];
        }

        $prefs = $member->communication_preferences ?? [];
        $channels = $config->channels ?? [];
        $usable = [];
        foreach ($channels as $channel) {
            if ($prefs !== [] && array_key_exists($channel, $prefs) && ! $prefs[$channel]) {
                continue;
            }
            if ($this->isSuppressed($member->id, $channel)) {
                continue;
            }
            $destination = match ($channel) {
                'email', 'external' => $member->email,
                'sms' => $member->phone,
                'push', 'in_app' => $member->user_id ? ('user:' . $member->user_id) : null,
                default => null,
            };
            if ($destination) {
                $usable[] = $channel;
            }
        }

        if ($usable === []) {
            return ['reason' => 'missing_destination', 'channels' => []];
        }

        if ($config->template === null || $config->template->status !== MessageTemplate::STATUS_PUBLISHED) {
            return ['reason' => 'template_unavailable', 'channels' => []];
        }

        if ($member->date_of_birth === null && $config->milestone_type === 'birthday') {
            return ['reason' => 'invalid_date', 'channels' => []];
        }

        return ['reason' => null, 'channels' => $usable];
    }

    private function isSuppressed(int $memberId, string $channel): bool
    {
        return CommunicationSuppression::query()
            ->where('member_id', $memberId)
            ->where('active', true)
            ->where(function (Builder $q) use ($channel): void {
                $q->whereNull('channel')->orWhere('channel', $channel);
            })
            ->exists();
    }

    private function sendTeamAlerts(
        User $actor,
        MilestoneGreetingConfig $config,
        Member $member,
        Carbon $occurredOn,
        Carbon $on,
        int $years,
    ): void {
        $permission = $config->team_alert_permission ?: 'communications.read';
        $roleIds = RolePermission::query()->where('action', $permission)->pluck('role_id');
        $userIds = RoleAssignment::query()
            ->whereIn('role_id', $roleIds)
            ->where(function (Builder $q): void {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->pluck('user_id')
            ->unique()
            ->filter(fn ($id) => (int) $id !== (int) ($member->user_id ?? 0))
            ->values();

        $listRow = $this->approvedListRow($member, $config->milestone_type, $occurredOn, $on, $years);
        $message = ($listRow['preferred_name'] ?: $listRow['first_name'])
            . ' · ' . ($listRow['milestone_label'] ?? $config->milestone_type)
            . ($years > 0 ? " · {$years} years" : '');

        foreach ($userIds as $userId) {
            $user = User::query()->find($userId);
            if ($user === null) {
                continue;
            }
            if (! $this->authorization->allows($user, $permission)) {
                continue;
            }
            $alertMember = Member::query()->where('user_id', $user->id)->first();
            if ($alertMember === null) {
                continue;
            }

            MemberNotification::create([
                'member_id' => $alertMember->id,
                'user_id' => $user->id,
                'type' => 'birthday.team_alert',
                'category' => 'birthday',
                'message' => $message,
                'metadata' => $listRow, // approved fields only
                'deep_link' => '/milestone-greetings',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function approvedListRow(Member $member, string $type, Carbon $occurredOn, Carbon $on, ?int $years = null): array
    {
        $years ??= max(0, $occurredOn->diffInYears($on));
        $branchName = Organization::query()->where('id', $member->branch_id)->value('name');

        $row = [
            'member_id' => $member->id,
            'membership_id' => $member->membership_id,
            'preferred_name' => $member->preferred_name,
            'first_name' => $member->first_name,
            'branch_id' => $member->branch_id,
            'branch_name' => $branchName,
            'milestone_type' => $type,
            'milestone_label' => config('milestone_greetings.types.' . $type . '.label', $type),
            'occurrence_date' => $occurredOn->format('m-d'),
            'years' => $years,
        ];

        $allowed = config('milestone_greetings.approved_list_fields', array_keys($row));

        return array_intersect_key($row, array_flip($allowed));
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function recordEvaluation(
        User $actor,
        MilestoneGreetingConfig $config,
        Member $member,
        string $periodKey,
        string $outcome,
        ?string $skipReason,
        ?int $communicationId = null,
        ?int $templateVersionId = null,
        array $result = [],
    ): void {
        MilestoneGreetingEvaluation::query()->firstOrCreate(
            [
                'member_id' => $member->id,
                'milestone_type' => $config->milestone_type,
                'period_key' => $periodKey,
            ],
            [
                'outcome' => $outcome,
                'skip_reason' => $skipReason,
                'milestone_greeting_config_id' => $config->id,
                'communication_id' => $communicationId,
                'message_template_version_id' => $templateVersionId,
                'result' => $result,
                'branch_id' => $member->branch_id,
                'actor_id' => $actor->id,
                'evaluated_at' => now(),
            ],
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
