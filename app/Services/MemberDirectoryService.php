<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\HouseholdMembership;
use App\Models\Member;
use App\Models\MemberDirectoryConsentEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Story 2.7: privacy-controlled church directory visibility and search.
 */
class MemberDirectoryService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
    ) {
    }

    public function settingsFor(User $user): array
    {
        $member = $this->resolveLinkedMember($user);
        $this->applyDueVisibilityChanges($member);
        $member->refresh();

        $effective = $this->effectiveVisibility($member);
        $pending = $member->directory_visibility_pending;

        return [
            'consent_directory' => (bool) $member->consent_directory,
            'directory_consent_at' => $member->directory_consent_at?->toIso8601String(),
            'propagation_seconds' => config('directory.propagation_seconds', 300),
            'forbidden_fields' => config('directory.forbidden_fields', []),
            'visibility_levels' => config('directory.visibility_levels', []),
            'fields' => $this->formatFieldSettings($effective, $pending),
            'pending_effective_at' => $member->directory_visibility_effective_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateSettings(User $user, array $payload): array
    {
        $member = $this->resolveLinkedMember($user);
        $validated = $this->validateSettingsPayload($payload);

        return DB::transaction(function () use ($user, $member, $validated) {
            $beforeVisibility = $this->effectiveVisibility($member);
            $beforeConsent = (bool) $member->consent_directory;

            $consentDirectory = array_key_exists('consent_directory', $validated)
                ? (bool) $validated['consent_directory']
                : $beforeConsent;

            $incomingVisibility = $validated['visibility'] ?? [];
            $nextVisibility = $this->mergeVisibility($beforeVisibility, $incomingVisibility);

            if (! $consentDirectory) {
                $nextVisibility = $this->allHiddenVisibility();
                $immediate = true;
            } elseif ($incomingVisibility !== []) {
                $immediate = false;
            } else {
                $immediate = true;
            }

            $member->consent_directory = $consentDirectory;
            $member->directory_consent_at = $consentDirectory ? ($member->directory_consent_at ?? now()) : null;

            if ($immediate) {
                $member->directory_visibility = $nextVisibility;
                $member->directory_visibility_pending = null;
                $member->directory_visibility_effective_at = null;
            } else {
                $member->directory_visibility_pending = $nextVisibility;
                $member->directory_visibility_effective_at = now()->addSeconds(
                    config('directory.propagation_seconds', 300),
                );
            }

            $member->updated_by = $user->id;
            $member->save();

            MemberDirectoryConsentEvent::create([
                'member_id' => $member->id,
                'actor_id' => $user->id,
                'consent_directory' => $consentDirectory,
                'visibility_before' => $beforeVisibility,
                'visibility_after' => $nextVisibility,
                'effective_at' => $immediate ? now() : $member->directory_visibility_effective_at,
                'created_at' => now(),
            ]);

            $this->audit->record(
                actor: $user,
                action: 'member.directory_visibility.updated',
                category: AuditEvent::CATEGORY_BUSINESS,
                module: 'members',
                branchId: $member->branch_id,
                subjectType: Member::class,
                subjectId: $member->id,
                before: [
                    'consent_directory' => $beforeConsent,
                    'visibility' => $beforeVisibility,
                ],
                after: [
                    'consent_directory' => $consentDirectory,
                    'visibility' => $nextVisibility,
                    'effective_at' => $member->directory_visibility_effective_at?->toIso8601String(),
                ],
            );

            return $this->settingsFor($user);
        });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function search(User $viewer, array $filters = []): Collection
    {
        $this->assertCan($viewer, 'directory.read');

        $query = $this->baseDirectoryQuery($viewer);

        if (! empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $query->where(function (Builder $q) use ($term): void {
                $q->where('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)
                    ->orWhere('preferred_name', 'like', $term);
            });
        }

        if (! empty($filters['branch_id'])) {
            $query->where('branch_id', (int) $filters['branch_id']);
        }

        return $query
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get()
            ->map(fn (Member $member) => $this->formatDirectoryEntry($viewer, $member))
            ->filter(fn (array $entry) => $entry['visible'])
            ->values();
    }

    public function show(User $viewer, Member $member): array
    {
        $this->assertCan($viewer, 'directory.read');
        $this->assertMemberInScope($viewer, $member);

        if (! $this->isListedInDirectory($member)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('This member is not listed in the directory.');
        }

        return $this->formatDirectoryEntry($viewer, $member);
    }

    /**
     * @return array{filename: string, content: string}
     */
    public function export(User $viewer, array $filters = []): array
    {
        $this->assertCan($viewer, 'directory.export');

        $rows = $this->search($viewer, $filters);
        $fieldKeys = array_keys(config('directory.fields', []));

        $lines = [implode(',', array_merge(['full_name'], $fieldKeys))];

        foreach ($rows as $row) {
            $line = [$this->csvEscape($row['full_name'])];
            foreach ($fieldKeys as $field) {
                $line[] = $this->csvEscape($row['fields'][$field] ?? '');
            }
            $lines[] = implode(',', $line);
        }

        return [
            'filename' => 'directory-export-' . now()->format('Y-m-d-His') . '.csv',
            'content' => implode("\n", $lines),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateSettingsPayload(array $payload): array
    {
        $allowedLevels = array_keys(config('directory.visibility_levels', []));
        $allowedFields = array_keys(config('directory.fields', []));

        $rules = [
            'consent_directory' => ['sometimes', 'boolean'],
            'visibility' => ['sometimes', 'array'],
        ];

        foreach ($allowedFields as $field) {
            $rules["visibility.{$field}"] = ['sometimes', 'string', 'in:' . implode(',', $allowedLevels)];
        }

        $validator = validator($payload, $rules);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();

        foreach (array_keys($validated['visibility'] ?? []) as $field) {
            if (in_array($field, config('directory.forbidden_fields', []), true)) {
                throw ValidationException::withMessages([
                    "visibility.{$field}" => ['This field cannot be published in the directory.'],
                ]);
            }
        }

        return $validated;
    }

    /** @return array<string, string> */
    private function defaultVisibility(): array
    {
        $default = config('directory.default_visibility', 'hidden');
        $settings = [];

        foreach (array_keys(config('directory.fields', [])) as $field) {
            $settings[$field] = $default;
        }

        return $settings;
    }

    /** @return array<string, string> */
    private function allHiddenVisibility(): array
    {
        $settings = [];
        foreach (array_keys(config('directory.fields', [])) as $field) {
            $settings[$field] = 'hidden';
        }

        return $settings;
    }

    /**
     * @param  array<string, string>  $current
     * @param  array<string, string>  $incoming
     * @return array<string, string>
     */
    private function mergeVisibility(array $current, array $incoming): array
    {
        $merged = array_merge($current, $incoming);

        foreach (config('directory.forbidden_fields', []) as $forbidden) {
            unset($merged[$forbidden]);
        }

        $allowedLevels = array_keys(config('directory.visibility_levels', []));
        foreach ($merged as $field => $level) {
            if (! in_array($level, $allowedLevels, true)) {
                $merged[$field] = config('directory.default_visibility', 'hidden');
            }
        }

        return $merged;
    }

    /** @return array<string, string> */
    private function effectiveVisibility(Member $member): array
    {
        $this->applyDueVisibilityChanges($member);

        if (
            $member->directory_visibility_pending !== null
            && $member->directory_visibility_effective_at !== null
            && now()->lt($member->directory_visibility_effective_at)
        ) {
            return $member->directory_visibility ?? $this->defaultVisibility();
        }

        return $member->directory_visibility ?? $this->defaultVisibility();
    }

    private function applyDueVisibilityChanges(Member $member): void
    {
        if (
            $member->directory_visibility_pending === null
            || $member->directory_visibility_effective_at === null
            || now()->lt($member->directory_visibility_effective_at)
        ) {
            return;
        }

        $member->update([
            'directory_visibility' => $member->directory_visibility_pending,
            'directory_visibility_pending' => null,
            'directory_visibility_effective_at' => null,
        ]);
    }

    /** @param  Builder<Member>  $query */
    private function baseDirectoryQuery(User $viewer): Builder
    {
        $query = Member::query()
            ->with('branch:id,name')
            ->where('consent_directory', true)
            ->whereNull('merged_into_id')
            ->whereNull('archived_at')
            ->where('membership_status', 'active');

        $this->applyBranchScope($query, $viewer);

        return $query;
    }

    private function isListedInDirectory(Member $member): bool
    {
        $this->applyDueVisibilityChanges($member);

        return (bool) $member->consent_directory
            && $member->merged_into_id === null
            && $member->archived_at === null
            && $member->membership_status === 'active';
    }

    /** @return array<string, mixed> */
    private function formatDirectoryEntry(User $viewer, Member $member): array
    {
        $visibility = $this->effectiveVisibility($member);
        $fields = [];
        $visible = false;

        foreach (config('directory.fields', []) as $field => $meta) {
            $level = $visibility[$field] ?? config('directory.default_visibility', 'hidden');
            if ($this->viewerCanSeeField($viewer, $member, $level)) {
                $value = $this->fieldValue($member, $field);
                if ($value !== null && $value !== '') {
                    $fields[$field] = $value;
                    $visible = true;
                }
            }
        }

        $showName = $visible || $this->hasAnyVisibleField($visibility);

        return [
            'id' => $member->id,
            'full_name' => $showName ? $member->fullName() : null,
            'visible' => $showName && $member->consent_directory,
            'fields' => $fields,
            'branch_id' => $member->branch_id,
        ];
    }

    private function hasAnyVisibleField(array $visibility): bool
    {
        foreach ($visibility as $level) {
            if ($level !== 'hidden') {
                return true;
            }
        }

        return false;
    }

    private function viewerCanSeeField(User $viewer, Member $member, string $level): bool
    {
        if ($level === 'hidden') {
            return false;
        }

        if ($member->user_id === $viewer->id) {
            return true;
        }

        return match ($level) {
            'congregation' => $this->authorization->allows($viewer, 'directory.read'),
            'staff' => $this->authorization->allows($viewer, 'directory.staff')
                || $this->authorization->allows($viewer, 'members.read', $member->branch_id),
            'household' => $this->shareHousehold($viewer, $member),
            default => false,
        };
    }

    private function shareHousehold(User $viewer, Member $target): bool
    {
        $viewerMember = Member::query()->where('user_id', $viewer->id)->first();
        if ($viewerMember === null) {
            return false;
        }

        $viewerHousehold = HouseholdMembership::activeForMember($viewerMember->id);
        $targetHousehold = HouseholdMembership::activeForMember($target->id);

        return $viewerHousehold !== null
            && $targetHousehold !== null
            && $viewerHousehold->household_id === $targetHousehold->household_id;
    }

    private function fieldValue(Member $member, string $field): mixed
    {
        return match ($field) {
            'branch' => $member->branch?->name,
            'department' => $this->firstSummaryValue($member->ministry_interests),
            'team' => $this->firstSummaryValue($member->skills),
            'group' => $this->firstSummaryValue($member->spiritual_gifts),
            default => $member->{$field},
        };
    }

    private function firstSummaryValue(?array $values): ?string
    {
        if ($values === null || $values === []) {
            return null;
        }

        $first = reset($values);

        return is_string($first) ? $first : null;
    }

    /**
     * @param  array<string, string>  $effective
     * @param  array<string, string>|null  $pending
     * @return array<int, array<string, mixed>>
     */
    private function formatFieldSettings(array $effective, ?array $pending): array
    {
        $fields = [];

        foreach (config('directory.fields', []) as $field => $meta) {
            $forbidden = in_array($field, config('directory.forbidden_fields', []), true);
            $fields[] = [
                'field' => $field,
                'label' => $meta['label'] ?? $field,
                'publishable' => ! $forbidden,
                'visibility' => $effective[$field] ?? config('directory.default_visibility', 'hidden'),
                'pending_visibility' => $pending[$field] ?? null,
            ];
        }

        return $fields;
    }

    private function resolveLinkedMember(User $user): Member
    {
        $member = Member::query()->where('user_id', $user->id)->first();

        if ($member === null) {
            throw new \Illuminate\Auth\Access\AuthorizationException('No member profile is linked to your account.');
        }

        return $member;
    }

    private function assertCan(User $user, string $action): void
    {
        if (! $this->authorization->allows($user, $action)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function assertMemberInScope(User $viewer, Member $member): void
    {
        if ($viewer->isChurchWide()) {
            return;
        }

        try {
            BranchScope::for($viewer)->assertIncludes($member->branch_id);
        } catch (BranchScopeException) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    /** @param  Builder<Member>  $query */
    private function applyBranchScope(Builder $query, User $viewer): void
    {
        if ($viewer->isChurchWide()) {
            return;
        }

        try {
            $scope = BranchScope::for($viewer);
            $query->whereIn('branch_id', $scope->subtreeIds((int) $scope->branchId()));
        } catch (BranchScopeException) {
            $query->whereRaw('1 = 0');
        }
    }

    private function csvEscape(mixed $value): string
    {
        $string = (string) ($value ?? '');

        return '"' . str_replace('"', '""', $string) . '"';
    }
}
