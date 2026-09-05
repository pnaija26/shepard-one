<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Member;
use App\Models\MemberNotification;
use App\Models\TrainingAssessmentCorrection;
use App\Models\TrainingAssessmentResult;
use App\Models\TrainingCertificate;
use App\Models\TrainingCompletionRecord;
use App\Models\TrainingEnrolment;
use App\Models\TrainingOffering;
use App\Models\TrainingOfferingVersion;
use App\Models\TrainingSessionAttendance;
use App\Models\TrainingSessionAttendanceCorrection;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Story 6.4: training attendance, assessments, completion, and certificates.
 */
class TrainingProgressService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getProgress(User $actor, TrainingEnrolment $enrolment): array
    {
        $this->assertCanReadProgress($actor, $enrolment);
        $enrolment->load([
            'member:id,first_name,last_name,membership_id',
            'offering:id,name,branch_id',
            'version:id,version,completion_rules,assessments',
            'sessionAttendance.corrections',
            'assessmentResults.corrections',
            'completionRecord',
            'certificate',
        ]);

        $evaluation = $this->evaluateProgress($enrolment);
        $this->syncCompletionRecord($enrolment, $evaluation);

        return $this->formatProgress($enrolment->fresh([
            'sessionAttendance.corrections',
            'assessmentResults.corrections',
            'completionRecord',
            'certificate',
        ]), $evaluation);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function recordAttendance(User $actor, TrainingEnrolment $enrolment, array $payload): array
    {
        $this->assertCanManageProgress($actor, $enrolment);
        $this->assertMutableProgress($enrolment);

        $validated = validator($payload, [
            'entries' => ['required', 'array', 'min:1'],
            'entries.*.session_key' => ['required', 'string', 'max:120'],
            'entries.*.session_title' => ['required', 'string', 'max:160'],
            'entries.*.status' => ['required', 'string', 'in:' . implode(',', config('training_progress.attendance_statuses', []))],
        ])->validate();

        return DB::transaction(function () use ($actor, $enrolment, $validated): array {
            $records = [];

            foreach ($validated['entries'] as $entry) {
                $record = TrainingSessionAttendance::updateOrCreate(
                    [
                        'training_enrolment_id' => $enrolment->id,
                        'session_key' => $entry['session_key'],
                    ],
                    [
                        'session_title' => $entry['session_title'],
                        'status' => $entry['status'],
                        'recorded_by' => $actor->id,
                    ],
                );

                $records[] = $record;
            }

            $this->audit($actor, 'training.attendance.recorded', $enrolment, [
                'count' => count($records),
            ]);

            $evaluation = $this->evaluateProgress($enrolment->fresh(['sessionAttendance', 'assessmentResults', 'version']));
            $this->syncCompletionRecord($enrolment, $evaluation);

            return $this->formatProgress($enrolment->fresh([
                'sessionAttendance.corrections',
                'assessmentResults.corrections',
                'completionRecord',
                'certificate',
            ]), $evaluation);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function correctAttendance(User $actor, TrainingSessionAttendance $record, array $payload): TrainingSessionAttendance
    {
        $enrolment = $record->enrolment ?? TrainingEnrolment::query()->findOrFail($record->training_enrolment_id);
        $this->assertCanCorrectProgress($actor, $enrolment);
        $this->assertMutableProgress($enrolment);

        $validated = validator($payload, [
            'status' => ['required', 'string', 'in:' . implode(',', config('training_progress.attendance_statuses', []))],
            'reason' => ['required', 'string', 'max:500'],
        ])->validate();

        if ($record->status === $validated['status']) {
            throw ValidationException::withMessages(['status' => ['Attendance status is unchanged.']]);
        }

        return DB::transaction(function () use ($actor, $record, $validated, $enrolment): TrainingSessionAttendance {
            $before = $record->status;

            TrainingSessionAttendanceCorrection::create([
                'training_session_attendance_id' => $record->id,
                'corrected_by' => $actor->id,
                'before_status' => $before,
                'after_status' => $validated['status'],
                'reason' => $validated['reason'],
                'created_at' => now(),
            ]);

            $record->update(['status' => $validated['status']]);

            $this->audit($actor, 'training.attendance.corrected', $enrolment, [
                'record_id' => $record->id,
                'before' => $before,
                'after' => $validated['status'],
            ]);

            $evaluation = $this->evaluateProgress($enrolment->fresh(['sessionAttendance', 'assessmentResults', 'version']));
            $this->syncCompletionRecord($enrolment, $evaluation);

            return $record->fresh(['corrections']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function recordAssessments(User $actor, TrainingEnrolment $enrolment, array $payload): array
    {
        $this->assertCanManageProgress($actor, $enrolment);
        $this->assertMutableProgress($enrolment);

        $validated = validator($payload, [
            'entries' => ['required', 'array', 'min:1'],
            'entries.*.assessment_key' => ['required', 'string', 'max:120'],
            'entries.*.assessment_title' => ['required', 'string', 'max:160'],
            'entries.*.result_status' => ['required', 'string', 'in:' . implode(',', config('training_progress.assessment_statuses', []))],
            'entries.*.score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'entries.*.notes' => ['nullable', 'string', 'max:500'],
        ])->validate();

        return DB::transaction(function () use ($actor, $enrolment, $validated): array {
            foreach ($validated['entries'] as $entry) {
                TrainingAssessmentResult::updateOrCreate(
                    [
                        'training_enrolment_id' => $enrolment->id,
                        'assessment_key' => $entry['assessment_key'],
                    ],
                    [
                        'assessment_title' => $entry['assessment_title'],
                        'result_status' => $entry['result_status'],
                        'score' => $entry['score'] ?? null,
                        'notes' => $entry['notes'] ?? null,
                        'recorded_by' => $actor->id,
                    ],
                );
            }

            $this->audit($actor, 'training.assessment.recorded', $enrolment, [
                'count' => count($validated['entries']),
            ]);

            $evaluation = $this->evaluateProgress($enrolment->fresh(['sessionAttendance', 'assessmentResults', 'version']));
            $this->syncCompletionRecord($enrolment, $evaluation);

            return $this->formatProgress($enrolment->fresh([
                'sessionAttendance.corrections',
                'assessmentResults.corrections',
                'completionRecord',
                'certificate',
            ]), $evaluation);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function correctAssessment(User $actor, TrainingAssessmentResult $result, array $payload): TrainingAssessmentResult
    {
        $enrolment = $result->enrolment ?? TrainingEnrolment::query()->findOrFail($result->training_enrolment_id);
        $this->assertCanCorrectProgress($actor, $enrolment);
        $this->assertMutableProgress($enrolment);

        $validated = validator($payload, [
            'result_status' => ['required', 'string', 'in:' . implode(',', config('training_progress.assessment_statuses', []))],
            'reason' => ['required', 'string', 'max:500'],
        ])->validate();

        if ($result->result_status === $validated['result_status']) {
            throw ValidationException::withMessages(['result_status' => ['Assessment status is unchanged.']]);
        }

        return DB::transaction(function () use ($actor, $result, $validated, $enrolment): TrainingAssessmentResult {
            $before = $result->result_status;

            TrainingAssessmentCorrection::create([
                'training_assessment_result_id' => $result->id,
                'corrected_by' => $actor->id,
                'before_status' => $before,
                'after_status' => $validated['result_status'],
                'reason' => $validated['reason'],
                'created_at' => now(),
            ]);

            $result->update(['result_status' => $validated['result_status']]);

            $this->audit($actor, 'training.assessment.corrected', $enrolment, [
                'result_id' => $result->id,
                'before' => $before,
                'after' => $validated['result_status'],
            ]);

            $evaluation = $this->evaluateProgress($enrolment->fresh(['sessionAttendance', 'assessmentResults', 'version']));
            $this->syncCompletionRecord($enrolment, $evaluation);

            return $result->fresh(['corrections']);
        });
    }

    public function confirmCompletion(User $actor, TrainingEnrolment $enrolment): array
    {
        $this->assertCan($actor, 'training.completion.confirm');
        $this->assertEnrolmentInScope($actor, $enrolment);

        if (TrainingCertificate::query()
            ->where('training_enrolment_id', $enrolment->id)
            ->where('status', TrainingCertificate::STATUS_ISSUED)
            ->exists()) {
            throw new TrainingProgressException('A certificate has already been issued for this enrolment.', 'duplicate_certificate', 422);
        }

        if ($enrolment->status !== TrainingEnrolment::STATUS_ENROLLED) {
            throw new TrainingProgressException('Only active enrolments can be completed.', 'invalid_enrolment_status', 422);
        }

        $enrolment->load(['offering', 'version', 'sessionAttendance', 'assessmentResults']);
        $evaluation = $this->evaluateProgress($enrolment);

        if (! $evaluation['requirements_met']) {
            throw new TrainingProgressException(
                'Completion requirements are not yet satisfied.',
                'requirements_not_met',
                422,
                ['unmet_criteria' => $evaluation['unmet_criteria']],
            );
        }

        return DB::transaction(function () use ($actor, $enrolment, $evaluation): array {
            $offering = $enrolment->offering;
            $version = $enrolment->version;
            $completionDate = now()->toDateString();
            $reference = $this->generateCertificateReference();

            TrainingCompletionRecord::updateOrCreate(
                ['training_enrolment_id' => $enrolment->id],
                [
                    'status' => TrainingCompletionRecord::STATUS_COMPLETED,
                    'progress_percent' => 100,
                    'unmet_criteria' => [],
                    'confirmed_by' => $actor->id,
                    'completed_at' => now(),
                ],
            );

            $certificate = TrainingCertificate::create([
                'training_enrolment_id' => $enrolment->id,
                'training_offering_id' => $offering->id,
                'training_offering_version_id' => $version->id,
                'member_id' => $enrolment->member_id,
                'branch_id' => $enrolment->branch_id,
                'certificate_reference' => $reference,
                'course_name' => $offering->name,
                'course_version' => $version->version,
                'completion_date' => $completionDate,
                'status' => TrainingCertificate::STATUS_ISSUED,
                'issued_by' => $actor->id,
            ]);

            $enrolment->update(['status' => TrainingEnrolment::STATUS_COMPLETED]);

            $this->audit($actor, 'training.completion.confirmed', $enrolment, [
                'certificate_reference' => $reference,
            ]);

            $this->notifyCompletion($enrolment, $certificate);

            return [
                'completion' => $this->formatCompletionRecord($enrolment->fresh('completionRecord')->completionRecord),
                'certificate' => $this->formatCertificate($certificate),
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function revokeCertificate(User $actor, TrainingCertificate $certificate, array $payload): TrainingCertificate
    {
        $this->assertCan($actor, 'training.certificates.revoke');
        $this->assertEnrolmentInScope($actor, $certificate->enrolment ?? TrainingEnrolment::query()->findOrFail($certificate->training_enrolment_id));

        if ($certificate->status === TrainingCertificate::STATUS_REVOKED) {
            throw new TrainingProgressException('Certificate is already revoked.', 'already_revoked', 422);
        }

        $validated = validator($payload, [
            'reason' => ['required', 'string', 'max:500'],
        ])->validate();

        return DB::transaction(function () use ($actor, $certificate, $validated): TrainingCertificate {
            $certificate->update([
                'status' => TrainingCertificate::STATUS_REVOKED,
                'revoked_by' => $actor->id,
                'revoked_at' => now(),
                'revocation_reason' => $validated['reason'],
            ]);

            TrainingCompletionRecord::query()
                ->where('training_enrolment_id', $certificate->training_enrolment_id)
                ->update([
                    'status' => TrainingCompletionRecord::STATUS_REVOKED,
                    'unmet_criteria' => [['criterion' => 'certificate_revoked', 'detail' => $validated['reason']]],
                ]);

            TrainingEnrolment::query()
                ->where('id', $certificate->training_enrolment_id)
                ->update(['status' => TrainingEnrolment::STATUS_ENROLLED]);

            $this->audit($actor, 'training.certificate.revoked', $certificate->enrolment, [
                'certificate_reference' => $certificate->certificate_reference,
                'reason' => $validated['reason'],
            ]);

            return $certificate->fresh();
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function verifyCertificate(User $actor, string $reference): array
    {
        $this->assertCan($actor, 'training.certificates.verify');

        $certificate = TrainingCertificate::query()
            ->with(['member:id,first_name,last_name,membership_id', 'offering:id,name'])
            ->where('certificate_reference', strtoupper(trim($reference)))
            ->first();

        if ($certificate === null) {
            return [
                'valid' => false,
                'message' => 'Certificate reference not recognized.',
            ];
        }

        $this->assertEnrolmentInScope($actor, $certificate->enrolment ?? TrainingEnrolment::query()->findOrFail($certificate->training_enrolment_id));

        if ($certificate->status === TrainingCertificate::STATUS_REVOKED) {
            return [
                'valid' => false,
                'message' => 'Certificate has been revoked.',
                'certificate' => $this->formatCertificate($certificate),
                'revocation_reason' => $certificate->revocation_reason,
            ];
        }

        return [
            'valid' => true,
            'message' => 'Certificate is valid.',
            'certificate' => $this->formatCertificate($certificate),
        ];
    }

    public static function memberHasValidCompletion(int $memberId, int $offeringId): bool
    {
        return TrainingCertificate::query()
            ->where('member_id', $memberId)
            ->where('training_offering_id', $offeringId)
            ->where('status', TrainingCertificate::STATUS_ISSUED)
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function evaluateProgress(TrainingEnrolment $enrolment): array
    {
        $version = $enrolment->version ?? TrainingOfferingVersion::query()->find($enrolment->training_offering_version_id);
        $rules = $version?->completion_rules ?? [];
        $sessions = $enrolment->schedule_snapshot ?? $version?->sessions ?? [];
        $assessments = $version?->assessments ?? [];

        $presentStatuses = config('training_progress.present_statuses', ['present', 'late']);
        $attendanceRecords = $enrolment->relationLoaded('sessionAttendance')
            ? $enrolment->sessionAttendance
            : $enrolment->sessionAttendance()->get();

        $presentCount = $attendanceRecords->whereIn('status', $presentStatuses)->count();
        $minSessions = (int) ($rules['min_attendance_sessions'] ?? count($sessions));
        $attendanceMet = $presentCount >= $minSessions;

        $requiredAssessments = collect($assessments)->filter(fn (array $a) => (bool) ($a['required'] ?? false));
        $resultRecords = $enrolment->relationLoaded('assessmentResults')
            ? $enrolment->assessmentResults
            : $enrolment->assessmentResults()->get();

        $passedKeys = $resultRecords
            ->whereIn('result_status', ['passed', 'exempt'])
            ->pluck('assessment_key')
            ->all();

        $assessmentFailures = [];
        foreach ($requiredAssessments as $index => $assessment) {
            $key = $assessment['title'] ?? ('assessment_' . $index);
            if (! in_array($key, $passedKeys, true)) {
                $assessmentFailures[] = [
                    'criterion' => 'required_assessment',
                    'detail' => 'Assessment not passed: ' . ($assessment['title'] ?? $key),
                    'assessment_key' => $key,
                ];
            }
        }

        $unmet = [];
        if (! $attendanceMet) {
            $unmet[] = [
                'criterion' => 'min_attendance_sessions',
                'detail' => "Attendance {$presentCount}/{$minSessions} sessions.",
                'present_count' => $presentCount,
                'required_count' => $minSessions,
            ];
        }

        $unmet = array_merge($unmet, $assessmentFailures);

        $totalRequirements = max(1, $minSessions > 0 ? 1 : 0) + $requiredAssessments->count();
        $metCount = ($attendanceMet ? 1 : 0) + ($requiredAssessments->count() - count($assessmentFailures));
        $progressPercent = round(($metCount / max(1, $totalRequirements)) * 100, 1);

        return [
            'present_count' => $presentCount,
            'min_sessions' => $minSessions,
            'attendance_met' => $attendanceMet,
            'assessments_met' => $assessmentFailures === [],
            'requirements_met' => $attendanceMet && $assessmentFailures === [],
            'progress_percent' => $progressPercent,
            'unmet_criteria' => $unmet,
        ];
    }

    /**
     * @param  array<string, mixed>  $evaluation
     */
    private function syncCompletionRecord(TrainingEnrolment $enrolment, array $evaluation): void
    {
        if (TrainingCertificate::query()
            ->where('training_enrolment_id', $enrolment->id)
            ->where('status', TrainingCertificate::STATUS_ISSUED)
            ->exists()) {
            return;
        }

        $status = $evaluation['requirements_met']
            ? TrainingCompletionRecord::STATUS_READY
            : ($evaluation['progress_percent'] > 0
                ? TrainingCompletionRecord::STATUS_IN_PROGRESS
                : TrainingCompletionRecord::STATUS_INCOMPLETE);

        TrainingCompletionRecord::updateOrCreate(
            ['training_enrolment_id' => $enrolment->id],
            [
                'status' => $status,
                'progress_percent' => $evaluation['progress_percent'],
                'unmet_criteria' => $evaluation['unmet_criteria'],
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $evaluation
     * @return array<string, mixed>
     */
    private function formatProgress(TrainingEnrolment $enrolment, array $evaluation): array
    {
        return [
            'enrolment_id' => $enrolment->id,
            'member' => $enrolment->relationLoaded('member') && $enrolment->member ? [
                'id' => $enrolment->member->id,
                'full_name' => $enrolment->member->fullName(),
                'membership_id' => $enrolment->member->membership_id,
            ] : null,
            'offering_name' => $enrolment->offering?->name,
            'progress_percent' => $evaluation['progress_percent'],
            'requirements_met' => $evaluation['requirements_met'],
            'unmet_criteria' => $evaluation['unmet_criteria'],
            'attendance' => $enrolment->sessionAttendance->map(fn (TrainingSessionAttendance $record) => [
                'id' => $record->id,
                'session_key' => $record->session_key,
                'session_title' => $record->session_title,
                'status' => $record->status,
                'corrections' => $record->relationLoaded('corrections')
                    ? $record->corrections->map(fn (TrainingSessionAttendanceCorrection $c) => [
                        'before_status' => $c->before_status,
                        'after_status' => $c->after_status,
                        'reason' => $c->reason,
                        'corrected_by' => $c->corrected_by,
                        'created_at' => $c->created_at?->toIso8601String(),
                    ])->values()->all()
                    : [],
            ])->values()->all(),
            'assessments' => $enrolment->assessmentResults->map(fn (TrainingAssessmentResult $result) => [
                'id' => $result->id,
                'assessment_key' => $result->assessment_key,
                'assessment_title' => $result->assessment_title,
                'result_status' => $result->result_status,
                'score' => $result->score,
                'corrections' => $result->relationLoaded('corrections')
                    ? $result->corrections->map(fn (TrainingAssessmentCorrection $c) => [
                        'before_status' => $c->before_status,
                        'after_status' => $c->after_status,
                        'reason' => $c->reason,
                        'corrected_by' => $c->corrected_by,
                        'created_at' => $c->created_at?->toIso8601String(),
                    ])->values()->all()
                    : [],
            ])->values()->all(),
            'completion' => $enrolment->completionRecord
                ? $this->formatCompletionRecord($enrolment->completionRecord)
                : null,
            'certificate' => $enrolment->certificate
                ? $this->formatCertificate($enrolment->certificate)
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatCompletionRecord(?TrainingCompletionRecord $record): array
    {
        if ($record === null) {
            return [];
        }

        return [
            'status' => $record->status,
            'progress_percent' => (float) $record->progress_percent,
            'unmet_criteria' => $record->unmet_criteria ?? [],
            'completed_at' => $record->completed_at?->toIso8601String(),
            'confirmed_by' => $record->confirmed_by,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formatCertificate(TrainingCertificate $certificate): array
    {
        return [
            'id' => $certificate->id,
            'certificate_reference' => $certificate->certificate_reference,
            'course_name' => $certificate->course_name,
            'course_version' => $certificate->course_version,
            'completion_date' => $certificate->completion_date?->toDateString(),
            'status' => $certificate->status,
            'revocation_reason' => $certificate->revocation_reason,
            'revoked_at' => $certificate->revoked_at?->toIso8601String(),
            'member' => $certificate->relationLoaded('member') && $certificate->member ? [
                'id' => $certificate->member->id,
                'full_name' => $certificate->member->fullName(),
                'membership_id' => $certificate->member->membership_id,
            ] : null,
        ];
    }

    private function generateCertificateReference(): string
    {
        do {
            $reference = 'TRN-' . strtoupper(Str::random(10));
        } while (TrainingCertificate::query()->where('certificate_reference', $reference)->exists());

        return $reference;
    }

    private function notifyCompletion(TrainingEnrolment $enrolment, TrainingCertificate $certificate): void
    {
        $member = Member::query()->find($enrolment->member_id);
        if ($member === null || $member->user_id === null) {
            return;
        }

        MemberNotification::create([
            'member_id' => $member->id,
            'user_id' => $member->user_id,
            'type' => 'training.certificate.issued',
            'message' => 'Congratulations! Your certificate for ' . $certificate->course_name . ' has been issued.',
            'metadata' => [
                'certificate_reference' => $certificate->certificate_reference,
                'training_offering_id' => $certificate->training_offering_id,
            ],
        ]);
    }

    private function assertMutableProgress(TrainingEnrolment $enrolment): void
    {
        if (TrainingCertificate::query()
            ->where('training_enrolment_id', $enrolment->id)
            ->where('status', TrainingCertificate::STATUS_ISSUED)
            ->exists()) {
            throw new TrainingProgressException(
                'Progress records cannot be changed after a certificate has been issued.',
                'progress_locked',
                422,
            );
        }
    }

    private function assertCanReadProgress(User $actor, TrainingEnrolment $enrolment): void
    {
        if ($this->authorization->allows($actor, 'training.progress.read')) {
            $this->assertEnrolmentInScope($actor, $enrolment);

            return;
        }

        $linked = Member::query()->where('user_id', $actor->id)->first();
        if ($linked !== null && (int) $linked->id === (int) $enrolment->member_id) {
            return;
        }

        throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
    }

    private function assertCanManageProgress(User $actor, TrainingEnrolment $enrolment): void
    {
        $this->assertCan($actor, 'training.progress.manage');
        $this->assertEnrolmentInScope($actor, $enrolment);

        if ($enrolment->status !== TrainingEnrolment::STATUS_ENROLLED) {
            throw new TrainingProgressException('Progress can only be recorded for active enrolments.', 'invalid_enrolment_status', 422);
        }
    }

    private function assertCanCorrectProgress(User $actor, TrainingEnrolment $enrolment): void
    {
        $this->assertCan($actor, 'training.progress.correct');
        $this->assertEnrolmentInScope($actor, $enrolment);
    }

    private function assertCan(User $actor, string $action): void
    {
        if (! $this->authorization->allows($actor, $action)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function assertEnrolmentInScope(User $actor, TrainingEnrolment $enrolment): void
    {
        if ($actor->isChurchWide()) {
            return;
        }

        try {
            BranchScope::for($actor)->assertIncludes((int) $enrolment->branch_id);
        } catch (BranchScopeException) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function audit(User $actor, string $action, TrainingEnrolment $enrolment, ?array $metadata = null): void
    {
        $this->audit->record(
            actor: $actor,
            action: $action,
            category: AuditEvent::CATEGORY_BUSINESS,
            module: 'training',
            branchId: $enrolment->branch_id,
            subjectType: TrainingEnrolment::class,
            subjectId: $enrolment->id,
            after: array_filter(['metadata' => $metadata]),
        );
    }
}
