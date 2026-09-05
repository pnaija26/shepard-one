<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Member;
use App\Models\MemberNotification;
use App\Models\User;
use App\Models\WelfareCaseEvent;
use App\Models\WelfareFollowUpEntry;
use App\Models\WelfareFollowUpReminder;
use App\Models\WelfareRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Story 7.5: welfare case follow-up, closure, and overdue reminders/escalation.
 */
class WelfareFollowUpService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function recordFollowUp(User $actor, WelfareRequest $request, array $payload): WelfareFollowUpEntry
    {
        $this->assertCanManage($actor, $request);

        if (! in_array($request->status, config('welfare_follow_ups.follow_up_eligible_statuses', []), true)) {
            throw new WelfareRequestException(
                'Follow-up can only be recorded on delivered or pending welfare cases.',
                'invalid_status',
                422,
            );
        }

        if ($request->status === WelfareRequest::STATUS_CLOSED) {
            throw new WelfareRequestException('Closed cases cannot receive follow-up activity.', 'closed', 422);
        }

        $validated = validator($payload, [
            'outcome' => ['required', 'string', 'in:' . implode(',', config('welfare_follow_ups.outcomes', []))],
            'further_action' => ['required', 'string', 'in:' . implode(',', config('welfare_follow_ups.further_actions', []))],
            'notes' => ['nullable', 'string', 'max:5000'],
            'follow_up_due_on' => ['nullable', 'date'],
            'reassign_officer_id' => ['nullable', 'integer', 'exists:users,id'],
            'evidence' => ['nullable', 'array', 'max:' . (int) config('welfare_follow_ups.evidence_constraints.max_items', 5)],
            'evidence.*.filename' => ['required_with:evidence', 'string', 'max:255'],
            'evidence.*.mime_type' => ['required_with:evidence', 'string', 'max:120'],
            'evidence.*.size_bytes' => ['required_with:evidence', 'integer', 'min:1'],
            'evidence.*.content_hash' => ['required_with:evidence', 'string', 'max:128'],
        ])->validate();

        if ($validated['further_action'] === 'reassign' && empty($validated['reassign_officer_id'])) {
            throw ValidationException::withMessages([
                'reassign_officer_id' => ['An officer is required when further action is reassign.'],
            ]);
        }

        if ($validated['further_action'] === 'reschedule' && empty($validated['follow_up_due_on'])) {
            throw ValidationException::withMessages([
                'follow_up_due_on' => ['A follow-up due date is required when rescheduling.'],
            ]);
        }

        if ($validated['further_action'] === 'close') {
            return $this->closeCase($actor, $request, [
                'closure_reason' => $payload['closure_reason'] ?? 'resolved',
                'notes' => $validated['notes'] ?? null,
                'evidence' => $validated['evidence'] ?? [],
            ]);
        }

        $evidence = $this->processEvidence($validated['evidence'] ?? []);

        return DB::transaction(function () use ($actor, $request, $validated, $evidence): WelfareFollowUpEntry {
            $fromOfficer = $request->assigned_officer_id;
            $toOfficer = $fromOfficer;
            $entryType = WelfareFollowUpEntry::TYPE_FOLLOW_UP;
            $nextStatus = WelfareRequest::STATUS_FOLLOW_UP;

            if ($validated['further_action'] === 'reassign') {
                $officer = User::query()->findOrFail((int) $validated['reassign_officer_id']);
                $this->assertOfficerInScope($actor, $officer, (int) $request->branch_id);
                $toOfficer = $officer->id;
                $entryType = WelfareFollowUpEntry::TYPE_REASSIGN;
            } elseif ($validated['further_action'] === 'escalate') {
                $entryType = WelfareFollowUpEntry::TYPE_ESCALATION;
                $nextStatus = WelfareRequest::STATUS_ESCALATED;
                $this->assertTransition($request->status, $nextStatus);
            } else {
                $this->assertTransition($request->status, WelfareRequest::STATUS_FOLLOW_UP);
            }

            $dueOn = $validated['follow_up_due_on']
                ?? ($request->follow_up_due_on?->toDateString()
                    ?? now()->addDays((int) config('welfare_follow_ups.default_due_days', 7))->toDateString());

            $entry = WelfareFollowUpEntry::create([
                'welfare_request_id' => $request->id,
                'branch_id' => $request->branch_id,
                'entry_type' => $entryType,
                'outcome' => $validated['outcome'],
                'further_action' => $validated['further_action'],
                'notes' => $validated['notes'] ?? null,
                'follow_up_due_on' => $dueOn,
                'from_officer_id' => $fromOfficer,
                'to_officer_id' => $toOfficer,
                'evidence' => $evidence,
                'recorded_by' => $actor->id,
                'recorded_at' => now(),
            ]);

            $updates = [
                'status' => $nextStatus,
                'follow_up_at' => now(),
                'follow_up_due_on' => $dueOn,
                'beneficiary_status_message' => config(
                    'welfare_follow_ups.beneficiary_status_messages.' . ($nextStatus === WelfareRequest::STATUS_ESCALATED ? 'escalated' : 'follow_up')
                ),
                'updated_by' => $actor->id,
            ];

            if ($validated['further_action'] === 'reassign') {
                $updates['assigned_officer_id'] = $toOfficer;
            }

            if ($validated['further_action'] === 'escalate') {
                $updates['escalated_at'] = now();
                $updates['follow_up_escalated_at'] = now();
            }

            $request->update($updates);

            WelfareCaseEvent::create([
                'welfare_request_id' => $request->id,
                'event_type' => 'follow_up_' . $validated['further_action'],
                'notes' => $validated['notes'] ?? ('Follow-up recorded: ' . $validated['outcome']),
                'beneficiary_message' => $updates['beneficiary_status_message'],
                'from_officer_id' => $fromOfficer,
                'to_officer_id' => $toOfficer,
                'actor_id' => $actor->id,
                'metadata' => [
                    'entry_id' => $entry->id,
                    'outcome' => $validated['outcome'],
                    'further_action' => $validated['further_action'],
                    'follow_up_due_on' => $dueOn,
                ],
                'created_at' => now(),
            ]);

            $this->audit($actor, 'welfare_request.follow_up_recorded', $request, [
                'entry_id' => $entry->id,
                'outcome' => $validated['outcome'],
                'further_action' => $validated['further_action'],
            ]);

            $this->notifyBeneficiary(
                $request->fresh(),
                'welfare.request.follow_up',
                (string) $updates['beneficiary_status_message'],
            );

            return $entry->fresh(['recordedBy:id,name']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function closeCase(User $actor, WelfareRequest $request, array $payload): WelfareFollowUpEntry
    {
        $this->assertCanManage($actor, $request);

        if (! in_array($request->status, config('welfare_follow_ups.close_from_statuses', []), true)) {
            throw new WelfareRequestException(
                'Only follow-up, disbursed, or rejected cases can be closed.',
                'invalid_status',
                422,
            );
        }

        $this->assertTransition($request->status, WelfareRequest::STATUS_CLOSED);

        $validated = validator($payload, [
            'closure_reason' => ['required', 'string', 'in:' . implode(',', config('welfare_follow_ups.closure_reasons', []))],
            'notes' => ['nullable', 'string', 'max:5000'],
            'evidence' => ['required', 'array', 'min:1', 'max:' . (int) config('welfare_follow_ups.evidence_constraints.max_items', 5)],
            'evidence.*.filename' => ['required', 'string', 'max:255'],
            'evidence.*.mime_type' => ['required', 'string', 'max:120'],
            'evidence.*.size_bytes' => ['required', 'integer', 'min:1'],
            'evidence.*.content_hash' => ['required', 'string', 'max:128'],
        ])->validate();

        $evidence = $this->processEvidence($validated['evidence']);

        return DB::transaction(function () use ($actor, $request, $validated, $evidence): WelfareFollowUpEntry {
            $entry = WelfareFollowUpEntry::create([
                'welfare_request_id' => $request->id,
                'branch_id' => $request->branch_id,
                'entry_type' => WelfareFollowUpEntry::TYPE_CLOSURE,
                'outcome' => 'resolved',
                'further_action' => 'close',
                'notes' => $validated['notes'] ?? null,
                'follow_up_due_on' => $request->follow_up_due_on,
                'from_officer_id' => $request->assigned_officer_id,
                'to_officer_id' => $request->assigned_officer_id,
                'closure_reason' => $validated['closure_reason'],
                'evidence' => $evidence,
                'recorded_by' => $actor->id,
                'recorded_at' => now(),
            ]);

            $message = (string) config('welfare_follow_ups.beneficiary_status_messages.closed');

            $request->update([
                'status' => WelfareRequest::STATUS_CLOSED,
                'closed_at' => now(),
                'closure_reason' => $validated['closure_reason'],
                'beneficiary_status_message' => $message,
                'updated_by' => $actor->id,
            ]);

            WelfareCaseEvent::create([
                'welfare_request_id' => $request->id,
                'event_type' => 'case_closed',
                'notes' => $validated['notes'] ?? ('Case closed: ' . $validated['closure_reason']),
                'beneficiary_message' => $message,
                'actor_id' => $actor->id,
                'metadata' => [
                    'entry_id' => $entry->id,
                    'closure_reason' => $validated['closure_reason'],
                ],
                'created_at' => now(),
            ]);

            $this->audit($actor, 'welfare_request.closed', $request, [
                'entry_id' => $entry->id,
                'closure_reason' => $validated['closure_reason'],
            ]);

            $this->notifyBeneficiary($request->fresh(), 'welfare.request.closed', $message);

            return $entry->fresh(['recordedBy:id,name']);
        });
    }

    /**
     * @return array{processed: int, reminded: int, escalated: int, skipped: int}
     */
    public function processOverdue(User $actor, ?int $branchId = null): array
    {
        if (! $this->authorization->allows($actor, 'welfare.follow_ups.escalate')
            && ! $this->authorization->allows($actor, 'welfare.follow_ups.manage')) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }

        $counts = ['processed' => 0, 'reminded' => 0, 'escalated' => 0, 'skipped' => 0];
        $reminderAfter = (int) config('welfare_follow_ups.overdue.reminder_after_due_days', 0);
        $escalateAfter = (int) config('welfare_follow_ups.overdue.escalate_after_due_days', 3);
        $cooldownHours = (int) config('welfare_follow_ups.overdue.reminder_cooldown_hours', 24);

        $query = WelfareRequest::query()
            ->whereIn('status', [WelfareRequest::STATUS_FOLLOW_UP, WelfareRequest::STATUS_DISBURSED])
            ->whereNotNull('follow_up_due_on')
            ->whereDate('follow_up_due_on', '<=', now()->toDateString())
            ->orderBy('id');

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        $this->applyBranchScope($query, $actor);

        foreach ($query->cursor() as $request) {
            $counts['processed']++;
            $daysOverdue = $request->follow_up_due_on->startOfDay()->diffInDays(now()->startOfDay());

            if ($daysOverdue < $reminderAfter) {
                $counts['skipped']++;

                continue;
            }

            if ($daysOverdue >= $escalateAfter && $request->follow_up_escalated_at === null) {
                $this->escalateOverdue($actor, $request, $daysOverdue);
                $counts['escalated']++;

                continue;
            }

            $recentReminder = $request->last_follow_up_reminder_at !== null
                && $request->last_follow_up_reminder_at->gt(now()->subHours($cooldownHours));

            if ($recentReminder) {
                $counts['skipped']++;

                continue;
            }

            $this->sendOverdueReminder($actor, $request, $daysOverdue);
            $counts['reminded']++;
        }

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    public function formatEntry(WelfareFollowUpEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'welfare_request_id' => $entry->welfare_request_id,
            'entry_type' => $entry->entry_type,
            'outcome' => $entry->outcome,
            'further_action' => $entry->further_action,
            'notes' => $entry->notes,
            'follow_up_due_on' => $entry->follow_up_due_on?->toDateString(),
            'from_officer_id' => $entry->from_officer_id,
            'to_officer_id' => $entry->to_officer_id,
            'closure_reason' => $entry->closure_reason,
            'evidence' => $entry->evidence ?? [],
            'recorded_by' => $entry->relationLoaded('recordedBy') && $entry->recordedBy
                ? ['id' => $entry->recordedBy->id, 'name' => $entry->recordedBy->name]
                : null,
            'recorded_at' => $entry->recorded_at?->toIso8601String(),
        ];
    }

    private function escalateOverdue(User $actor, WelfareRequest $request, int $daysOverdue): void
    {
        DB::transaction(function () use ($actor, $request, $daysOverdue): void {
            $this->assertTransition($request->status, WelfareRequest::STATUS_ESCALATED);

            $entry = WelfareFollowUpEntry::create([
                'welfare_request_id' => $request->id,
                'branch_id' => $request->branch_id,
                'entry_type' => WelfareFollowUpEntry::TYPE_ESCALATION,
                'outcome' => 'unresolved',
                'further_action' => 'escalate',
                'notes' => "Overdue follow-up escalated after {$daysOverdue} day(s).",
                'follow_up_due_on' => $request->follow_up_due_on,
                'from_officer_id' => $request->assigned_officer_id,
                'to_officer_id' => $request->assigned_officer_id,
                'recorded_by' => $actor->id,
                'recorded_at' => now(),
            ]);

            WelfareFollowUpReminder::create([
                'welfare_request_id' => $request->id,
                'reminder_type' => WelfareFollowUpReminder::TYPE_OVERDUE_ESCALATION,
                'sent_at' => now(),
                'actor_id' => $actor->id,
                'metadata' => ['days_overdue' => $daysOverdue, 'entry_id' => $entry->id],
            ]);

            $message = (string) config('welfare_follow_ups.beneficiary_status_messages.escalated');

            $request->update([
                'status' => WelfareRequest::STATUS_ESCALATED,
                'escalated_at' => now(),
                'follow_up_escalated_at' => now(),
                'beneficiary_status_message' => $message,
                'updated_by' => $actor->id,
            ]);

            WelfareCaseEvent::create([
                'welfare_request_id' => $request->id,
                'event_type' => 'follow_up_overdue_escalated',
                'notes' => "Overdue follow-up escalated after {$daysOverdue} day(s).",
                'beneficiary_message' => $message,
                'actor_id' => $actor->id,
                'metadata' => ['days_overdue' => $daysOverdue, 'entry_id' => $entry->id],
                'created_at' => now(),
            ]);

            $this->audit($actor, 'welfare_request.follow_up_overdue_escalated', $request, [
                'days_overdue' => $daysOverdue,
            ]);

            $this->notifyAssignee($request->fresh(), 'welfare.request.follow_up_overdue', "Welfare case {$request->case_number} follow-up is overdue and has been escalated.");
            $this->notifyBeneficiary($request->fresh(), 'welfare.request.escalated', $message);
        });
    }

    private function sendOverdueReminder(User $actor, WelfareRequest $request, int $daysOverdue): void
    {
        DB::transaction(function () use ($actor, $request, $daysOverdue): void {
            WelfareFollowUpReminder::create([
                'welfare_request_id' => $request->id,
                'reminder_type' => WelfareFollowUpReminder::TYPE_OVERDUE_REMINDER,
                'sent_at' => now(),
                'actor_id' => $actor->id,
                'metadata' => ['days_overdue' => $daysOverdue],
            ]);

            WelfareFollowUpEntry::create([
                'welfare_request_id' => $request->id,
                'branch_id' => $request->branch_id,
                'entry_type' => WelfareFollowUpEntry::TYPE_REMINDER,
                'outcome' => 'unresolved',
                'further_action' => 'continue',
                'notes' => "Overdue follow-up reminder after {$daysOverdue} day(s).",
                'follow_up_due_on' => $request->follow_up_due_on,
                'from_officer_id' => $request->assigned_officer_id,
                'to_officer_id' => $request->assigned_officer_id,
                'recorded_by' => $actor->id,
                'recorded_at' => now(),
            ]);

            $request->update([
                'last_follow_up_reminder_at' => now(),
                'updated_by' => $actor->id,
            ]);

            WelfareCaseEvent::create([
                'welfare_request_id' => $request->id,
                'event_type' => 'follow_up_overdue_reminder',
                'notes' => "Overdue follow-up reminder after {$daysOverdue} day(s).",
                'actor_id' => $actor->id,
                'metadata' => ['days_overdue' => $daysOverdue],
                'created_at' => now(),
            ]);

            $this->audit($actor, 'welfare_request.follow_up_overdue_reminder', $request, [
                'days_overdue' => $daysOverdue,
            ]);

            $this->notifyAssignee(
                $request->fresh(),
                'welfare.request.follow_up_overdue',
                "Welfare case {$request->case_number} follow-up is overdue ({$daysOverdue} day(s)).",
            );
        });
    }

    private function assertTransition(string $from, string $to): void
    {
        $allowed = config('welfare_follow_ups.transitions.' . $from, []);

        if (! in_array($to, $allowed, true) && $from !== $to) {
            throw new WelfareRequestException(
                "Status transition from {$from} to {$to} is not permitted.",
                'invalid_transition',
                422,
            );
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function processEvidence(array $items): array
    {
        $constraints = config('welfare_follow_ups.evidence_constraints', []);
        $processed = [];

        foreach ($items as $index => $item) {
            $filename = (string) ($item['filename'] ?? '');
            $mime = (string) ($item['mime_type'] ?? '');
            $size = (int) ($item['size_bytes'] ?? 0);
            $hash = (string) ($item['content_hash'] ?? '');

            if ($filename === '' || str_contains($filename, "\0") || str_contains($filename, '../')) {
                throw ValidationException::withMessages([
                    "evidence.{$index}" => ['Evidence filename is not safe.'],
                ]);
            }

            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (in_array($extension, $constraints['blocked_extensions'] ?? [], true)) {
                throw ValidationException::withMessages([
                    "evidence.{$index}" => ['This evidence file type is not permitted.'],
                ]);
            }

            if (! in_array($mime, $constraints['allowed_mime_types'] ?? [], true)) {
                throw ValidationException::withMessages([
                    "evidence.{$index}" => ['Use PDF, JPEG, PNG, or WEBP evidence only.'],
                ]);
            }

            if ($size > (int) ($constraints['max_size_bytes'] ?? 0)) {
                throw ValidationException::withMessages([
                    "evidence.{$index}" => ['Evidence file exceeds the maximum allowed size.'],
                ]);
            }

            $processed[] = [
                'document_id' => (string) Str::uuid(),
                'filename' => $filename,
                'mime_type' => $mime,
                'size_bytes' => $size,
                'content_hash' => $hash,
                'status' => 'accepted',
                'storage_path' => 'welfare/follow-ups/' . $hash . '/' . basename($filename),
            ];
        }

        return $processed;
    }

    private function assertCanManage(User $actor, WelfareRequest $request): void
    {
        if (! $this->authorization->allows($actor, 'welfare.follow_ups.manage')) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }

        $this->assertInScope($actor, $request);
    }

    private function assertOfficerInScope(User $actor, User $officer, int $branchId): void
    {
        if ($actor->isChurchWide()) {
            return;
        }

        try {
            BranchScope::for($actor)->assertIncludes($branchId);
            if ($officer->branch_id !== null) {
                BranchScope::for($actor)->assertIncludes((int) $officer->branch_id);
            }
        } catch (BranchScopeException) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function assertInScope(User $actor, WelfareRequest $request): void
    {
        if ($actor->isChurchWide()) {
            return;
        }

        try {
            BranchScope::for($actor)->assertIncludes((int) $request->branch_id);
        } catch (BranchScopeException) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function applyBranchScope(Builder $query, User $actor): void
    {
        if ($actor->isChurchWide()) {
            return;
        }

        $scope = BranchScope::for($actor);
        $query->whereIn('branch_id', $scope->subtreeIds((int) $scope->branchId()));
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

    private function notifyAssignee(WelfareRequest $request, string $type, string $message): void
    {
        if ($request->assigned_officer_id === null) {
            return;
        }

        $officer = User::query()->find($request->assigned_officer_id);
        if ($officer === null) {
            return;
        }

        $member = Member::query()->where('user_id', $officer->id)->first();
        if ($member === null) {
            // Still create a lightweight notification row if member link is missing — skip.
            return;
        }

        MemberNotification::create([
            'member_id' => $member->id,
            'user_id' => $officer->id,
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
            after: $metadata,
            metadata: $metadata,
        );
    }
}
