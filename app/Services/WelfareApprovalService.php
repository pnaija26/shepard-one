<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Member;
use App\Models\MemberNotification;
use App\Models\User;
use App\Models\WelfareApprovalConfig;
use App\Models\WelfareApprovalConfigVersion;
use App\Models\WelfareApprovalDecision;
use App\Models\WelfareApprovalStep;
use App\Models\WelfareAssessmentVersion;
use App\Models\WelfareCaseEvent;
use App\Models\WelfareRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Story 7.3: route welfare approvals by configured monetary thresholds.
 */
class WelfareApprovalService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
    ) {
    }

    /**
     * Ensure a published church-wide config exists (bootstrap for tests / first use).
     */
    public function ensureDefaultPublishedConfig(?User $actor = null): WelfareApprovalConfig
    {
        $config = WelfareApprovalConfig::query()
            ->whereNull('branch_id')
            ->where('status', WelfareApprovalConfig::STATUS_PUBLISHED)
            ->orderByDesc('id')
            ->first();

        if ($config !== null) {
            return $config;
        }

        return DB::transaction(function () use ($actor): WelfareApprovalConfig {
            $config = WelfareApprovalConfig::create([
                'name' => 'Default welfare approval thresholds',
                'branch_id' => null,
                'status' => WelfareApprovalConfig::STATUS_DRAFT,
                'current_version' => 0,
                'created_by' => $actor?->id,
                'updated_by' => $actor?->id,
            ]);

            $version = WelfareApprovalConfigVersion::create([
                'welfare_approval_config_id' => $config->id,
                'version' => 1,
                'status' => WelfareApprovalConfigVersion::STATUS_DRAFT,
                'thresholds' => config('welfare_approvals.default_thresholds', []),
                'created_by' => $actor?->id,
            ]);

            $version->update([
                'status' => WelfareApprovalConfigVersion::STATUS_PUBLISHED,
                'published_by' => $actor?->id,
                'published_at' => now(),
            ]);

            $config->update([
                'status' => WelfareApprovalConfig::STATUS_PUBLISHED,
                'current_version' => 1,
                'published_at' => now(),
                'updated_by' => $actor?->id,
            ]);

            return $config->fresh(['versions']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function publishConfig(User $actor, array $payload, ?WelfareApprovalConfig $config = null): WelfareApprovalConfig
    {
        $this->assertCan($actor, 'welfare.approvals.configure');

        $validated = validator($payload, [
            'name' => ['nullable', 'string', 'max:160'],
            'branch_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'thresholds' => ['required', 'array', 'min:1'],
            'thresholds.*.max_value' => ['nullable', 'numeric', 'min:0'],
            'thresholds.*.levels' => ['required', 'array', 'min:1'],
            'thresholds.*.levels.*' => ['string', 'in:' . implode(',', config('welfare_approvals.levels', []))],
        ])->validate();

        $this->assertThresholdsValid($validated['thresholds']);

        return DB::transaction(function () use ($actor, $validated, $config): WelfareApprovalConfig {
            if ($config === null) {
                $config = WelfareApprovalConfig::create([
                    'name' => $validated['name'] ?? 'Welfare approval thresholds',
                    'branch_id' => $validated['branch_id'] ?? null,
                    'status' => WelfareApprovalConfig::STATUS_DRAFT,
                    'current_version' => 0,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);
                $nextVersion = 1;
            } else {
                $nextVersion = ((int) $config->current_version) + 1;
            }

            $version = WelfareApprovalConfigVersion::create([
                'welfare_approval_config_id' => $config->id,
                'version' => $nextVersion,
                'status' => WelfareApprovalConfigVersion::STATUS_PUBLISHED,
                'thresholds' => $this->normalizeThresholds($validated['thresholds']),
                'published_by' => $actor->id,
                'published_at' => now(),
                'created_by' => $actor->id,
            ]);

            $config->update([
                'name' => $validated['name'] ?? $config->name,
                'branch_id' => $validated['branch_id'] ?? $config->branch_id,
                'status' => WelfareApprovalConfig::STATUS_PUBLISHED,
                'current_version' => $version->version,
                'published_at' => now(),
                'updated_by' => $actor->id,
            ]);

            $this->audit($actor, 'welfare_approval_config.published', $config->branch_id, WelfareApprovalConfig::class, $config->id, [
                'version' => $version->version,
            ]);

            return $config->fresh(['versions']);
        });
    }

    /**
     * Create approval sequence from effective published thresholds for a completed assessment.
     */
    public function createRouting(User $actor, WelfareRequest $request): WelfareRequest
    {
        if ($request->status !== WelfareRequest::STATUS_PENDING_REVIEW
            && $request->status !== WelfareRequest::STATUS_PENDING_APPROVAL) {
            throw new WelfareRequestException('Approval routing requires a completed recommendation.', 'invalid_status', 422);
        }

        $assessment = WelfareAssessmentVersion::query()
            ->where('welfare_request_id', $request->id)
            ->where('complete', true)
            ->orderByDesc('version')
            ->first();

        if ($assessment === null) {
            throw new WelfareRequestException('No complete assessment recommendation found.', 'missing_assessment', 422);
        }

        if (! in_array($assessment->recommendation, ['approve', 'partial_approve'], true)) {
            $request->update([
                'status' => WelfareRequest::STATUS_REJECTED,
                'approval_status' => 'closed_without_routing',
                'beneficiary_status_message' => config('welfare_approvals.beneficiary_status_messages.rejected'),
                'updated_by' => $actor->id,
            ]);

            return $request->fresh(['approvalSteps', 'approvalDecisions']);
        }

        $configVersion = $this->resolveEffectiveConfigVersion((int) $request->branch_id);
        $levels = $this->levelsForValue((float) $assessment->proposed_value, $configVersion->thresholds ?? []);

        return DB::transaction(function () use ($actor, $request, $configVersion, $levels, $assessment): WelfareRequest {
            // Replace only if no completed decisions yet.
            $hasCompleted = WelfareApprovalDecision::query()
                ->where('welfare_request_id', $request->id)
                ->exists();

            if ($hasCompleted) {
                throw new WelfareRequestException(
                    'Routing already has decisions; use reevaluate to adjust pending steps.',
                    'routing_locked',
                    422,
                );
            }

            WelfareApprovalStep::query()->where('welfare_request_id', $request->id)->delete();

            foreach ($levels as $index => $level) {
                WelfareApprovalStep::create([
                    'welfare_request_id' => $request->id,
                    'approval_config_version_id' => $configVersion->id,
                    'sequence' => $index + 1,
                    'level' => $level,
                    'status' => WelfareApprovalStep::STATUS_PENDING,
                    'is_current' => $index === 0,
                ]);
            }

            $request->update([
                'approval_config_version_id' => $configVersion->id,
                'current_approval_step' => 1,
                'approval_status' => 'in_progress',
                'status' => WelfareRequest::STATUS_PENDING_APPROVAL,
                'beneficiary_status_message' => config('welfare_approvals.beneficiary_status_messages.pending_approval'),
                'updated_by' => $actor->id,
            ]);

            WelfareCaseEvent::create([
                'welfare_request_id' => $request->id,
                'event_type' => 'approval_routed',
                'notes' => 'Approval sequence created from threshold policy v' . $configVersion->version,
                'beneficiary_message' => config('welfare_approvals.beneficiary_status_messages.pending_approval'),
                'actor_id' => $actor->id,
                'metadata' => [
                    'config_version_id' => $configVersion->id,
                    'config_version' => $configVersion->version,
                    'levels' => $levels,
                    'proposed_value' => $assessment->proposed_value,
                ],
                'created_at' => now(),
            ]);

            $this->audit($actor, 'welfare_request.approval_routed', $request->branch_id, WelfareRequest::class, $request->id, [
                'config_version' => $configVersion->version,
                'levels' => $levels,
            ]);

            return $request->fresh(['approvalSteps', 'approvalConfigVersion', 'approvalDecisions']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function decide(User $actor, WelfareRequest $request, array $payload): WelfareRequest
    {
        $validated = validator($payload, [
            'decision' => ['required', 'string', 'in:' . implode(',', config('welfare_approvals.decisions', []))],
            'reason' => ['required', 'string', 'max:2000'],
        ])->validate();

        $this->assertCanDecide($actor, $request);

        $step = WelfareApprovalStep::query()
            ->where('welfare_request_id', $request->id)
            ->where('is_current', true)
            ->where('status', WelfareApprovalStep::STATUS_PENDING)
            ->first();

        if ($step === null) {
            throw new WelfareRequestException('No pending approval step for this case.', 'no_pending_step', 422);
        }

        $this->assertLevelPermission($actor, $step->level);
        $this->assertNotSelfApproval($actor, $request);
        $this->assertNotAssessorApproval($actor, $request);

        return DB::transaction(function () use ($actor, $request, $step, $validated): WelfareRequest {
            $configVersion = $request->approvalConfigVersion
                ?? WelfareApprovalConfigVersion::query()->findOrFail($request->approval_config_version_id);

            // Immutable decision row
            WelfareApprovalDecision::create([
                'welfare_approval_step_id' => $step->id,
                'welfare_request_id' => $request->id,
                'level' => $step->level,
                'decision' => $validated['decision'],
                'reason' => $validated['reason'],
                'decided_by' => $actor->id,
                'config_version' => $configVersion->version,
                'decided_at' => now(),
                'created_at' => now(),
            ]);

            $step->update([
                'status' => $validated['decision'],
                'is_current' => false,
            ]);

            return match ($validated['decision']) {
                'approved' => $this->advanceAfterApproval($actor, $request, $step),
                'rejected' => $this->closeAsRejected($actor, $request, $validated['reason']),
                'returned' => $this->returnForInfo($actor, $request, $validated['reason']),
                'escalated' => $this->escalateRemaining($actor, $request, $step, $validated['reason']),
                default => throw ValidationException::withMessages(['decision' => ['Unsupported decision.']]),
            };
        });
    }

    /**
     * Reevaluate pending steps after a threshold publish.
     * Completed approvals are retained; pending steps are rebuilt from the locked or new policy.
     *
     * @param  array<string, mixed>  $payload
     */
    public function reevaluate(User $actor, WelfareRequest $request, array $payload = []): WelfareRequest
    {
        $this->assertCan($actor, 'welfare.approvals.configure');

        if (! in_array($request->status, [
            WelfareRequest::STATUS_PENDING_APPROVAL,
            WelfareRequest::STATUS_PENDING_REVIEW,
        ], true)) {
            throw new WelfareRequestException('Only in-flight approval cases can be reevaluated.', 'invalid_status', 422);
        }

        $useNewPolicy = (bool) ($payload['use_published_policy'] ?? false);

        $configVersion = $useNewPolicy
            ? $this->resolveEffectiveConfigVersion((int) $request->branch_id)
            : ($request->approvalConfigVersion
                ?? WelfareApprovalConfigVersion::query()->findOrFail($request->approval_config_version_id));

        $assessment = WelfareAssessmentVersion::query()
            ->where('welfare_request_id', $request->id)
            ->where('complete', true)
            ->orderByDesc('version')
            ->firstOrFail();

        $requiredLevels = $this->levelsForValue((float) $assessment->proposed_value, $configVersion->thresholds ?? []);

        return DB::transaction(function () use ($actor, $request, $configVersion, $requiredLevels, $useNewPolicy): WelfareRequest {
            $completedLevels = WelfareApprovalDecision::query()
                ->where('welfare_request_id', $request->id)
                ->where('decision', 'approved')
                ->orderBy('id')
                ->pluck('level')
                ->all();

            // Never discard completed approvals — verify they remain a prefix of the required sequence.
            foreach ($completedLevels as $index => $level) {
                if (! isset($requiredLevels[$index]) || $requiredLevels[$index] !== $level) {
                    // Keep completed steps; append any still-needed levels after them.
                    break;
                }
            }

            $remainingLevels = array_values(array_filter(
                $requiredLevels,
                fn (string $level) => ! in_array($level, $completedLevels, true),
            ));

            WelfareApprovalStep::query()
                ->where('welfare_request_id', $request->id)
                ->where('status', WelfareApprovalStep::STATUS_PENDING)
                ->delete();

            $sequence = WelfareApprovalStep::query()
                ->where('welfare_request_id', $request->id)
                ->max('sequence') ?? count($completedLevels);

            WelfareApprovalStep::query()
                ->where('welfare_request_id', $request->id)
                ->update(['is_current' => false]);

            if ($remainingLevels === []) {
                $request->update([
                    'approval_config_version_id' => $configVersion->id,
                    'status' => WelfareRequest::STATUS_APPROVED,
                    'approval_status' => 'approved',
                    'current_approval_step' => null,
                    'beneficiary_status_message' => config('welfare_approvals.beneficiary_status_messages.approved'),
                    'updated_by' => $actor->id,
                ]);
            } else {
                foreach ($remainingLevels as $index => $level) {
                    WelfareApprovalStep::create([
                        'welfare_request_id' => $request->id,
                        'approval_config_version_id' => $configVersion->id,
                        'sequence' => $sequence + $index + 1,
                        'level' => $level,
                        'status' => WelfareApprovalStep::STATUS_PENDING,
                        'is_current' => $index === 0,
                    ]);
                }

                $request->update([
                    'approval_config_version_id' => $configVersion->id,
                    'status' => WelfareRequest::STATUS_PENDING_APPROVAL,
                    'approval_status' => 'in_progress',
                    'current_approval_step' => $sequence + 1,
                    'beneficiary_status_message' => config('welfare_approvals.beneficiary_status_messages.pending_approval'),
                    'updated_by' => $actor->id,
                ]);
            }

            WelfareCaseEvent::create([
                'welfare_request_id' => $request->id,
                'event_type' => 'approval_reevaluated',
                'notes' => $useNewPolicy
                    ? 'Pending steps rebuilt from newly published policy; completed approvals retained.'
                    : 'Pending steps rebuilt from locked in-flight policy version.',
                'actor_id' => $actor->id,
                'metadata' => [
                    'config_version' => $configVersion->version,
                    'completed_levels' => $completedLevels,
                    'remaining_levels' => $remainingLevels,
                    'use_published_policy' => $useNewPolicy,
                ],
                'created_at' => now(),
            ]);

            $this->audit($actor, 'welfare_request.approval_reevaluated', $request->branch_id, WelfareRequest::class, $request->id, [
                'config_version' => $configVersion->version,
            ]);

            return $request->fresh(['approvalSteps', 'approvalDecisions', 'approvalConfigVersion']);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function formatRequestApprovals(WelfareRequest $request): array
    {
        $request->loadMissing([
            'approvalSteps',
            'approvalDecisions.decider:id,name',
            'approvalConfigVersion',
        ]);

        return [
            'approval_status' => $request->approval_status,
            'current_approval_step' => $request->current_approval_step,
            'approval_config_version' => $request->approvalConfigVersion ? [
                'id' => $request->approvalConfigVersion->id,
                'version' => $request->approvalConfigVersion->version,
                'thresholds' => $request->approvalConfigVersion->thresholds,
            ] : null,
            'steps' => $request->approvalSteps->sortBy('sequence')->values()->map(fn (WelfareApprovalStep $step) => [
                'id' => $step->id,
                'sequence' => $step->sequence,
                'level' => $step->level,
                'status' => $step->status,
                'is_current' => $step->is_current,
            ])->all(),
            'decisions' => $request->approvalDecisions->map(fn (WelfareApprovalDecision $decision) => [
                'id' => $decision->id,
                'level' => $decision->level,
                'decision' => $decision->decision,
                'reason' => $decision->reason,
                'decided_by' => $decision->decider?->name,
                'config_version' => $decision->config_version,
                'decided_at' => $decision->decided_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    public function formatConfig(WelfareApprovalConfig $config): array
    {
        $config->loadMissing('versions');

        return [
            'id' => $config->id,
            'name' => $config->name,
            'branch_id' => $config->branch_id,
            'status' => $config->status,
            'current_version' => $config->current_version,
            'published_at' => $config->published_at?->toIso8601String(),
            'versions' => $config->versions->sortByDesc('version')->values()->map(fn (WelfareApprovalConfigVersion $version) => [
                'id' => $version->id,
                'version' => $version->version,
                'status' => $version->status,
                'thresholds' => $version->thresholds,
                'published_at' => $version->published_at?->toIso8601String(),
            ])->all(),
        ];
    }

    private function advanceAfterApproval(User $actor, WelfareRequest $request, WelfareApprovalStep $step): WelfareRequest
    {
        $next = WelfareApprovalStep::query()
            ->where('welfare_request_id', $request->id)
            ->where('sequence', '>', $step->sequence)
            ->where('status', WelfareApprovalStep::STATUS_PENDING)
            ->orderBy('sequence')
            ->first();

        if ($next === null) {
            $approvedValue = WelfareAssessmentVersion::query()
                ->where('welfare_request_id', $request->id)
                ->where('complete', true)
                ->orderByDesc('version')
                ->value('proposed_value');

            $request->update([
                'status' => WelfareRequest::STATUS_APPROVED,
                'approval_status' => 'approved',
                'current_approval_step' => null,
                'approved_value' => $approvedValue,
                'beneficiary_status_message' => config('welfare_approvals.beneficiary_status_messages.approved'),
                'updated_by' => $actor->id,
            ]);

            $this->notifyBeneficiary($request, 'welfare.request.approved', config('welfare_approvals.beneficiary_status_messages.approved'));
            $this->audit($actor, 'welfare_request.approved', $request->branch_id, WelfareRequest::class, $request->id);

            return $request->fresh(['approvalSteps', 'approvalDecisions']);
        }

        $next->update(['is_current' => true]);
        $request->update([
            'current_approval_step' => $next->sequence,
            'approval_status' => 'in_progress',
            'status' => WelfareRequest::STATUS_PENDING_APPROVAL,
            'updated_by' => $actor->id,
        ]);

        $this->audit($actor, 'welfare_request.approval_step_approved', $request->branch_id, WelfareRequest::class, $request->id, [
            'level' => $step->level,
            'next_level' => $next->level,
        ]);

        return $request->fresh(['approvalSteps', 'approvalDecisions']);
    }

    private function closeAsRejected(User $actor, WelfareRequest $request, string $reason): WelfareRequest
    {
        WelfareApprovalStep::query()
            ->where('welfare_request_id', $request->id)
            ->where('status', WelfareApprovalStep::STATUS_PENDING)
            ->update(['status' => WelfareApprovalStep::STATUS_SKIPPED, 'is_current' => false]);

        $request->update([
            'status' => WelfareRequest::STATUS_REJECTED,
            'approval_status' => 'rejected',
            'current_approval_step' => null,
            'beneficiary_status_message' => config('welfare_approvals.beneficiary_status_messages.rejected'),
            'updated_by' => $actor->id,
        ]);

        $this->notifyBeneficiary($request, 'welfare.request.rejected', config('welfare_approvals.beneficiary_status_messages.rejected'));
        $this->audit($actor, 'welfare_request.rejected', $request->branch_id, WelfareRequest::class, $request->id, [
            'reason' => $reason,
        ]);

        return $request->fresh(['approvalSteps', 'approvalDecisions']);
    }

    private function returnForInfo(User $actor, WelfareRequest $request, string $reason): WelfareRequest
    {
        WelfareApprovalStep::query()
            ->where('welfare_request_id', $request->id)
            ->where('status', WelfareApprovalStep::STATUS_PENDING)
            ->update(['is_current' => false]);

        $request->update([
            'status' => WelfareRequest::STATUS_RETURNED_FOR_INFO,
            'approval_status' => 'returned',
            'returned_at' => now(),
            'beneficiary_status_message' => config('welfare_approvals.beneficiary_status_messages.returned_for_info'),
            'updated_by' => $actor->id,
        ]);

        $this->notifyBeneficiary($request, 'welfare.request.returned', config('welfare_approvals.beneficiary_status_messages.returned_for_info'));
        $this->audit($actor, 'welfare_request.approval_returned', $request->branch_id, WelfareRequest::class, $request->id, [
            'reason' => $reason,
        ]);

        return $request->fresh(['approvalSteps', 'approvalDecisions']);
    }

    private function escalateRemaining(User $actor, WelfareRequest $request, WelfareApprovalStep $step, string $reason): WelfareRequest
    {
        // Skip current; jump to next pending if any, else mark escalated awaiting higher attention.
        $next = WelfareApprovalStep::query()
            ->where('welfare_request_id', $request->id)
            ->where('sequence', '>', $step->sequence)
            ->where('status', WelfareApprovalStep::STATUS_PENDING)
            ->orderBy('sequence')
            ->first();

        if ($next !== null) {
            $next->update(['is_current' => true]);
            $request->update([
                'current_approval_step' => $next->sequence,
                'approval_status' => 'escalated_in_progress',
                'status' => WelfareRequest::STATUS_PENDING_APPROVAL,
                'escalated_at' => now(),
                'updated_by' => $actor->id,
            ]);
        } else {
            $request->update([
                'status' => WelfareRequest::STATUS_ESCALATED,
                'approval_status' => 'escalated',
                'escalated_at' => now(),
                'beneficiary_status_message' => config('welfare_assessments.beneficiary_status_messages.escalated'),
                'updated_by' => $actor->id,
            ]);
        }

        $this->audit($actor, 'welfare_request.approval_escalated', $request->branch_id, WelfareRequest::class, $request->id, [
            'reason' => $reason,
            'from_level' => $step->level,
        ]);

        return $request->fresh(['approvalSteps', 'approvalDecisions']);
    }

    private function resolveEffectiveConfigVersion(int $branchId): WelfareApprovalConfigVersion
    {
        $this->ensureDefaultPublishedConfig();

        $config = WelfareApprovalConfig::query()
            ->where('status', WelfareApprovalConfig::STATUS_PUBLISHED)
            ->where(function ($q) use ($branchId) {
                $q->where('branch_id', $branchId)->orWhereNull('branch_id');
            })
            ->orderByRaw('branch_id is null') // prefer branch-specific
            ->orderByDesc('id')
            ->first();

        if ($config === null) {
            throw new WelfareRequestException('No published welfare approval configuration is available.', 'missing_config', 422);
        }

        $version = WelfareApprovalConfigVersion::query()
            ->where('welfare_approval_config_id', $config->id)
            ->where('version', $config->current_version)
            ->where('status', WelfareApprovalConfigVersion::STATUS_PUBLISHED)
            ->first();

        if ($version === null) {
            throw new WelfareRequestException('Published approval configuration version not found.', 'missing_config_version', 422);
        }

        return $version;
    }

    /**
     * @param  array<int, array<string, mixed>>  $thresholds
     * @return array<int, string>
     */
    private function levelsForValue(float $value, array $thresholds): array
    {
        foreach ($thresholds as $band) {
            $max = $band['max_value'] ?? null;
            if ($max === null || $value <= (float) $max) {
                return array_values($band['levels'] ?? []);
            }
        }

        return config('welfare_approvals.levels', ['branch', 'hq', 'executive', 'finance']);
    }

    /**
     * @param  array<int, array<string, mixed>>  $thresholds
     * @return array<int, array<string, mixed>>
     */
    private function normalizeThresholds(array $thresholds): array
    {
        return array_values(array_map(fn (array $band) => [
            'max_value' => $band['max_value'] ?? null,
            'levels' => array_values($band['levels']),
        ], $thresholds));
    }

    /**
     * @param  array<int, array<string, mixed>>  $thresholds
     */
    private function assertThresholdsValid(array $thresholds): void
    {
        $allowed = config('welfare_approvals.levels', []);
        foreach ($thresholds as $index => $band) {
            foreach ($band['levels'] as $level) {
                if (! in_array($level, $allowed, true)) {
                    throw ValidationException::withMessages([
                        "thresholds.{$index}.levels" => ['Invalid approval level: ' . $level],
                    ]);
                }
            }
        }
    }

    private function assertCanDecide(User $actor, WelfareRequest $request): void
    {
        if (! $this->authorization->allows($actor, 'welfare.approvals.decide')
            && ! $this->hasAnyLevelPermission($actor)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }

        if ($actor->isChurchWide()) {
            return;
        }

        try {
            BranchScope::for($actor)->assertIncludes((int) $request->branch_id);
        } catch (BranchScopeException) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function hasAnyLevelPermission(User $actor): bool
    {
        foreach (config('welfare_approvals.level_permissions', []) as $permission) {
            if ($this->authorization->allows($actor, $permission)) {
                return true;
            }
        }

        return false;
    }

    private function assertLevelPermission(User $actor, string $level): void
    {
        $permission = config('welfare_approvals.level_permissions.' . $level);
        if ($permission === null || ! $this->authorization->allows($actor, $permission)) {
            throw new WelfareRequestException(
                'You are not authorized to decide at the ' . $level . ' approval level.',
                'level_forbidden',
                403,
            );
        }
    }

    private function assertNotSelfApproval(User $actor, WelfareRequest $request): void
    {
        if ((int) $request->requester_user_id === (int) $actor->id) {
            throw new WelfareRequestException('You cannot approve your own welfare request.', 'self_approval', 403);
        }

        $linked = Member::query()->where('user_id', $actor->id)->first();
        if ($linked !== null && (int) $request->beneficiary_member_id === (int) $linked->id) {
            throw new WelfareRequestException('You cannot approve a request where you are the beneficiary.', 'self_approval', 403);
        }
    }

    private function assertNotAssessorApproval(User $actor, WelfareRequest $request): void
    {
        $wasAssessor = WelfareAssessmentVersion::query()
            ->where('welfare_request_id', $request->id)
            ->where('assessor_id', $actor->id)
            ->where('complete', true)
            ->exists();

        if ($wasAssessor) {
            throw new WelfareRequestException(
                'Segregation of duties prevents the assessing officer from approving this case.',
                'sod_prohibited_approval',
                403,
            );
        }
    }

    private function assertCan(User $actor, string $action): void
    {
        if (! $this->authorization->allows($actor, $action)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function notifyBeneficiary(WelfareRequest $request, string $type, string $message): void
    {
        $member = Member::query()->find($request->requester_member_id)
            ?? Member::query()->find($request->beneficiary_member_id);

        if ($member === null || $member->user_id === null) {
            return;
        }

        MemberNotification::create([
            'member_id' => $member->id,
            'user_id' => $member->user_id,
            'type' => $type,
            'message' => $message,
            'metadata' => [
                'welfare_request_id' => $request->id,
                'case_number' => $request->case_number,
                'status' => $request->status,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function audit(
        User $actor,
        string $action,
        ?int $branchId,
        string $subjectType,
        int $subjectId,
        ?array $metadata = null,
    ): void {
        $this->audit->record(
            actor: $actor,
            action: $action,
            category: AuditEvent::CATEGORY_BUSINESS,
            module: 'welfare',
            branchId: $branchId,
            subjectType: $subjectType,
            subjectId: $subjectId,
            after: array_filter(['metadata' => $metadata]),
        );
    }
}
