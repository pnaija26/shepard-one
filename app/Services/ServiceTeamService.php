<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Member;
use App\Models\MemberNotification;
use App\Models\Organization;
use App\Models\ServiceTeam;
use App\Models\ServiceTeamChange;
use App\Models\ServiceTeamConfigVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Story 5.1: create and configure service teams with versioned operating rules.
 */
class ServiceTeamService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
    ) {
    }

    /**
     * @return Collection<int, ServiceTeam>
     */
    public function listTeams(User $actor, array $filters = []): Collection
    {
        $this->assertCan($actor, 'teams.read');

        $query = ServiceTeam::query()
            ->with(['branch:id,name', 'department:id,name'])
            ->orderBy('name');

        if (! empty($filters['branch_id'])) {
            $query->where('branch_id', $filters['branch_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $this->applyBranchScope($query, $actor);

        return $query->limit(200)->get();
    }

    public function showTeam(User $actor, ServiceTeam $team): ServiceTeam
    {
        $this->assertCan($actor, 'teams.read');
        $this->assertTeamInScope($actor, $team);

        return $team->load([
            'branch:id,name',
            'department:id,name',
            'configVersions' => fn ($q) => $q->orderByDesc('version')->limit(10),
            'changes' => fn ($q) => $q->orderByDesc('created_at')->limit(20),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createTeam(User $actor, array $payload): ServiceTeam
    {
        $this->assertCan($actor, 'teams.manage');

        $validated = $this->validatePayload($payload);
        $this->assertBranchWritable($actor, (int) $validated['branch_id']);
        $this->assertDepartmentInBranch($validated);
        $this->assertNoDuplicateName((int) $validated['branch_id'], $validated['name']);
        $this->assertConfigurationConsistent($validated);

        return DB::transaction(function () use ($actor, $validated): ServiceTeam {
            $team = ServiceTeam::create([
                ...$this->mapAttributes($validated),
                'status' => ServiceTeam::STATUS_DRAFT,
                'current_config_version' => 1,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->recordConfigVersion($actor, $team, 1);
            $this->recordChange($actor, $team, 'created', null, $this->snapshot($team), 'Service team created', 1);
            $this->audit($actor, 'service_team.created', $team);

            return $team->fresh(['branch:id,name', 'department:id,name']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateTeam(User $actor, ServiceTeam $team, array $payload): ServiceTeam
    {
        $this->assertCan($actor, 'teams.manage');
        $this->assertTeamInScope($actor, $team);

        if ($team->status === ServiceTeam::STATUS_ARCHIVED) {
            throw ValidationException::withMessages(['team' => ['Archived teams cannot be edited.']]);
        }

        $validated = $this->validatePayload($payload, $team->id);
        $this->assertBranchWritable($actor, (int) $validated['branch_id']);
        $this->assertDepartmentInBranch($validated);
        $this->assertNoDuplicateName((int) $validated['branch_id'], $validated['name'], $team->id);
        $this->assertConfigurationConsistent($validated);

        return DB::transaction(function () use ($actor, $team, $validated): ServiceTeam {
            $before = $this->snapshot($team);
            $materialChanges = $this->detectMaterialChanges($before, $validated);

            $team->update([
                ...$this->mapAttributes($validated),
                'updated_by' => $actor->id,
            ]);

            $team = $team->fresh();
            $newVersion = $team->status === ServiceTeam::STATUS_ACTIVE
                ? $team->current_config_version + 1
                : $team->current_config_version;

            if ($team->status === ServiceTeam::STATUS_ACTIVE) {
                $team->update(['current_config_version' => $newVersion]);
                $team = $team->fresh();
                $this->recordConfigVersion($actor, $team, $newVersion);
            }

            $this->recordChange($actor, $team, 'updated', $before, $this->snapshot($team), 'Service team updated', $newVersion);
            $this->audit($actor, 'service_team.updated', $team, $before);

            if ($materialChanges !== []) {
                $this->notifyLeaders($team, $materialChanges);
            }

            return $team->load(['branch:id,name', 'department:id,name']);
        });
    }

    public function activateTeam(User $actor, ServiceTeam $team): ServiceTeam
    {
        $this->assertCan($actor, 'teams.manage');
        $this->assertTeamInScope($actor, $team);

        if ($team->status === ServiceTeam::STATUS_ACTIVE) {
            return $team;
        }

        if ($team->status === ServiceTeam::STATUS_ARCHIVED) {
            throw ValidationException::withMessages(['status' => ['Archived teams cannot be reactivated.']]);
        }

        return DB::transaction(function () use ($actor, $team): ServiceTeam {
            $before = $this->snapshot($team);

            $team->update([
                'status' => ServiceTeam::STATUS_ACTIVE,
                'updated_by' => $actor->id,
            ]);

            $team = $team->fresh();
            $this->recordChange($actor, $team, 'activated', $before, $this->snapshot($team), 'Service team activated', $team->current_config_version);
            $this->audit($actor, 'service_team.activated', $team, $before);

            return $team->load(['branch:id,name', 'department:id,name']);
        });
    }

    public function archiveTeam(User $actor, ServiceTeam $team, ?string $reason = null): ServiceTeam
    {
        $this->assertCan($actor, 'teams.manage');
        $this->assertTeamInScope($actor, $team);

        if ($team->status === ServiceTeam::STATUS_ARCHIVED) {
            return $team;
        }

        return DB::transaction(function () use ($actor, $team, $reason): ServiceTeam {
            $before = $this->snapshot($team);

            $team->update([
                'status' => ServiceTeam::STATUS_ARCHIVED,
                'archived_at' => now(),
                'updated_by' => $actor->id,
            ]);

            $team = $team->fresh();
            $this->recordChange($actor, $team, 'archived', $before, $this->snapshot($team), $reason ?? 'Service team archived', $team->current_config_version);
            $this->audit($actor, 'service_team.archived', $team, $before);
            $this->notifyLeaders($team, ['status']);

            return $team->load(['branch:id,name', 'department:id,name']);
        });
    }

    public function formatTeam(ServiceTeam $team): array
    {
        return [
            'id' => $team->id,
            'branch_id' => $team->branch_id,
            'department_id' => $team->department_id,
            'name' => $team->name,
            'category' => $team->category,
            'category_label' => config('service_teams.categories.' . $team->category, $team->category),
            'description' => $team->description,
            'leaders' => $team->leaders ?? [],
            'required_skills' => $team->required_skills ?? [],
            'minimum_staffing' => $team->minimum_staffing ?? [],
            'schedules' => $team->schedules ?? [],
            'objectives' => $team->objectives ?? [],
            'attendance_rules' => $team->attendance_rules ?? [],
            'reporting_template' => $team->reporting_template ?? [],
            'approval_hierarchy' => $team->approval_hierarchy ?? [],
            'status' => $team->status,
            'current_config_version' => $team->current_config_version,
            'archived_at' => $team->archived_at?->toIso8601String(),
            'branch' => $team->relationLoaded('branch') ? $team->branch : null,
            'department' => $team->relationLoaded('department') ? $team->department : null,
            'config_versions' => $team->relationLoaded('configVersions')
                ? $team->configVersions->map(fn (ServiceTeamConfigVersion $version) => [
                    'version' => $version->version,
                    'effective_from' => $version->effective_from?->toIso8601String(),
                ])->values()->all()
                : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validatePayload(array $payload, ?int $ignoreTeamId = null): array
    {
        return validator($payload, [
            'branch_id' => ['required', 'integer', 'exists:organizations,id'],
            'department_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'in:' . implode(',', array_keys(config('service_teams.categories', [])))],
            'description' => ['nullable', 'string', 'max:5000'],
            'leaders' => ['required', 'array', 'min:1'],
            'leaders.*.user_id' => ['required', 'integer', 'exists:users,id'],
            'leaders.*.role' => ['required', 'string', 'in:' . implode(',', config('service_teams.leader_roles', []))],
            'required_skills' => ['nullable', 'array'],
            'required_skills.*' => ['string', 'max:120'],
            'minimum_staffing' => ['required', 'array'],
            'minimum_staffing.minimum_per_session' => ['required', 'integer', 'min:1'],
            'minimum_staffing.maximum_per_session' => ['nullable', 'integer', 'min:1'],
            'schedules' => ['required', 'array', 'min:1'],
            'schedules.*.type' => ['required', 'string', 'in:' . implode(',', config('service_teams.schedule_types', []))],
            'schedules.*.label' => ['required', 'string', 'max:120'],
            'schedules.*.required_volunteers' => ['required', 'integer', 'min:1'],
            'objectives' => ['nullable', 'array'],
            'objectives.*' => ['string', 'max:500'],
            'attendance_rules' => ['required', 'array'],
            'attendance_rules.require_check_in' => ['required', 'boolean'],
            'attendance_rules.methods' => ['nullable', 'array'],
            'attendance_rules.methods.*' => ['string', 'in:manual,qr,mobile'],
            'reporting_template' => ['required', 'array'],
            'reporting_template.frequency' => ['required', 'string', 'in:weekly,monthly,per_event'],
            'reporting_template.fields' => ['required', 'array', 'min:1'],
            'reporting_template.fields.*' => ['string', 'max:120'],
            'approval_hierarchy' => ['required', 'array'],
            'approval_hierarchy.requires_approval' => ['required', 'boolean'],
            'approval_hierarchy.levels' => ['nullable', 'array'],
            'approval_hierarchy.levels.*.user_id' => ['required_with:approval_hierarchy.levels', 'integer', 'exists:users,id'],
            'approval_hierarchy.levels.*.role' => ['required_with:approval_hierarchy.levels', 'string', 'max:64'],
        ])->validate();
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function assertConfigurationConsistent(array $validated): void
    {
        $min = (int) ($validated['minimum_staffing']['minimum_per_session'] ?? 0);
        $max = (int) ($validated['minimum_staffing']['maximum_per_session'] ?? 0);

        if ($max > 0 && $min > $max) {
            throw ValidationException::withMessages([
                'minimum_staffing.minimum_per_session' => ['Minimum staffing cannot exceed maximum staffing.'],
            ]);
        }

        foreach ($validated['schedules'] as $index => $schedule) {
            if ((int) $schedule['required_volunteers'] < $min) {
                throw ValidationException::withMessages([
                    "schedules.{$index}.required_volunteers" => ['Schedule staffing cannot be below team minimum staffing.'],
                ]);
            }
        }

        $attendance = $validated['attendance_rules'];
        if (($attendance['require_check_in'] ?? false) && empty($attendance['methods'])) {
            throw ValidationException::withMessages([
                'attendance_rules.methods' => ['At least one attendance method is required when check-in is mandatory.'],
            ]);
        }

        $approval = $validated['approval_hierarchy'];
        if (($approval['requires_approval'] ?? false) && empty($approval['levels'])) {
            throw ValidationException::withMessages([
                'approval_hierarchy.levels' => ['Approval hierarchy levels are required when approval is enabled.'],
            ]);
        }

        $leaderIds = collect($validated['leaders'])->pluck('user_id')->unique();
        if ($leaderIds->count() !== count($validated['leaders'])) {
            throw ValidationException::withMessages(['leaders' => ['Duplicate leaders are not allowed.']]);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function assertDepartmentInBranch(array $validated): void
    {
        if (empty($validated['department_id'])) {
            return;
        }

        $department = Organization::query()->find($validated['department_id']);
        if ($department === null) {
            return;
        }

        if (! in_array($department->type, ['department', 'ministry', 'team'], true)) {
            throw ValidationException::withMessages(['department_id' => ['Department must be a valid organizational unit.']]);
        }

        if ((int) $department->parent_id !== (int) $validated['branch_id']
            && ! $this->departmentUnderBranch($department, (int) $validated['branch_id'])) {
            throw ValidationException::withMessages(['department_id' => ['Department must belong to the selected branch scope.']]);
        }
    }

    private function departmentUnderBranch(Organization $department, int $branchId): bool
    {
        $current = $department;

        while ($current !== null) {
            if ((int) $current->id === $branchId) {
                return true;
            }

            $current = $current->parent_id ? Organization::query()->find($current->parent_id) : null;
        }

        return false;
    }

    private function assertNoDuplicateName(int $branchId, string $name, ?int $ignoreTeamId = null): void
    {
        $query = ServiceTeam::query()
            ->where('branch_id', $branchId)
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)]);

        if ($ignoreTeamId !== null) {
            $query->where('id', '!=', $ignoreTeamId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages(['name' => ['A team with this name already exists in the branch.']]);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function mapAttributes(array $validated): array
    {
        return [
            'branch_id' => $validated['branch_id'],
            'department_id' => $validated['department_id'] ?? null,
            'name' => $validated['name'],
            'category' => $validated['category'],
            'description' => $validated['description'] ?? null,
            'leaders' => $validated['leaders'],
            'required_skills' => $validated['required_skills'] ?? [],
            'minimum_staffing' => $validated['minimum_staffing'],
            'schedules' => $validated['schedules'],
            'objectives' => $validated['objectives'] ?? [],
            'attendance_rules' => $validated['attendance_rules'],
            'reporting_template' => $validated['reporting_template'],
            'approval_hierarchy' => $validated['approval_hierarchy'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(ServiceTeam $team): array
    {
        return [
            'name' => $team->name,
            'category' => $team->category,
            'status' => $team->status,
            'leaders' => $team->leaders,
            'minimum_staffing' => $team->minimum_staffing,
            'schedules' => $team->schedules,
            'attendance_rules' => $team->attendance_rules,
            'reporting_template' => $team->reporting_template,
            'approval_hierarchy' => $team->approval_hierarchy,
            'current_config_version' => $team->current_config_version,
        ];
    }

    private function recordConfigVersion(User $actor, ServiceTeam $team, int $version): void
    {
        ServiceTeamConfigVersion::create([
            'service_team_id' => $team->id,
            'version' => $version,
            'config' => $this->snapshot($team),
            'effective_from' => now(),
            'created_by' => $actor->id,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>  $after
     */
    private function recordChange(
        User $actor,
        ServiceTeam $team,
        string $changeType,
        ?array $before,
        array $after,
        string $summary,
        int $configVersion,
    ): void {
        ServiceTeamChange::create([
            'service_team_id' => $team->id,
            'change_type' => $changeType,
            'config_version' => $configVersion,
            'before_state' => $before,
            'after_state' => $after,
            'summary' => $summary,
            'changed_by' => $actor->id,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $validated
     * @return string[]
     */
    private function detectMaterialChanges(array $before, array $validated): array
    {
        $changed = [];

        foreach (config('service_teams.material_change_fields', []) as $field) {
            $beforeValue = json_encode($before[$field] ?? null);
            $afterValue = json_encode($validated[$field] ?? null);

            if ($beforeValue !== $afterValue) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    /**
     * @param  string[]  $fields
     */
    private function notifyLeaders(ServiceTeam $team, array $fields): void
    {
        foreach ($team->leaders ?? [] as $leader) {
            $userId = $leader['user_id'] ?? null;
            if ($userId === null) {
                continue;
            }

            $member = Member::query()->where('user_id', $userId)->first();
            if ($member === null) {
                continue;
            }

            MemberNotification::create([
                'member_id' => $member->id,
                'user_id' => $userId,
                'type' => 'service_team.config_changed',
                'message' => 'Service team "' . $team->name . '" configuration was updated.',
                'metadata' => [
                    'service_team_id' => $team->id,
                    'changed_fields' => $fields,
                    'config_version' => $team->current_config_version,
                ],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>|null  $before
     */
    private function audit(User $actor, string $action, ServiceTeam $team, ?array $before = null): void
    {
        $this->audit->record(
            actor: $actor,
            action: $action,
            category: AuditEvent::CATEGORY_BUSINESS,
            module: 'teams',
            branchId: $team->branch_id,
            subjectType: ServiceTeam::class,
            subjectId: $team->id,
            before: $before,
            after: $this->snapshot($team),
        );
    }

    private function assertCan(User $actor, string $action): void
    {
        if (! $this->authorization->allows($actor, $action)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function assertBranchWritable(User $actor, int $branchId): void
    {
        if ($actor->isChurchWide()) {
            return;
        }

        try {
            BranchScope::for($actor)->assertIncludes($branchId);
        } catch (BranchScopeException) {
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

    /** @param  Builder<ServiceTeam>  $query */
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
