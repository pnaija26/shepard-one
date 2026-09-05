<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\AttendanceRecordCorrection;
use App\Models\AuditEvent;
use App\Models\Member;
use App\Models\User;
use App\Models\Visitor;
use App\Services\BranchScope;
use App\Services\BranchScopeException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Story 3.3: record and correct attendance that feeds exception detection.
 */
class AttendanceRecordService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
        private AttendanceExceptionService $exceptions,
    ) {
    }

    /**
     * @return Collection<int, AttendanceRecord>
     */
    public function listRecords(User $actor, array $filters = []): Collection
    {
        $this->assertCan($actor, 'attendance.read');

        $query = AttendanceRecord::query()
            ->with(['branch:id,name'])
            ->orderByDesc('gathering_date');

        if (! empty($filters['subject_type']) && ! empty($filters['subject_id'])) {
            $query->where('subject_type', $filters['subject_type'])
                ->where('subject_id', $filters['subject_id']);
        }

        if (! empty($filters['branch_id'])) {
            $query->where('branch_id', $filters['branch_id']);
        }

        $this->applyBranchScope($query, $actor);

        return $query->limit(200)->get();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function recordAttendance(User $actor, array $payload): AttendanceRecord
    {
        $this->assertCan($actor, 'attendance.write');

        $validated = $this->validatePayload($payload);
        $subject = $this->resolveSubject($validated['subject_type'], (int) $validated['subject_id']);
        $this->assertSubjectInScope($actor, $subject);

        return DB::transaction(function () use ($actor, $validated, $subject): AttendanceRecord {
            $record = AttendanceRecord::updateOrCreate(
                [
                    'subject_type' => $validated['subject_type'],
                    'subject_id' => $validated['subject_id'],
                    'branch_id' => $validated['branch_id'],
                    'service_type' => $validated['service_type'],
                    'gathering_date' => $validated['gathering_date'],
                    'team_id' => $validated['team_id'] ?? null,
                ],
                [
                    'status' => $validated['status'],
                    'service_cancelled' => $validated['service_cancelled'] ?? false,
                    'branch_transfer' => $validated['branch_transfer'] ?? false,
                    'recorded_by' => $actor->id,
                ],
            );

            $this->audit->record(
                actor: $actor,
                action: 'attendance.recorded',
                category: AuditEvent::CATEGORY_BUSINESS,
                module: 'attendance',
                branchId: $record->branch_id,
                subjectType: $validated['subject_type'],
                subjectId: (int) $validated['subject_id'],
                after: [
                    'gathering_date' => $validated['gathering_date'],
                    'status' => $validated['status'],
                    'service_type' => $validated['service_type'],
                ],
            );

            $this->exceptions->evaluateForSubject($actor, $subject, $record);

            return $record->fresh(['branch:id,name']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function correctAttendance(User $actor, AttendanceRecord $record, array $payload): AttendanceRecord
    {
        $this->assertCan($actor, 'attendance.write');
        $this->assertRecordInScope($actor, $record);

        $validated = validator($payload, [
            'status' => ['required', 'string', 'in:' . implode(',', config('attendance_exceptions.attendance_statuses', []))],
            'service_cancelled' => ['nullable', 'boolean'],
            'branch_transfer' => ['nullable', 'boolean'],
            'correction_reason' => ['required', 'string', 'max:500'],
        ])->validate();

        return DB::transaction(function () use ($actor, $record, $validated): AttendanceRecord {
            $before = [
                'status' => $record->status,
                'service_cancelled' => $record->service_cancelled,
                'branch_transfer' => $record->branch_transfer,
            ];

            AttendanceRecordCorrection::create([
                'attendance_record_id' => $record->id,
                'corrected_by' => $actor->id,
                'before_status' => $record->status,
                'after_status' => $validated['status'],
                'reason' => $validated['correction_reason'],
                'created_at' => now(),
            ]);

            $record->update([
                'status' => $validated['status'],
                'service_cancelled' => $validated['service_cancelled'] ?? $record->service_cancelled,
                'branch_transfer' => $validated['branch_transfer'] ?? $record->branch_transfer,
                'original_status' => $record->original_status ?? $before['status'],
                'correction_reason' => $validated['correction_reason'],
                'corrected_at' => now(),
            ]);

            $subject = $this->resolveSubject($record->subject_type, (int) $record->subject_id);

            $this->audit->record(
                actor: $actor,
                action: 'attendance.corrected',
                category: AuditEvent::CATEGORY_BUSINESS,
                module: 'attendance',
                branchId: $record->branch_id,
                subjectType: $record->subject_type,
                subjectId: (int) $record->subject_id,
                before: $before,
                after: [
                    'status' => $record->status,
                    'service_cancelled' => $record->service_cancelled,
                    'branch_transfer' => $record->branch_transfer,
                ],
            );

            $this->exceptions->evaluateForSubject($actor, $subject, $record->fresh());

            return $record->fresh(['branch:id,name']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validatePayload(array $payload): array
    {
        return validator($payload, [
            'subject_type' => ['required', 'string', 'in:' . Member::class . ',' . Visitor::class],
            'subject_id' => ['required', 'integer', 'min:1'],
            'branch_id' => ['required', 'integer', 'exists:organizations,id'],
            'service_type' => ['required', 'string', 'max:64'],
            'gathering_date' => ['required', 'date'],
            'status' => ['required', 'string', 'in:' . implode(',', config('attendance_exceptions.attendance_statuses', []))],
            'team_id' => ['nullable', 'integer', 'min:1'],
            'service_cancelled' => ['nullable', 'boolean'],
            'branch_transfer' => ['nullable', 'boolean'],
        ])->validate();
    }

    private function resolveSubject(string $type, int $id): Model
    {
        $subject = match ($type) {
            Member::class => Member::query()->find($id),
            Visitor::class => Visitor::query()->find($id),
            default => null,
        };

        if ($subject === null) {
            throw ValidationException::withMessages(['subject_id' => ['Subject not found.']]);
        }

        return $subject;
    }

    private function assertCan(User $actor, string $action): void
    {
        if (! $this->authorization->allows($actor, $action)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function assertSubjectInScope(User $actor, Model $subject): void
    {
        $branchId = $subject->branch_id ?? null;
        if ($branchId === null || $actor->isChurchWide()) {
            return;
        }

        try {
            BranchScope::for($actor)->assertIncludes((int) $branchId);
        } catch (BranchScopeException) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function assertRecordInScope(User $actor, AttendanceRecord $record): void
    {
        if ($actor->isChurchWide()) {
            return;
        }

        try {
            BranchScope::for($actor)->assertIncludes((int) $record->branch_id);
        } catch (BranchScopeException) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    /** @param  Builder<AttendanceRecord>  $query */
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
