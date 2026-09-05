<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\AuditEvent;
use App\Models\ChurchEvent;
use App\Models\ChurchService;
use App\Models\Member;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Story 4.4: capture attendance through approved methods and sessions.
 */
class AttendanceCaptureService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
        private AttendanceExceptionService $exceptions,
        private MembershipCardService $membershipCards,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function capture(User $actor, array $payload): AttendanceRecord
    {
        $this->assertCan($actor, 'attendance.write');

        $validated = $this->validateCapturePayload($payload);
        $subject = $this->resolveSubject($validated);
        $this->assertSubjectInScope($actor, $subject);

        $session = $this->resolveSession($validated);
        $this->assertSessionInScope($actor, $session);

        if (! empty($validated['client_reference'])) {
            $existing = AttendanceRecord::query()
                ->where('client_reference', $validated['client_reference'])
                ->first();

            if ($existing !== null) {
                return $existing->load(['branch:id,name']);
            }
        }

        $this->assertNoSessionDuplicate($subject, $session);

        if ((int) $subject->branch_id !== (int) $session['branch_id'] && ! ($validated['branch_transfer'] ?? false)) {
            throw new AttendanceCaptureException(
                'This person is not assigned to this branch session.',
                'wrong_branch',
                422,
                'Confirm branch transfer or choose the correct session.',
            );
        }

        return $this->persistCapture($actor, $subject, $session, $validated);
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return array{synced: int, conflicts: array<int, array<string, mixed>>, records: array<int, array<string, mixed>>}
     */
    public function syncOffline(User $actor, array $entries): array
    {
        $this->assertCan($actor, 'attendance.write');

        $limit = (int) config('attendance.offline_sync_batch_limit', 100);
        if (count($entries) > $limit) {
            throw ValidationException::withMessages([
                'entries' => ["A maximum of {$limit} entries can be synchronized at once."],
            ]);
        }

        $records = [];
        $conflicts = [];
        $synced = 0;

        foreach ($entries as $index => $entry) {
            if (! is_array($entry)) {
                throw ValidationException::withMessages([
                    "entries.{$index}" => ['Each entry must be an object.'],
                ]);
            }

            $entry['offline'] = true;
            $entry['syncing'] = true;
            $entry['sync_status'] = $entry['sync_status'] ?? 'pending';

            try {
                $record = $this->capture($actor, $entry);
                $records[] = $this->formatRecord($record);
                $synced++;
            } catch (AttendanceCaptureException $e) {
                if ($e->reason === 'duplicate') {
                    $existing = $this->findSessionDuplicate(
                        $this->resolveSubject($this->validateCapturePayload($entry)),
                        $this->resolveSession($this->validateCapturePayload($entry)),
                    );

                    if ($existing !== null && $existing->status !== ($entry['status'] ?? null)) {
                        $existing->update(['sync_status' => 'conflict']);
                        $conflicts[] = [
                            'client_reference' => $entry['client_reference'] ?? null,
                            'reason' => 'conflict',
                            'message' => 'Offline entry conflicts with an existing attendance record.',
                            'record_id' => $existing->id,
                            'existing_status' => $existing->status,
                            'incoming_status' => $entry['status'] ?? null,
                        ];
                    } elseif ($existing !== null) {
                        $records[] = $this->formatRecord($existing);
                        $synced++;
                    } else {
                        $conflicts[] = [
                            'client_reference' => $entry['client_reference'] ?? null,
                            'reason' => $e->reason,
                            'message' => $e->getMessage(),
                        ];
                    }
                } else {
                    $conflicts[] = [
                        'client_reference' => $entry['client_reference'] ?? null,
                        'reason' => $e->reason,
                        'message' => $e->getMessage(),
                        'next_step' => $e->nextStep,
                    ];
                }
            }
        }

        return [
            'synced' => $synced,
            'conflicts' => $conflicts,
            'records' => $records,
        ];
    }

    /**
     * @return Collection<int, AttendanceRecord>
     */
    public function listSessionRecords(User $actor, string $sessionType, int $sessionId): Collection
    {
        $this->assertCan($actor, 'attendance.read');

        $query = AttendanceRecord::query()
            ->with(['branch:id,name'])
            ->where('session_type', $sessionType)
            ->where('session_id', $sessionId)
            ->orderByDesc('captured_at');

        if (! $actor->isChurchWide()) {
            try {
                $scope = BranchScope::for($actor);
                $query->whereIn('branch_id', $scope->subtreeIds((int) $scope->branchId()));
            } catch (BranchScopeException) {
                $query->whereRaw('1 = 0');
            }
        }

        return $query->limit(500)->get();
    }

  /**
     * @return array<string, mixed>
     */
    public function formatRecord(AttendanceRecord $record): array
    {
        return [
            'id' => $record->id,
            'subject_type' => $record->subject_type,
            'subject_id' => $record->subject_id,
            'branch_id' => $record->branch_id,
            'session_type' => $record->session_type,
            'session_id' => $record->session_id,
            'service_type' => $record->service_type,
            'gathering_date' => $record->gathering_date?->toDateString(),
            'status' => $record->status,
            'capture_method' => $record->capture_method,
            'captured_at' => $record->captured_at?->toIso8601String(),
            'device_id' => $record->device_id,
            'sync_status' => $record->sync_status,
            'client_reference' => $record->client_reference,
            'corrected_at' => $record->corrected_at?->toIso8601String(),
            'original_status' => $record->original_status,
            'correction_reason' => $record->correction_reason,
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{branch_id: int, gathering_date: string, service_type: string, session_type: string, session_id: int, title: string}
     */
    private function resolveSession(array $validated): array
    {
        $sessionKey = $validated['session_key'];
        $modelClass = config("attendance.session_models.{$sessionKey}");

        if ($modelClass !== null) {
            $session = $modelClass::query()->find($validated['session_id']);
            if ($session === null) {
                throw ValidationException::withMessages(['session_id' => ['Session not found.']]);
            }

            if ($session instanceof ChurchService) {
                if ($session->status !== ChurchService::STATUS_PUBLISHED) {
                    throw new AttendanceCaptureException('This service is not open for attendance capture.', 'wrong_session', 422);
                }

                return [
                    'branch_id' => (int) $session->branch_id,
                    'gathering_date' => $session->service_date->toDateString(),
                    'service_type' => $session->service_type,
                    'session_type' => $sessionKey,
                    'session_id' => (int) $session->id,
                    'title' => $session->title,
                ];
            }

            if ($session instanceof ChurchEvent) {
                if (! in_array($session->status, [ChurchEvent::STATUS_PUBLISHED, ChurchEvent::STATUS_COMPLETED], true)) {
                    throw new AttendanceCaptureException('This event is not open for attendance capture.', 'wrong_session', 422);
                }

                return [
                    'branch_id' => (int) $session->branch_id,
                    'gathering_date' => $session->event_date->toDateString(),
                    'service_type' => 'church_event',
                    'session_type' => $sessionKey,
                    'session_id' => (int) $session->id,
                    'title' => $session->title,
                ];
            }
        }

        if (! in_array($sessionKey, config('attendance.generic_session_types', []), true)) {
            throw ValidationException::withMessages(['session_key' => ['Unsupported session type.']]);
        }

        return [
            'branch_id' => (int) $validated['branch_id'],
            'gathering_date' => $validated['gathering_date'],
            'service_type' => $validated['service_type'],
            'session_type' => $sessionKey,
            'session_id' => (int) $validated['session_id'],
            'title' => $validated['session_label'] ?? ucfirst($sessionKey) . ' session',
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function persistCapture(User $actor, Model $subject, array $session, array $validated): AttendanceRecord
    {
        return DB::transaction(function () use ($actor, $subject, $session, $validated): AttendanceRecord {
            $capturedAt = isset($validated['captured_at'])
                ? Carbon::parse($validated['captured_at'])
                : now();

            $record = AttendanceRecord::create([
                'subject_type' => $subject::class,
                'subject_id' => $subject->id,
                'branch_id' => $session['branch_id'],
                'service_type' => $session['service_type'],
                'gathering_date' => $session['gathering_date'],
                'session_type' => $session['session_type'],
                'session_id' => $session['session_id'],
                'status' => $validated['status'],
                'capture_method' => $validated['capture_method'],
                'captured_at' => $capturedAt,
                'device_id' => $validated['device_id'] ?? null,
                'sync_status' => $validated['sync_status'] ?? (($validated['offline'] ?? false) ? 'pending' : 'synced'),
                'client_reference' => $validated['client_reference'] ?? null,
                'team_id' => $validated['team_id'] ?? null,
                'service_cancelled' => $validated['service_cancelled'] ?? false,
                'branch_transfer' => $validated['branch_transfer'] ?? false,
                'recorded_by' => $actor->id,
            ]);

            $this->audit->record(
                actor: $actor,
                action: 'attendance.captured',
                category: AuditEvent::CATEGORY_BUSINESS,
                module: 'attendance',
                branchId: $record->branch_id,
                subjectType: $subject::class,
                subjectId: (int) $subject->id,
                after: [
                    'record_id' => $record->id,
                    'session_type' => $record->session_type,
                    'session_id' => $record->session_id,
                    'status' => $record->status,
                    'capture_method' => $record->capture_method,
                ],
            );

            $this->exceptions->evaluateForSubject($actor, $subject, $record);

            if (($validated['syncing'] ?? false) && $record->sync_status === 'pending') {
                $record->update(['sync_status' => 'synced']);
                $record = $record->fresh();
            }

            return $record->load(['branch:id,name']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateCapturePayload(array $payload): array
    {
        $sessionKeys = array_merge(
            array_keys(config('attendance.session_models', [])),
            config('attendance.generic_session_types', []),
        );

        $rules = [
            'session_key' => ['required', 'string', 'in:' . implode(',', $sessionKeys)],
            'session_id' => ['required', 'integer', 'min:1'],
            'status' => ['required', 'string', 'in:' . implode(',', config('attendance_exceptions.attendance_statuses', []))],
            'capture_method' => ['required', 'string', 'in:' . implode(',', config('attendance.capture_methods', []))],
            'captured_at' => ['nullable', 'date'],
            'device_id' => ['nullable', 'string', 'max:64'],
            'client_reference' => ['nullable', 'string', 'max:64'],
            'offline' => ['nullable', 'boolean'],
            'syncing' => ['nullable', 'boolean'],
            'sync_status' => ['nullable', 'string', 'in:' . implode(',', config('attendance.sync_statuses', []))],
            'team_id' => ['nullable', 'integer', 'min:1'],
            'service_cancelled' => ['nullable', 'boolean'],
            'branch_transfer' => ['nullable', 'boolean'],
            'session_label' => ['nullable', 'string', 'max:255'],
        ];

        $sessionKey = $payload['session_key'] ?? null;
        if ($sessionKey !== null && in_array($sessionKey, config('attendance.generic_session_types', []), true)) {
            $rules['branch_id'] = ['required', 'integer', 'exists:organizations,id'];
            $rules['gathering_date'] = ['required', 'date'];
            $rules['service_type'] = ['required', 'string', 'max:64'];
        }

        if (! empty($payload['token'])) {
            $rules['token'] = ['required', 'string'];
        } elseif (! empty($payload['membership_id'])) {
            $rules['membership_id'] = ['required', 'string', 'max:64'];
        } else {
            $rules['subject_type'] = ['required', 'string', 'in:' . Member::class . ',' . Visitor::class];
            $rules['subject_id'] = ['required', 'integer', 'min:1'];
        }

        return validator($payload, $rules)->validate();
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function resolveSubject(array $validated): Model
    {
        if (! empty($validated['token'])) {
            $member = $this->membershipCards->memberFromToken($validated['token']);

            return $member;
        }

        if (! empty($validated['membership_id'])) {
            $member = Member::query()->where('membership_id', $validated['membership_id'])->first();
            if ($member === null) {
                throw new AttendanceCaptureException('Membership ID not recognized.', 'invalid_member', 422);
            }

            return $member;
        }

        $subject = match ($validated['subject_type']) {
            Member::class => Member::query()->find($validated['subject_id']),
            Visitor::class => Visitor::query()->find($validated['subject_id']),
            default => null,
        };

        if ($subject === null) {
            throw ValidationException::withMessages(['subject_id' => ['Subject not found.']]);
        }

        return $subject;
    }

    private function assertNoSessionDuplicate(Model $subject, array $session): void
    {
        if ($this->findSessionDuplicate($subject, $session) !== null) {
            throw new AttendanceCaptureException(
                'Attendance has already been captured for this person and session.',
                'duplicate',
                422,
                'Review the existing record or request an authorized correction.',
            );
        }
    }

    private function findSessionDuplicate(Model $subject, array $session): ?AttendanceRecord
    {
        return AttendanceRecord::query()
            ->where('subject_type', $subject::class)
            ->where('subject_id', $subject->id)
            ->where('session_type', $session['session_type'])
            ->where('session_id', $session['session_id'])
            ->first();
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

    /**
     * @param  array{branch_id: int}  $session
     */
    private function assertSessionInScope(User $actor, array $session): void
    {
        if ($actor->isChurchWide()) {
            return;
        }

        try {
            BranchScope::for($actor)->assertIncludes((int) $session['branch_id']);
        } catch (BranchScopeException) {
            throw new AttendanceCaptureException('This session is outside your branch scope.', 'wrong_branch', 403);
        }
    }
}
