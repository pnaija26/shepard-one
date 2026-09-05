<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Member;
use App\Models\User;
use App\Models\Visitor;
use App\Models\VisitorDuplicateReview;
use App\Models\VisitorVisit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Story 3.1: capture visitors, visits, duplicates, and restricted responses.
 */
class VisitorService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
        private MemberDuplicateService $memberDuplicates,
        private OnboardingJourneyService $onboarding,
    ) {
    }

    /**
     * @return Collection<int, Visitor>
     */
    public function listFor(User $actor, array $filters = []): Collection
    {
        $this->assertCan($actor, 'visitors.read');

        $query = Visitor::query()
            ->with(['branch:id,name'])
            ->with(['visits' => fn ($q) => $q->orderByDesc('visit_date')->limit(1)])
            ->withCount('visits')
            ->orderByDesc('first_visit_at');

        $this->applyBranchScope($query, $actor);

        if (! empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $query->where(function (Builder $q) use ($term): void {
                $q->where('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term);
            });
        }

        return $query->get();
    }

    public function findFor(User $actor, int $visitorId): Visitor
    {
        $this->assertCan($actor, 'visitors.read');

        $visitor = Visitor::with(['branch:id,name', 'member:id,membership_id,first_name,last_name', 'visits'])
            ->findOrFail($visitorId);

        $this->assertVisitorInScope($actor, $visitor);

        return $visitor;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function capture(User $actor, array $payload, bool $force = false): Visitor
    {
        $this->assertCan($actor, 'visitors.write', (int) ($payload['branch_id'] ?? 0));

        $validated = $this->validateCapture($payload);
        $matches = $this->findPotentialDuplicates($validated);

        if ($matches->isNotEmpty() && ! $force) {
            foreach ($matches as $match) {
                VisitorDuplicateReview::create([
                    'matched_visitor_id' => $match['type'] === 'visitor' ? $match['record']->id : null,
                    'matched_member_id' => $match['type'] === 'member' ? $match['record']->id : null,
                    'confidence' => $match['confidence'],
                    'match_reason' => $match['reason'],
                    'submitted_payload' => $validated,
                    'status' => VisitorDuplicateReview::STATUS_PENDING,
                ]);
            }

            throw new VisitorDuplicateException($matches->all(), $validated);
        }

        if ($force && ! empty($validated['link_visitor_id'])) {
            $visitor = Visitor::findOrFail((int) $validated['link_visitor_id']);
            $this->assertVisitorInScope($actor, $visitor);

            return $this->recordVisit($actor, $visitor, $validated);
        }

        return DB::transaction(function () use ($actor, $validated) {
            $visitor = Visitor::create([
                'branch_id' => $validated['branch_id'],
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'email' => $validated['email'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'date_of_birth' => $validated['date_of_birth'] ?? null,
                'original_source' => $validated['source'],
                'inviter_name' => $validated['inviter_name'] ?? null,
                'inviter_member_id' => $validated['inviter_member_id'] ?? null,
                'first_visit_at' => $validated['visit_date'],
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->createVisit($visitor, $actor, $validated);

            $this->audit->record(
                actor: $actor,
                action: 'visitor.captured',
                category: AuditEvent::CATEGORY_BUSINESS,
                module: 'visitors',
                branchId: $visitor->branch_id,
                subjectType: Visitor::class,
                subjectId: $visitor->id,
            );

            $visitor = $visitor->fresh(['branch:id,name', 'visits']);
            $this->onboarding->handleEvent('visitor.captured', $visitor, $actor);

            return $visitor;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function recordReturningVisit(User $actor, Visitor $visitor, array $payload): Visitor
    {
        $this->assertCan($actor, 'visitors.write', $visitor->branch_id);
        $this->assertVisitorInScope($actor, $visitor);

        $validated = $this->validateVisit($payload);
        $validated['attendance_status'] = $validated['attendance_status'] ?? 'returning';

        return DB::transaction(function () use ($actor, $visitor, $validated) {
            $this->createVisit($visitor, $actor, $validated);
            $visitor->update(['updated_by' => $actor->id]);

            $this->audit->record(
                actor: $actor,
                action: 'visitor.visit.recorded',
                category: AuditEvent::CATEGORY_BUSINESS,
                module: 'visitors',
                branchId: $visitor->branch_id,
                subjectType: Visitor::class,
                subjectId: $visitor->id,
                metadata: ['visit_date' => $validated['visit_date']],
            );

            return $visitor->fresh(['branch:id,name', 'visits']);
        });
    }

    /**
     * @return array{filename: string, content: string}
     */
    public function export(User $actor, array $filters = []): array
    {
        $this->assertCan($actor, 'visitors.export');

        $visitors = $this->listFor($actor, $filters);
        $lines = ['"Full Name","Email","Phone","Branch","Last Visit","Membership Interest","Decisions"'];

        foreach ($visitors as $visitor) {
            $latest = $visitor->visits->first();
            $lines[] = implode(',', [
                $this->csv($visitor->fullName()),
                $this->csv($visitor->email),
                $this->csv($visitor->phone),
                $this->csv($visitor->branch?->name),
                $this->csv($latest?->visit_date?->format('Y-m-d')),
                $this->csv($latest?->membership_interest ? 'yes' : 'no'),
                $this->csv($latest ? implode(';', $latest->decisions ?? []) : ''),
            ]);
        }

        return [
            'filename' => 'visitors-export-' . now()->format('Y-m-d-His') . '.csv',
            'content' => implode("\n", $lines),
        ];
    }

    public function formatForList(Visitor $visitor, User $actor): array
    {
        $latest = $visitor->visits->first();

        return [
            'id' => $visitor->id,
            'full_name' => $visitor->fullName(),
            'email' => $visitor->email,
            'phone' => $visitor->phone,
            'branch' => $visitor->branch ? ['id' => $visitor->branch->id, 'name' => $visitor->branch->name] : null,
            'original_source' => $visitor->original_source,
            'first_visit_at' => $visitor->first_visit_at?->toIso8601String(),
            'visit_count' => $visitor->visits_count ?? $visitor->visits->count(),
            'latest_visit' => $latest ? $this->formatVisit($latest, $actor, false) : null,
        ];
    }

    public function formatForViewer(Visitor $visitor, User $actor): array
    {
        return [
            'id' => $visitor->id,
            'full_name' => $visitor->fullName(),
            'first_name' => $visitor->first_name,
            'last_name' => $visitor->last_name,
            'email' => $visitor->email,
            'phone' => $visitor->phone,
            'date_of_birth' => $visitor->date_of_birth?->format('Y-m-d'),
            'branch' => $visitor->branch ? ['id' => $visitor->branch->id, 'name' => $visitor->branch->name] : null,
            'member' => $visitor->member ? [
                'id' => $visitor->member->id,
                'membership_id' => $visitor->member->membership_id,
                'full_name' => $visitor->member->fullName(),
            ] : null,
            'original_source' => $visitor->original_source,
            'inviter_name' => $visitor->inviter_name,
            'first_visit_at' => $visitor->first_visit_at?->toIso8601String(),
            'visits' => $visitor->visits->map(fn (VisitorVisit $visit) => $this->formatVisit($visit, $actor, true))->values(),
        ];
    }

    /**
     * @param  array<int, array{type: string, record: object, confidence: string, reason: string}>  $matches
     */
    public function formatDuplicateResponse(array $matches, array $preservedInput): array
    {
        return [
            'message' => 'Potential duplicate visitor records found. Review before creating another identity.',
            'duplicate_review_required' => true,
            'preserved_input' => $preservedInput,
            'potential_matches' => collect($matches)->map(function (array $match) {
                $record = $match['record'];

                return [
                    'type' => $match['type'],
                    'id' => $record->id,
                    'full_name' => method_exists($record, 'fullName') ? $record->fullName() : trim($record->first_name . ' ' . $record->last_name),
                    'email' => $record->email ?? null,
                    'phone' => $record->phone ?? null,
                    'confidence' => $match['confidence'],
                    'reason' => $match['reason'],
                ];
            })->values(),
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function recordVisit(User $actor, Visitor $visitor, array $validated): Visitor
    {
        return DB::transaction(function () use ($actor, $visitor, $validated) {
            $validated['attendance_status'] = $validated['attendance_status'] ?? 'returning';
            $this->createVisit($visitor, $actor, $validated);
            $visitor->update(['updated_by' => $actor->id]);

            return $visitor->fresh(['branch:id,name', 'visits']);
        });
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function createVisit(Visitor $visitor, User $actor, array $validated): VisitorVisit
    {
        return VisitorVisit::create([
            'visitor_id' => $visitor->id,
            'branch_id' => $validated['branch_id'] ?? $visitor->branch_id,
            'visit_date' => $validated['visit_date'],
            'service_or_event' => $validated['service_or_event'] ?? null,
            'attendance_status' => $validated['attendance_status'] ?? 'first_timer',
            'source' => $validated['source'],
            'decisions' => $validated['decisions'] ?? null,
            'salvation_response' => $validated['salvation_response'] ?? null,
            'prayer_needs' => $validated['prayer_needs'] ?? null,
            'membership_interest' => (bool) ($validated['membership_interest'] ?? false),
            'consent_data_processing' => (bool) $validated['consent_data_processing'],
            'consent_follow_up' => (bool) ($validated['consent_follow_up'] ?? false),
            'recorded_by' => $actor->id,
            'created_at' => now(),
        ]);
    }

    private function formatVisit(VisitorVisit $visit, User $actor, bool $includeRestricted): array
    {
        $data = [
            'id' => $visit->id,
            'visit_date' => $visit->visit_date?->format('Y-m-d'),
            'service_or_event' => $visit->service_or_event,
            'attendance_status' => $visit->attendance_status,
            'source' => $visit->source,
            'decisions' => $visit->decisions,
            'membership_interest' => $visit->membership_interest,
            'consent_data_processing' => $visit->consent_data_processing,
            'consent_follow_up' => $visit->consent_follow_up,
            'created_at' => $visit->created_at?->toIso8601String(),
            'has_restricted_content' => $visit->prayer_needs !== null || $visit->salvation_response !== null,
        ];

        if ($includeRestricted && $this->canViewRestricted($actor, $visit->branch_id)) {
            $data['prayer_needs'] = $visit->prayer_needs;
            $data['salvation_response'] = $visit->salvation_response;
        }

        return $data;
    }

    /**
     * @return Collection<int, array{type: string, record: Visitor|Member, confidence: string, reason: string}>
     */
    private function findPotentialDuplicates(array $data): Collection
    {
        $matches = collect();

        foreach (config('visitors.duplicate_rules', []) as $rule) {
            if (isset($rule['field'])) {
                $this->matchVisitorsByField($matches, $data, $rule['field'], $rule['confidence']);
            } elseif (isset($rule['fields'])) {
                $this->matchVisitorsByFields($matches, $data, $rule['fields'], $rule['confidence']);
            }
        }

        foreach ($this->memberDuplicates->findMatchesForPayload($data) as $match) {
            $matches->push([
                'type' => 'member',
                'record' => $match['member'],
                'confidence' => $match['confidence'],
                'reason' => $match['reason'],
            ]);
        }

        return $matches->unique(fn (array $row) => $row['type'] . ':' . $row['record']->id)->values();
    }

    /**
     * @param  Collection<int, array{type: string, record: object, confidence: string, reason: string}>  $matches
     */
    private function matchVisitorsByField(Collection $matches, array $data, string $field, string $confidence): void
    {
        $value = $data[$field] ?? null;
        if ($value === null || $value === '') {
            return;
        }

        if ($field === 'phone') {
            $normalized = $this->normalizePhone((string) $value);
            $candidates = Visitor::query()->whereNotNull('phone')->get()
                ->filter(fn (Visitor $v) => $this->normalizePhone((string) $v->phone) === $normalized);

            foreach ($candidates as $visitor) {
                $matches->push(['type' => 'visitor', 'record' => $visitor, 'confidence' => $confidence, 'reason' => $field]);
            }

            return;
        }

        foreach (Visitor::query()->where($field, $value)->get() as $visitor) {
            $matches->push(['type' => 'visitor', 'record' => $visitor, 'confidence' => $confidence, 'reason' => $field]);
        }
    }

    /**
     * @param  string[]  $fields
     * @param  Collection<int, array{type: string, record: object, confidence: string, reason: string}>  $matches
     */
    private function matchVisitorsByFields(Collection $matches, array $data, array $fields, string $confidence): void
    {
        foreach ($fields as $field) {
            if (empty($data[$field])) {
                return;
            }
        }

        $query = Visitor::query();
        foreach ($fields as $field) {
            $query->where($field, $data[$field]);
        }

        $reason = implode('_and_', $fields);
        foreach ($query->get() as $visitor) {
            $matches->push(['type' => 'visitor', 'record' => $visitor, 'confidence' => $confidence, 'reason' => $reason]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validateCapture(array $payload): array
    {
        $validator = validator($payload, [
            'branch_id' => ['required', 'integer', 'exists:organizations,id'],
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:32'],
            'date_of_birth' => ['nullable', 'date'],
            'inviter_name' => ['nullable', 'string', 'max:120'],
            'inviter_member_id' => ['nullable', 'integer', 'exists:members,id'],
            'visit_date' => ['required', 'date'],
            'service_or_event' => ['nullable', 'string', 'max:255'],
            'attendance_status' => ['nullable', 'string', 'in:' . implode(',', config('visitors.attendance_statuses', []))],
            'source' => ['required', 'string', 'in:' . implode(',', config('visitors.sources', []))],
            'decisions' => ['nullable', 'array'],
            'decisions.*' => ['string', 'in:' . implode(',', config('visitors.decision_types', []))],
            'salvation_response' => ['nullable', 'string', 'max:5000'],
            'prayer_needs' => ['nullable', 'string', 'max:5000'],
            'membership_interest' => ['boolean'],
            'consent_data_processing' => ['required', 'accepted'],
            'consent_follow_up' => ['boolean'],
            'link_visitor_id' => ['nullable', 'integer', 'exists:visitors,id'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }

    /**
     * @return array<string, mixed>
     */
    private function validateVisit(array $payload): array
    {
        $validator = validator($payload, [
            'branch_id' => ['sometimes', 'integer', 'exists:organizations,id'],
            'visit_date' => ['required', 'date'],
            'service_or_event' => ['nullable', 'string', 'max:255'],
            'attendance_status' => ['nullable', 'string', 'in:' . implode(',', config('visitors.attendance_statuses', []))],
            'source' => ['required', 'string', 'in:' . implode(',', config('visitors.sources', []))],
            'decisions' => ['nullable', 'array'],
            'decisions.*' => ['string', 'in:' . implode(',', config('visitors.decision_types', []))],
            'salvation_response' => ['nullable', 'string', 'max:5000'],
            'prayer_needs' => ['nullable', 'string', 'max:5000'],
            'membership_interest' => ['boolean'],
            'consent_data_processing' => ['required', 'accepted'],
            'consent_follow_up' => ['boolean'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }

    private function canViewRestricted(User $actor, ?int $branchId): bool
    {
        return $this->authorization->allows($actor, 'visitors.sensitive', $branchId);
    }

    private function assertCan(User $actor, string $action, ?int $branchId = null): void
    {
        if (! $this->authorization->allows($actor, $action, $branchId)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function assertVisitorInScope(User $actor, Visitor $visitor): void
    {
        if ($actor->isChurchWide()) {
            return;
        }

        try {
            BranchScope::for($actor)->assertIncludes($visitor->branch_id);
        } catch (BranchScopeException) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    /** @param  Builder<Visitor>  $query */
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

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    private function csv(?string $value): string
    {
        return '"' . str_replace('"', '""', (string) ($value ?? '')) . '"';
    }
}
