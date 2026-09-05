<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Member;
use App\Models\MemberNotification;
use App\Models\User;
use App\Models\WelfareAssessmentVersion;
use App\Models\WelfareCaseEvent;
use App\Models\WelfareRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Story 7.2: assess welfare requests and record recommendations.
 */
class WelfareAssessmentService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
        private WelfareRequestService $requests,
        private WelfareApprovalService $approvals,
        private WelfareDeliveryService $deliveries,
        private WelfareFollowUpService $followUps,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function assign(User $actor, WelfareRequest $request, array $payload): WelfareRequest
    {
        $this->assertCanAssess($actor, $request);

        if (! in_array($request->status, [
            WelfareRequest::STATUS_SUBMITTED,
            WelfareRequest::STATUS_UNDER_ASSESSMENT,
            WelfareRequest::STATUS_RETURNED_FOR_INFO,
            WelfareRequest::STATUS_ESCALATED,
        ], true)) {
            throw new WelfareRequestException('Only submitted or returned cases can be assigned for assessment.', 'invalid_status', 422);
        }

        $validated = validator($payload, [
            'officer_id' => ['required', 'integer', 'exists:users,id'],
        ])->validate();

        $officer = User::query()->findOrFail((int) $validated['officer_id']);
        $this->assertOfficerInScope($actor, $officer, (int) $request->branch_id);

        return DB::transaction(function () use ($actor, $request, $officer): WelfareRequest {
            $from = $request->assigned_officer_id;

            $request->update([
                'assigned_officer_id' => $officer->id,
                'status' => WelfareRequest::STATUS_UNDER_ASSESSMENT,
                'beneficiary_status_message' => $this->beneficiaryMessage(WelfareRequest::STATUS_UNDER_ASSESSMENT),
                'updated_by' => $actor->id,
            ]);

            $this->recordEvent($request, $actor, 'assigned', null, 'Case assigned for assessment.', [
                'from_officer_id' => $from,
                'to_officer_id' => $officer->id,
            ]);

            $this->audit($actor, 'welfare_request.assigned', $request, [
                'officer_id' => $officer->id,
            ]);

            return $request->fresh(['assignedOfficer:id,name', 'assessments', 'caseEvents']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function recordAssessment(User $actor, WelfareRequest $request, array $payload): WelfareRequest
    {
        $this->assertCanAssess($actor, $request);
        $this->assertAssignedOrMaySelfAssign($actor, $request);

        if (! in_array($request->status, [
            WelfareRequest::STATUS_SUBMITTED,
            WelfareRequest::STATUS_UNDER_ASSESSMENT,
            WelfareRequest::STATUS_RETURNED_FOR_INFO,
            WelfareRequest::STATUS_ESCALATED,
        ], true)) {
            throw new WelfareRequestException('Assessment cannot be recorded for this case status.', 'invalid_status', 422);
        }

        $validated = validator($payload, [
            'assessment_notes' => ['required', 'string', 'max:5000'],
            'verified_documents' => ['nullable', 'array'],
            'verified_documents.*' => ['string', 'max:120'],
            'priority' => ['required', 'string', 'in:' . implode(',', config('welfare_requests.priorities', []))],
            'recommendation' => ['required', 'string', 'in:' . implode(',', config('welfare_assessments.recommendations', []))],
            'proposed_assistance_type' => ['nullable', 'string', 'in:' . implode(',', config('welfare_assessments.assistance_types', []))],
            'proposed_value' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:8'],
            'follow_up_needs' => ['nullable', 'string', 'max:2000'],
            'complete' => ['nullable', 'boolean'],
        ])->validate();

        $complete = (bool) ($validated['complete'] ?? false);

        if ($complete) {
            $this->assertCompleteAssessment($validated);
        }

        return DB::transaction(function () use ($actor, $request, $validated, $complete): WelfareRequest {
            if ($request->assigned_officer_id === null) {
                $request->assigned_officer_id = $actor->id;
            }

            $versionNumber = ((int) $request->current_assessment_version) + 1;

            WelfareAssessmentVersion::create([
                'welfare_request_id' => $request->id,
                'version' => $versionNumber,
                'assessor_id' => $actor->id,
                'assessment_notes' => $validated['assessment_notes'],
                'verified_documents' => $validated['verified_documents'] ?? [],
                'priority' => $validated['priority'],
                'recommendation' => $validated['recommendation'],
                'proposed_assistance_type' => $validated['proposed_assistance_type'] ?? null,
                'proposed_value' => $validated['proposed_value'] ?? null,
                'currency' => $validated['currency'] ?? $request->currency ?? 'NGN',
                'follow_up_needs' => $validated['follow_up_needs'] ?? null,
                'complete' => $complete,
                'recorded_at' => now(),
            ]);

            $status = $complete
                ? WelfareRequest::STATUS_PENDING_REVIEW
                : WelfareRequest::STATUS_UNDER_ASSESSMENT;

            $request->update([
                'assigned_officer_id' => $request->assigned_officer_id,
                'current_assessment_version' => $versionNumber,
                'priority' => $validated['priority'],
                'status' => $status,
                'beneficiary_status_message' => $this->beneficiaryMessage($status),
                'updated_by' => $actor->id,
            ]);

            $this->recordEvent($request, $actor, $complete ? 'assessment_completed' : 'assessment_recorded', null, $validated['assessment_notes'], [
                'version' => $versionNumber,
                'recommendation' => $validated['recommendation'],
            ]);

            $this->audit($actor, $complete ? 'welfare_request.assessment_completed' : 'welfare_request.assessment_recorded', $request, [
                'version' => $versionNumber,
            ]);

            if ($complete) {
                $fresh = $request->fresh(['assignedOfficer:id,name', 'assessments.assessor:id,name', 'caseEvents']);
                if (in_array($validated['recommendation'], ['approve', 'partial_approve'], true)) {
                    $fresh = $this->approvals->createRouting($actor, $fresh);
                }
                $this->notifyRequester($fresh, 'welfare.request.pending_review', $this->beneficiaryMessage($fresh->status));

                return $fresh->load(['assignedOfficer:id,name', 'assessments.assessor:id,name', 'caseEvents', 'approvalSteps', 'approvalConfigVersion']);
            }

            return $request->fresh(['assignedOfficer:id,name', 'assessments.assessor:id,name', 'caseEvents']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function recordCondition(User $actor, WelfareRequest $request, array $payload): WelfareRequest
    {
        $this->assertCanAssess($actor, $request);

        if (! in_array($request->status, [
            WelfareRequest::STATUS_SUBMITTED,
            WelfareRequest::STATUS_UNDER_ASSESSMENT,
            WelfareRequest::STATUS_RETURNED_FOR_INFO,
            WelfareRequest::STATUS_ESCALATED,
            WelfareRequest::STATUS_PENDING_REVIEW,
            WelfareRequest::STATUS_PENDING_APPROVAL,
        ], true)) {
            throw new WelfareRequestException('Conditions cannot be recorded for this case status.', 'invalid_status', 422);
        }

        $validated = validator($payload, [
            'condition_type' => ['required', 'string', 'in:' . implode(',', config('welfare_assessments.condition_types', []))],
            'notes' => ['required', 'string', 'max:2000'],
            'reassign_to_officer_id' => ['nullable', 'integer', 'exists:users,id'],
        ])->validate();

        $action = config('welfare_assessments.condition_actions.' . $validated['condition_type']);
        if ($action === null) {
            throw ValidationException::withMessages(['condition_type' => ['Unsupported welfare condition.']]);
        }

        return DB::transaction(function () use ($actor, $request, $validated, $action): WelfareRequest {
            $beneficiaryMessage = match ($action) {
                'return_for_info' => $this->beneficiaryMessage(WelfareRequest::STATUS_RETURNED_FOR_INFO),
                'escalate' => $this->beneficiaryMessage(WelfareRequest::STATUS_ESCALATED),
                'reassign' => $this->beneficiaryMessage(WelfareRequest::STATUS_UNDER_ASSESSMENT),
                default => $this->beneficiaryMessage($request->status),
            };

            if ($action === 'return_for_info') {
                $request->update([
                    'status' => WelfareRequest::STATUS_RETURNED_FOR_INFO,
                    'beneficiary_status_message' => $beneficiaryMessage,
                    'returned_at' => now(),
                    'updated_by' => $actor->id,
                ]);
            } elseif ($action === 'escalate') {
                $request->update([
                    'status' => WelfareRequest::STATUS_ESCALATED,
                    'beneficiary_status_message' => $beneficiaryMessage,
                    'escalated_at' => now(),
                    'updated_by' => $actor->id,
                ]);
            } elseif ($action === 'reassign') {
                $toOfficerId = $validated['reassign_to_officer_id'] ?? null;
                if ($toOfficerId === null) {
                    throw ValidationException::withMessages([
                        'reassign_to_officer_id' => ['A replacement officer is required for conflict-of-interest reassignment.'],
                    ]);
                }

                if ((int) $toOfficerId === (int) $actor->id) {
                    throw ValidationException::withMessages([
                        'reassign_to_officer_id' => ['Conflict of interest requires reassignment to a different officer.'],
                    ]);
                }

                $toOfficer = User::query()->findOrFail((int) $toOfficerId);
                $this->assertOfficerInScope($actor, $toOfficer, (int) $request->branch_id);

                $from = $request->assigned_officer_id;
                $request->update([
                    'assigned_officer_id' => $toOfficer->id,
                    'status' => WelfareRequest::STATUS_UNDER_ASSESSMENT,
                    'beneficiary_status_message' => $beneficiaryMessage,
                    'updated_by' => $actor->id,
                ]);

                $this->recordEvent($request, $actor, 'reassigned', $validated['condition_type'], $validated['notes'], [
                    'from_officer_id' => $from,
                    'to_officer_id' => $toOfficer->id,
                    'beneficiary_message' => $beneficiaryMessage,
                ]);

                $this->audit($actor, 'welfare_request.reassigned', $request, [
                    'condition_type' => $validated['condition_type'],
                    'to_officer_id' => $toOfficer->id,
                ]);

                $this->notifyRequester($request->fresh(), 'welfare.request.status_updated', $beneficiaryMessage);

                return $request->fresh(['assignedOfficer:id,name', 'assessments', 'caseEvents']);
            }

            $this->recordEvent($request, $actor, $action, $validated['condition_type'], $validated['notes'], [
                'beneficiary_message' => $beneficiaryMessage,
            ]);

            $this->audit($actor, 'welfare_request.' . $action, $request, [
                'condition_type' => $validated['condition_type'],
            ]);

            $this->notifyRequester($request->fresh(), 'welfare.request.status_updated', $beneficiaryMessage);

            return $request->fresh(['assignedOfficer:id,name', 'assessments', 'caseEvents']);
        });
    }

    /**
     * @deprecated Use WelfareApprovalService::decide — kept for assessor SoD probe compatibility.
     *
     * @param  array<string, mixed>  $payload
     */
    public function attemptApproval(User $actor, WelfareRequest $request, array $payload): WelfareRequest
    {
        return $this->approvals->decide($actor, $request, [
            'decision' => $payload['decision'] ?? 'approved',
            'reason' => $payload['reason'] ?? $payload['approval_level'] ?? 'Approval attempted',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function formatForActor(WelfareRequest $request, User $actor): array
    {
        $base = $this->requests->formatRequest($request, $actor);
        $isOfficer = $this->authorization->allows($actor, 'welfare.assess')
            || $this->authorization->allows($actor, 'welfare.approvals.decide')
            || $this->authorization->allows($actor, 'welfare.requests.read')
            || $this->authorization->allows($actor, 'welfare.follow_ups.manage');
        $isRequester = (int) $request->requester_user_id === (int) $actor->id;

        $base['assigned_officer_id'] = $request->assigned_officer_id;
        $base['assigned_officer'] = $request->relationLoaded('assignedOfficer') && $request->assignedOfficer
            ? ['id' => $request->assignedOfficer->id, 'name' => $request->assignedOfficer->name]
            : null;
        $base['current_assessment_version'] = $request->current_assessment_version;
        $base['beneficiary_status_message'] = $request->beneficiary_status_message
            ?? $this->beneficiaryMessage($request->status);
        $base['follow_up_due_on'] = $request->follow_up_due_on?->toDateString();
        $base['closed_at'] = $request->closed_at?->toIso8601String();
        $base['closure_reason'] = $request->closure_reason;

        if ($isOfficer) {
            $base['assessments'] = $request->relationLoaded('assessments')
                ? $request->assessments->map(fn (WelfareAssessmentVersion $version) => $this->formatAssessment($version))->values()->all()
                : [];
            $base['case_events'] = $request->relationLoaded('caseEvents')
                ? $request->caseEvents->map(fn (WelfareCaseEvent $event) => [
                    'id' => $event->id,
                    'event_type' => $event->event_type,
                    'condition_type' => $event->condition_type,
                    'notes' => $event->notes,
                    'beneficiary_message' => $event->beneficiary_message,
                    'created_at' => $event->created_at?->toIso8601String(),
                ])->values()->all()
                : [];
            $base['approvals'] = $this->approvals->formatRequestApprovals($request);
            $request->loadMissing(['deliveries.confirmation', 'followUpEntries.recordedBy']);
            $base['deliveries'] = $request->deliveries
                ->map(fn ($delivery) => $this->deliveries->formatDelivery($delivery, $actor))
                ->values()
                ->all();
            $base['follow_ups'] = $request->followUpEntries
                ->map(fn ($entry) => $this->followUps->formatEntry($entry))
                ->values()
                ->all();
            $base['approved_value'] = $this->authorization->allows($actor, 'welfare.finance.read')
                || $this->authorization->allows($actor, 'welfare.deliveries.manage')
                ? $request->approved_value
                : null;
        } else {
            // Requesters / beneficiaries only see the sanitized status message.
            $base['description'] = $isRequester ? $base['description'] : ($base['description'] ?? null);
            $base['assessments'] = [];
            $base['case_events'] = [];
            $base['deliveries'] = [];
            $base['follow_ups'] = [];
            $base['approved_value'] = null;
            if ($isRequester) {
                $base['officer_notes'] = null;
                $base['recommendation'] = null;
                $base['proposed_value'] = null;
            }
        }

        return $base;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatAssessment(WelfareAssessmentVersion $version): array
    {
        return [
            'id' => $version->id,
            'version' => $version->version,
            'assessor_id' => $version->assessor_id,
            'assessor' => $version->relationLoaded('assessor') && $version->assessor
                ? ['id' => $version->assessor->id, 'name' => $version->assessor->name]
                : null,
            'assessment_notes' => $version->assessment_notes,
            'verified_documents' => $version->verified_documents ?? [],
            'priority' => $version->priority,
            'recommendation' => $version->recommendation,
            'proposed_assistance_type' => $version->proposed_assistance_type,
            'proposed_value' => $version->proposed_value,
            'currency' => $version->currency,
            'follow_up_needs' => $version->follow_up_needs,
            'complete' => $version->complete,
            'recorded_at' => $version->recorded_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function assertCompleteAssessment(array $validated): void
    {
        if (empty($validated['verified_documents'])) {
            throw ValidationException::withMessages([
                'verified_documents' => ['At least one verified document is required to complete the assessment.'],
            ]);
        }

        if (empty($validated['proposed_assistance_type'])) {
            throw ValidationException::withMessages([
                'proposed_assistance_type' => ['Proposed assistance type is required for a complete recommendation.'],
            ]);
        }

        if (! array_key_exists('proposed_value', $validated) || $validated['proposed_value'] === null) {
            throw ValidationException::withMessages([
                'proposed_value' => ['Proposed value is required for a complete recommendation.'],
            ]);
        }
    }

    private function beneficiaryMessage(string $status): string
    {
        return (string) (config('welfare_assessments.beneficiary_status_messages.' . $status)
            ?? 'Your welfare request status has been updated.');
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function recordEvent(
        WelfareRequest $request,
        User $actor,
        string $eventType,
        ?string $conditionType,
        ?string $notes,
        array $metadata = [],
    ): void {
        WelfareCaseEvent::create([
            'welfare_request_id' => $request->id,
            'event_type' => $eventType,
            'condition_type' => $conditionType,
            'notes' => $notes,
            'beneficiary_message' => $metadata['beneficiary_message'] ?? null,
            'from_officer_id' => $metadata['from_officer_id'] ?? null,
            'to_officer_id' => $metadata['to_officer_id'] ?? null,
            'actor_id' => $actor->id,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }

    private function notifyRequester(WelfareRequest $request, string $type, string $message): void
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

    private function assertCanAssess(User $actor, WelfareRequest $request): void
    {
        if (! $this->authorization->allows($actor, 'welfare.assess')) {
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

    private function assertAssignedOrMaySelfAssign(User $actor, WelfareRequest $request): void
    {
        if ($request->assigned_officer_id === null) {
            return;
        }

        if ((int) $request->assigned_officer_id === (int) $actor->id) {
            return;
        }

        throw new WelfareRequestException('Only the assigned officer may record this assessment.', 'not_assigned', 403);
    }

    private function assertOfficerInScope(User $actor, User $officer, int $branchId): void
    {
        if ($officer->branch_id !== null && (int) $officer->branch_id !== $branchId && ! $officer->isChurchWide()) {
            // Allow church-wide officers; otherwise require same branch.
            if (! $actor->isChurchWide()) {
                throw ValidationException::withMessages([
                    'officer_id' => ['Officer must belong to the case branch.'],
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function audit(User $actor, string $action, WelfareRequest $request, ?array $metadata = null): void
    {
        $this->audit->record(
            actor: $actor,
            action: $action,
            category: AuditEvent::CATEGORY_BUSINESS,
            module: 'welfare',
            branchId: $request->branch_id,
            subjectType: WelfareRequest::class,
            subjectId: $request->id,
            after: array_filter([
                'case_number' => $request->case_number,
                'status' => $request->status,
                'metadata' => $metadata,
            ]),
        );
    }
}
