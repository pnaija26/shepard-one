<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\AuditEvent;
use App\Models\Member;
use App\Models\ServiceTeam;
use App\Models\TeamAttendanceCorrection;
use App\Models\TeamAttendanceRecord;
use App\Models\TeamOccurrence;
use App\Models\TeamRosterSlot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Story 5.5: independent team duty/rehearsal attendance and reliability analysis.
 *
 * Team attendance is stored separately from general gathering attendance records.
 */
class TeamAttendanceService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
    ) {
    }

    /**
     * @return Collection<int, TeamOccurrence>
     */
    public function listOccurrences(User $actor, ServiceTeam $team): Collection
    {
        $this->assertCan($actor, 'teams.attendance.read');
        $this->assertTeamInScope($actor, $team);

        return TeamOccurrence::query()
            ->withCount('attendanceRecords')
            ->where('service_team_id', $team->id)
            ->orderByDesc('occurrence_date')
            ->limit(100)
            ->get();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createOccurrence(User $actor, ServiceTeam $team, array $payload): TeamOccurrence
    {
        $this->assertCan($actor, 'teams.attendance.capture');
        $this->assertTeamInScope($actor, $team);

        $validated = $this->validateOccurrencePayload($payload);

        return DB::transaction(function () use ($actor, $team, $validated): TeamOccurrence {
            $occurrence = TeamOccurrence::create([
                'service_team_id' => $team->id,
                'branch_id' => $team->branch_id,
                'occurrence_type' => $validated['occurrence_type'],
                'title' => $validated['title'],
                'occurrence_date' => $validated['occurrence_date'],
                'start_time' => $validated['start_time'] ?? null,
                'end_time' => $validated['end_time'] ?? null,
                'team_roster_id' => $validated['team_roster_id'] ?? null,
                'team_roster_slot_id' => $validated['team_roster_slot_id'] ?? null,
                'gathering_key' => $validated['gathering_key'] ?? null,
                'gathering_id' => $validated['gathering_id'] ?? null,
                'status' => TeamOccurrence::STATUS_SCHEDULED,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->audit($actor, 'team_occurrence.created', $occurrence);

            return $occurrence;
        });
    }

    public function showOccurrence(User $actor, TeamOccurrence $occurrence): TeamOccurrence
    {
        $this->assertCan($actor, 'teams.attendance.read');
        $this->assertOccurrenceInScope($actor, $occurrence);

        return $occurrence->load([
            'team:id,name',
            'attendanceRecords.member:id,first_name,last_name,membership_id',
            'attendanceRecords.corrections',
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return array{created: int, records: array<int, array<string, mixed>>}
     */
    public function captureAttendance(User $actor, TeamOccurrence $occurrence, array $entries): array
    {
        $this->assertCan($actor, 'teams.attendance.capture');
        $this->assertOccurrenceInScope($actor, $occurrence);

        if ($occurrence->status === TeamOccurrence::STATUS_CANCELLED) {
            throw ValidationException::withMessages(['occurrence' => ['Attendance cannot be captured for a cancelled occurrence.']]);
        }

        $created = [];

        return DB::transaction(function () use ($actor, $occurrence, $entries, &$created): array {
            foreach ($entries as $index => $entry) {
                if (! is_array($entry)) {
                    throw ValidationException::withMessages(["entries.{$index}" => ['Each entry must be an object.']]);
                }

                $validated = validator($entry, [
                    'member_id' => ['required', 'integer', 'exists:members,id'],
                    'status' => ['required', 'string', 'in:' . implode(',', config('team_attendance.attendance_statuses', []))],
                    'team_roster_slot_id' => ['nullable', 'integer', 'exists:team_roster_slots,id'],
                ])->validate();

                $member = Member::query()->findOrFail($validated['member_id']);
                $this->assertMemberOnTeam($occurrence, $member, $validated['team_roster_slot_id'] ?? null);

                $record = TeamAttendanceRecord::query()->updateOrCreate(
                    [
                        'team_occurrence_id' => $occurrence->id,
                        'member_id' => $member->id,
                    ],
                    [
                        'team_roster_slot_id' => $validated['team_roster_slot_id'] ?? null,
                        'status' => $validated['status'],
                        'captured_at' => now(),
                        'recorded_by' => $actor->id,
                    ],
                );

                $created[] = $this->formatRecord($record->fresh(['member:id,first_name,last_name']));
            }

            if ($occurrence->status === TeamOccurrence::STATUS_SCHEDULED) {
                $occurrence->update([
                    'status' => TeamOccurrence::STATUS_COMPLETED,
                    'updated_by' => $actor->id,
                ]);
            }

            $this->audit($actor, 'team_attendance.captured', $occurrence, [
                'records' => count($created),
            ]);

            return [
                'created' => count($created),
                'records' => $created,
            ];
        });
    }

    public function correctAttendance(User $actor, TeamAttendanceRecord $record, array $payload): TeamAttendanceRecord
    {
        $this->assertCan($actor, 'teams.attendance.correct');
        $occurrence = $record->occurrence ?? TeamOccurrence::query()->findOrFail($record->team_occurrence_id);
        $this->assertOccurrenceInScope($actor, $occurrence);

        $validated = validator($payload, [
            'status' => ['required', 'string', 'in:' . implode(',', config('team_attendance.attendance_statuses', []))],
            'reason' => ['required', 'string', 'max:500'],
        ])->validate();

        if ($record->status === $validated['status']) {
            return $record;
        }

        return DB::transaction(function () use ($actor, $record, $validated, $occurrence): TeamAttendanceRecord {
            $before = $record->status;

            TeamAttendanceCorrection::create([
                'team_attendance_record_id' => $record->id,
                'corrected_by' => $actor->id,
                'before_status' => $before,
                'after_status' => $validated['status'],
                'reason' => $validated['reason'],
                'created_at' => now(),
            ]);

            $record->update([
                'status' => $validated['status'],
                'original_status' => $record->original_status ?? $before,
                'correction_reason' => $validated['reason'],
                'corrected_at' => now(),
            ]);

            $this->audit($actor, 'team_attendance.corrected', $occurrence, [
                'record_id' => $record->id,
                'before' => $before,
                'after' => $validated['status'],
            ]);

            return $record->fresh(['member:id,first_name,last_name', 'corrections']);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function analyzeTeam(User $actor, ServiceTeam $team, array $filters = []): array
    {
        $this->assertCan($actor, 'teams.attendance.read');
        $this->assertTeamInScope($actor, $team);

        $fromDate = ! empty($filters['from_date'])
            ? Carbon::parse($filters['from_date'])
            : now()->subMonths(3)->startOfDay();
        $toDate = ! empty($filters['to_date'])
            ? Carbon::parse($filters['to_date'])
            : now()->endOfDay();

        $records = TeamAttendanceRecord::query()
            ->with(['member:id,first_name,last_name,membership_id', 'occurrence:id,occurrence_date,title'])
            ->whereHas('occurrence', function (Builder $query) use ($team, $fromDate, $toDate): void {
                $query->where('service_team_id', $team->id)
                    ->whereBetween('occurrence_date', [$fromDate->toDateString(), $toDate->toDateString()]);
            })
            ->get();

        $minRecords = (int) config('team_attendance.min_records_for_analysis', 3);
        $memberStats = [];
        $trend = [];

        foreach ($records as $record) {
            $memberId = $record->member_id;
            if (! isset($memberStats[$memberId])) {
                $memberStats[$memberId] = [
                    'member_id' => $memberId,
                    'member_name' => $record->member?->fullName(),
                    'present' => 0,
                    'absent' => 0,
                    'excused' => 0,
                    'late' => 0,
                    'total' => 0,
                ];
            }

            $memberStats[$memberId][$record->status]++;
            $memberStats[$memberId]['total']++;

            $month = $record->occurrence?->occurrence_date?->format('Y-m') ?? 'unknown';
            if (! isset($trend[$month])) {
                $trend[$month] = ['month' => $month, 'present' => 0, 'total' => 0];
            }
            $trend[$month]['total']++;
            if (in_array($record->status, [TeamAttendanceRecord::STATUS_PRESENT, TeamAttendanceRecord::STATUS_LATE], true)) {
                $trend[$month]['present']++;
            }
        }

        $members = [];
        $followUp = [];

        foreach ($memberStats as $stats) {
            $attended = $stats['present'] + $stats['late'];
            $percent = $stats['total'] > 0 ? round(($attended / $stats['total']) * 100, 1) : 0.0;
            $reliability = $stats['total'] >= $minRecords
                ? $this->reliabilityLevel($percent)
                : 'insufficient_data';

            $entry = array_merge($stats, [
                'attendance_percent' => $percent,
                'reliability' => $reliability,
            ]);
            $members[] = $entry;

            if ($stats['total'] >= $minRecords && $reliability === 'needs_follow_up') {
                $followUp[] = $entry;
            }
        }

        usort($members, fn (array $a, array $b) => $a['attendance_percent'] <=> $b['attendance_percent']);
        ksort($trend);

        $trendRows = array_values(array_map(function (array $row) {
            $row['attendance_percent'] = $row['total'] > 0
                ? round(($row['present'] / $row['total']) * 100, 1)
                : 0.0;

            return $row;
        }, $trend));

        $totals = [
            'records' => $records->count(),
            'occurrences' => $records->pluck('team_occurrence_id')->unique()->count(),
            'present' => $records->whereIn('status', [TeamAttendanceRecord::STATUS_PRESENT, TeamAttendanceRecord::STATUS_LATE])->count(),
            'absent' => $records->where('status', TeamAttendanceRecord::STATUS_ABSENT)->count(),
            'excused' => $records->where('status', TeamAttendanceRecord::STATUS_EXCUSED)->count(),
            'late' => $records->where('status', TeamAttendanceRecord::STATUS_LATE)->count(),
        ];

        $totals['attendance_percent'] = $totals['records'] > 0
            ? round(($totals['present'] / $totals['records']) * 100, 1)
            : 0.0;

        return [
            'team_id' => $team->id,
            'from_date' => $fromDate->toDateString(),
            'to_date' => $toDate->toDateString(),
            'totals' => $totals,
            'members' => $members,
            'trend' => $trendRows,
            'members_requiring_follow_up' => $followUp,
            'uses_gathering_attendance' => false,
            'gathering_attendance_records_in_scope' => $this->countGatheringAttendanceInScope($team, $fromDate, $toDate),
        ];
    }

    public function formatOccurrence(TeamOccurrence $occurrence): array
    {
        return [
            'id' => $occurrence->id,
            'service_team_id' => $occurrence->service_team_id,
            'occurrence_type' => $occurrence->occurrence_type,
            'title' => $occurrence->title,
            'occurrence_date' => $occurrence->occurrence_date?->toDateString(),
            'start_time' => $occurrence->start_time,
            'end_time' => $occurrence->end_time,
            'status' => $occurrence->status,
            'team_roster_id' => $occurrence->team_roster_id,
            'gathering_key' => $occurrence->gathering_key,
            'gathering_id' => $occurrence->gathering_id,
            'attendance_count' => $occurrence->attendance_records_count ?? $occurrence->attendanceRecords?->count(),
            'records' => $occurrence->relationLoaded('attendanceRecords')
                ? $occurrence->attendanceRecords->map(fn (TeamAttendanceRecord $record) => $this->formatRecord($record))->values()->all()
                : [],
        ];
    }

    public function formatRecord(TeamAttendanceRecord $record): array
    {
        return [
            'id' => $record->id,
            'team_occurrence_id' => $record->team_occurrence_id,
            'member_id' => $record->member_id,
            'status' => $record->status,
            'captured_at' => $record->captured_at?->toIso8601String(),
            'corrected_at' => $record->corrected_at?->toIso8601String(),
            'correction_reason' => $record->correction_reason,
            'member' => $record->relationLoaded('member') && $record->member
                ? [
                    'id' => $record->member->id,
                    'full_name' => $record->member->fullName(),
                ]
                : null,
        ];
    }

    private function reliabilityLevel(float $percent): string
    {
        $thresholds = config('team_attendance.thresholds', []);

        if ($percent >= (float) ($thresholds['reliable_percent'] ?? 90)) {
            return 'reliable';
        }

        if ($percent >= (float) ($thresholds['moderate_percent'] ?? 75)) {
            return 'moderate';
        }

        if ($percent >= (float) ($thresholds['at_risk_percent'] ?? 60)) {
            return 'at_risk';
        }

        return 'needs_follow_up';
    }

    /**
     * Exposed for transparency: gathering attendance exists separately and is not merged into analysis.
     */
    private function countGatheringAttendanceInScope(ServiceTeam $team, Carbon $fromDate, Carbon $toDate): int
    {
        return AttendanceRecord::query()
            ->where('branch_id', $team->branch_id)
            ->where('team_id', $team->id)
            ->whereDate('gathering_date', '>=', $fromDate->toDateString())
            ->whereDate('gathering_date', '<=', $toDate->toDateString())
            ->count();
    }

    private function assertMemberOnTeam(TeamOccurrence $occurrence, Member $member, ?int $rosterSlotId): void
    {
        if ((int) $member->branch_id !== (int) $occurrence->branch_id) {
            throw ValidationException::withMessages(['member_id' => ['Member is outside the team branch scope.']]);
        }

        if ($rosterSlotId !== null) {
            $slot = TeamRosterSlot::query()->find($rosterSlotId);
            if ($slot === null || (int) $slot->member_id !== (int) $member->id) {
                throw ValidationException::withMessages(['team_roster_slot_id' => ['Roster slot does not belong to this member.']]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateOccurrencePayload(array $payload): array
    {
        return validator($payload, [
            'occurrence_type' => ['required', 'string', 'in:' . implode(',', config('team_attendance.occurrence_types', []))],
            'title' => ['required', 'string', 'max:160'],
            'occurrence_date' => ['required', 'date'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'team_roster_id' => ['nullable', 'integer', 'exists:team_rosters,id'],
            'team_roster_slot_id' => ['nullable', 'integer', 'exists:team_roster_slots,id'],
            'gathering_key' => ['nullable', 'string', 'in:church_service,church_event'],
            'gathering_id' => ['nullable', 'integer'],
        ])->validate();
    }

    private function assertCan(User $actor, string $action): void
    {
        if (! $this->authorization->allows($actor, $action)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function assertTeamInScope(User $actor, ServiceTeam $team): void
    {
        if ($actor->isChurchWide()) {
            return;
        }

        try {
            BranchScope::for($actor)->assertIncludes((int) $team->branch_id);
        } catch (BranchScopeException) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function assertOccurrenceInScope(User $actor, TeamOccurrence $occurrence): void
    {
        if ($actor->isChurchWide()) {
            return;
        }

        try {
            BranchScope::for($actor)->assertIncludes((int) $occurrence->branch_id);
        } catch (BranchScopeException) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function audit(User $actor, string $action, TeamOccurrence $occurrence, ?array $metadata = null): void
    {
        $this->audit->record(
            actor: $actor,
            action: $action,
            category: AuditEvent::CATEGORY_BUSINESS,
            module: 'teams',
            branchId: $occurrence->branch_id,
            subjectType: TeamOccurrence::class,
            subjectId: $occurrence->id,
            before: null,
            after: array_filter([
                'service_team_id' => $occurrence->service_team_id,
                'status' => $occurrence->status,
                'metadata' => $metadata,
            ]),
        );
    }
}
