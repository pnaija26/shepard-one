<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\ServiceTeam;
use App\Models\TeamReport;
use App\Models\TeamReportForm;
use App\Models\TeamReportFormAssignment;
use App\Models\TeamReportFormVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Story 5.7: configurable team report forms with versioned publication.
 */
class TeamReportFormService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
    ) {
    }

    /**
     * @return Collection<int, TeamReportForm>
     */
    public function listForms(User $actor): Collection
    {
        $this->assertCan($actor, 'teams.report_forms.read');

        $query = TeamReportForm::query()->with('branch:id,name')->orderBy('name');
        $this->applyBranchScope($query, $actor);

        return $query->limit(100)->get();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createForm(User $actor, array $payload): TeamReportForm
    {
        $this->assertCan($actor, 'teams.report_forms.manage');

        $validated = validator($payload, [
            'name' => ['required', 'string', 'max:160'],
            'branch_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'fields' => ['required', 'array', 'min:1'],
        ])->validate();

        $this->assertFieldsValid($validated['fields']);

        return DB::transaction(function () use ($actor, $validated): TeamReportForm {
            $form = TeamReportForm::create([
                'name' => $validated['name'],
                'branch_id' => $validated['branch_id'] ?? null,
                'status' => TeamReportForm::STATUS_DRAFT,
                'current_version' => 0,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            TeamReportFormVersion::create([
                'team_report_form_id' => $form->id,
                'version' => 1,
                'fields' => $this->normalizeFields($validated['fields']),
                'status' => TeamReportFormVersion::STATUS_DRAFT,
                'created_by' => $actor->id,
            ]);

            $this->recordChange($actor, $form, 'created', ['version' => 1]);
            $this->audit($actor, 'team_report_form.created', $form);

            return $form->fresh(['versions']);
        });
    }

    public function showForm(User $actor, TeamReportForm $form): TeamReportForm
    {
        $this->assertCan($actor, 'teams.report_forms.read');
        $this->assertFormInScope($actor, $form);

        return $form->load([
            'branch:id,name',
            'versions' => fn ($q) => $q->orderByDesc('version'),
            'assignments.team:id,name',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateDraft(User $actor, TeamReportForm $form, array $payload): TeamReportForm
    {
        $this->assertCan($actor, 'teams.report_forms.manage');
        $this->assertFormInScope($actor, $form);

        $validated = validator($payload, [
            'fields' => ['required', 'array', 'min:1'],
            'allow_incompatible_changes' => ['nullable', 'boolean'],
            'migration_notes' => ['nullable', 'string', 'max:500'],
        ])->validate();

        $this->assertFieldsValid($validated['fields']);
        $draft = $this->draftVersion($form);
        $normalized = $this->normalizeFields($validated['fields']);

        if ($form->current_version > 0) {
            $published = $this->publishedVersion($form, $form->current_version);
            $incompatibilities = $this->detectIncompatibleChanges($published?->fields ?? [], $normalized);

            if ($incompatibilities !== [] && ! ($validated['allow_incompatible_changes'] ?? false)) {
                throw new TeamReportFormException(
                    'Incompatible form changes require explicit migration approval or a new field key.',
                    'incompatible_changes',
                    422,
                    $incompatibilities,
                );
            }
        }

        return DB::transaction(function () use ($actor, $form, $draft, $normalized, $validated): TeamReportForm {
            if ($draft->status === TeamReportFormVersion::STATUS_PUBLISHED) {
                $draft = TeamReportFormVersion::create([
                    'team_report_form_id' => $form->id,
                    'version' => $form->current_version + 1,
                    'fields' => $normalized,
                    'status' => TeamReportFormVersion::STATUS_DRAFT,
                    'created_by' => $actor->id,
                ]);
            } else {
                $draft->update(['fields' => $normalized]);
            }

            $form->update(['updated_by' => $actor->id]);

            $this->recordChange($actor, $form, 'draft_updated', [
                'version' => $draft->version,
                'migration_notes' => $validated['migration_notes'] ?? null,
            ]);

            return $form->fresh(['versions']);
        });
    }

    public function previewForm(User $actor, TeamReportForm $form): array
    {
        $this->assertCan($actor, 'teams.report_forms.read');
        $this->assertFormInScope($actor, $form);

        $draft = $this->draftVersion($form);

        return [
            'form_id' => $form->id,
            'version' => $draft->version,
            'status' => $draft->status,
            'fields' => $draft->fields ?? [],
            'validation_preview' => $this->validationPreview($draft->fields ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function publishForm(User $actor, TeamReportForm $form, array $payload): TeamReportForm
    {
        $this->assertCan($actor, 'teams.report_forms.manage');
        $this->assertFormInScope($actor, $form);

        $validated = validator($payload, [
            'team_ids' => ['required', 'array', 'min:1'],
            'team_ids.*' => ['integer', 'exists:service_teams,id'],
        ])->validate();

        $draft = $this->draftVersion($form);
        $this->assertFieldsValid($draft->fields ?? []);

        return DB::transaction(function () use ($actor, $form, $draft, $validated): TeamReportForm {
            $draft->update([
                'status' => TeamReportFormVersion::STATUS_PUBLISHED,
                'published_at' => now(),
                'published_by' => $actor->id,
            ]);

            $form->update([
                'status' => TeamReportForm::STATUS_PUBLISHED,
                'current_version' => $draft->version,
                'updated_by' => $actor->id,
            ]);

            foreach ($validated['team_ids'] as $teamId) {
                $team = ServiceTeam::query()->findOrFail($teamId);
                $this->assertTeamAssignable($actor, $team, $form);

                TeamReportFormAssignment::query()->updateOrCreate(
                    ['service_team_id' => $teamId],
                    [
                        'team_report_form_id' => $form->id,
                        'form_version' => $draft->version,
                        'assigned_by' => $actor->id,
                        'assigned_at' => now(),
                    ],
                );
            }

            $this->recordChange($actor, $form, 'published', [
                'version' => $draft->version,
                'team_ids' => $validated['team_ids'],
            ]);
            $this->audit($actor, 'team_report_form.published', $form, ['version' => $draft->version]);

            return $form->fresh(['versions', 'assignments.team:id,name']);
        });
    }

    public function activeFormForTeam(ServiceTeam $team): ?TeamReportFormVersion
    {
        $assignment = TeamReportFormAssignment::query()
            ->where('service_team_id', $team->id)
            ->first();

        if ($assignment === null) {
            return null;
        }

        return TeamReportFormVersion::query()
            ->where('team_report_form_id', $assignment->team_report_form_id)
            ->where('version', $assignment->form_version)
            ->where('status', TeamReportFormVersion::STATUS_PUBLISHED)
            ->first();
    }

    public function activeFormForTeamScoped(User $actor, ServiceTeam $team): ?TeamReportFormVersion
    {
        $this->assertCan($actor, 'teams.report_forms.read');

        if (! $actor->isChurchWide()) {
            try {
                BranchScope::for($actor)->assertIncludes((int) $team->branch_id);
            } catch (BranchScopeException) {
                throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
            }
        }

        return $this->activeFormForTeam($team);
    }

    /**
     * @param  array<string, mixed>  $fieldValues
     */
    public function validateFieldValues(array $fields, array $fieldValues, bool $requireAll = false): void
    {
        foreach ($fields as $field) {
            $key = $field['key'] ?? null;
            if ($key === null) {
                continue;
            }

            $value = $fieldValues[$key] ?? null;
            $required = (bool) ($field['required'] ?? false);

            if ($requireAll && $required && ($value === null || $value === '')) {
                throw ValidationException::withMessages([
                    "field_values.{$key}" => ["The {$field['label']} field is required."],
                ]);
            }

            if ($value === null || $value === '') {
                continue;
            }

            $this->validateFieldValue($field, $value);
        }
    }

    public function formatForm(TeamReportForm $form): array
    {
        $draft = $form->relationLoaded('versions')
            ? $form->versions->sortByDesc('version')->first()
            : $this->draftVersion($form);

        return [
            'id' => $form->id,
            'name' => $form->name,
            'branch_id' => $form->branch_id,
            'status' => $form->status,
            'current_version' => $form->current_version,
            'draft_version' => $draft?->version,
            'fields' => $draft?->fields ?? [],
            'assignments' => $form->relationLoaded('assignments')
                ? $form->assignments->map(fn (TeamReportFormAssignment $assignment) => [
                    'service_team_id' => $assignment->service_team_id,
                    'form_version' => $assignment->form_version,
                    'team_name' => $assignment->team?->name,
                ])->values()->all()
                : [],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $fields
     */
    private function assertFieldsValid(array $fields): void
    {
        $keys = [];
        foreach ($fields as $index => $field) {
            if (! is_array($field)) {
                throw ValidationException::withMessages(["fields.{$index}" => ['Each field must be an object.']]);
            }

            validator($field, [
                'key' => ['required', 'string', 'max:80', 'regex:/^[a-z][a-z0-9_]*$/'],
                'label' => ['required', 'string', 'max:160'],
                'type' => ['required', 'string', 'in:' . implode(',', config('team_report_forms.field_types', []))],
                'required' => ['nullable', 'boolean'],
                'help_text' => ['nullable', 'string', 'max:500'],
                'options' => ['nullable', 'array'],
                'options.*' => ['string', 'max:120'],
                'constraints' => ['nullable', 'array'],
            ])->validate();

            if (in_array($field['key'], $keys, true)) {
                throw ValidationException::withMessages(["fields.{$index}.key" => ['Field keys must be unique.']]);
            }

            $keys[] = $field['key'];

            if (($field['type'] ?? '') === 'dropdown' && empty($field['options'])) {
                throw ValidationException::withMessages(["fields.{$index}.options" => ['Dropdown fields require options.']]);
            }

            if (in_array($field['type'] ?? '', ['attachment', 'image'], true)) {
                $allowed = $field['constraints']['allowed_mime_types'] ?? config('team_report_forms.attachment_constraints.allowed_mime_types', []);
                if ($allowed === []) {
                    throw ValidationException::withMessages(["fields.{$index}.constraints" => ['Attachment fields require allowed MIME types.']]);
                }
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    private function normalizeFields(array $fields): array
    {
        return array_values(array_map(fn (array $field) => [
            'key' => $field['key'],
            'label' => $field['label'],
            'type' => $field['type'],
            'required' => (bool) ($field['required'] ?? false),
            'help_text' => $field['help_text'] ?? null,
            'options' => $field['options'] ?? [],
            'constraints' => $field['constraints'] ?? [],
        ], $fields));
    }

    /**
     * @param  array<int, array<string, mixed>>  $before
     * @param  array<int, array<string, mixed>>  $after
     * @return array<int, array<string, mixed>>
     */
    private function detectIncompatibleChanges(array $before, array $after): array
    {
        $beforeByKey = collect($before)->keyBy('key');
        $afterByKey = collect($after)->keyBy('key');
        $issues = [];

        foreach ($beforeByKey as $key => $field) {
            if (! $afterByKey->has($key)) {
                $issues[] = ['key' => $key, 'reason' => 'field_removed'];

                continue;
            }

            $next = $afterByKey->get($key);
            if (($field['type'] ?? null) !== ($next['type'] ?? null)) {
                $issues[] = ['key' => $key, 'reason' => 'type_changed'];
            }

            if (($field['required'] ?? false) === true && ($next['required'] ?? false) === false) {
                $issues[] = ['key' => $key, 'reason' => 'required_removed'];
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function validateFieldValue(array $field, mixed $value): void
    {
        $type = $field['type'] ?? 'text';

        match ($type) {
            'number', 'percentage', 'rating' => validator(['value' => $value], ['value' => ['numeric']])->validate(),
            'date' => validator(['value' => $value], ['value' => ['date']])->validate(),
            'checkbox' => validator(['value' => $value], ['value' => ['boolean']])->validate(),
            'dropdown' => $this->assertInOptions($field, (string) $value),
            'attachment', 'image' => $this->assertAttachmentAllowed($field, is_array($value) ? $value : ['reference' => $value]),
            default => validator(['value' => $value], ['value' => ['string', 'max:5000']])->validate(),
        };

        if ($type === 'rating') {
            $min = (int) config('team_report_forms.rating.min', 1);
            $max = (int) config('team_report_forms.rating.max', 5);
            if ((float) $value < $min || (float) $value > $max) {
                throw ValidationException::withMessages(['value' => ["Rating must be between {$min} and {$max}."]]);
            }
        }

        if ($type === 'percentage' && ((float) $value < 0 || (float) $value > 100)) {
            throw ValidationException::withMessages(['value' => ['Percentage must be between 0 and 100.']]);
        }
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function assertInOptions(array $field, string $value): void
    {
        if (! in_array($value, $field['options'] ?? [], true)) {
            throw ValidationException::withMessages(['value' => ['Selected value is not permitted.']]);
        }
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  array<string, mixed>  $field
     */
    private function assertAttachmentAllowed(array $field, array $value): void
    {
        $mime = $value['mime_type'] ?? null;
        $allowed = $field['constraints']['allowed_mime_types']
            ?? config('team_report_forms.attachment_constraints.allowed_mime_types', []);

        if ($mime !== null && ! in_array($mime, $allowed, true)) {
            throw ValidationException::withMessages(['value' => ['Attachment type is not permitted for this field.']]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    private function validationPreview(array $fields): array
    {
        return array_map(fn (array $field) => [
            'key' => $field['key'],
            'label' => $field['label'],
            'required' => $field['required'] ?? false,
            'type' => $field['type'],
            'help_text' => $field['help_text'] ?? null,
        ], $fields);
    }

    private function draftVersion(TeamReportForm $form): TeamReportFormVersion
    {
        $draft = TeamReportFormVersion::query()
            ->where('team_report_form_id', $form->id)
            ->where('status', TeamReportFormVersion::STATUS_DRAFT)
            ->orderByDesc('version')
            ->first();

        if ($draft !== null) {
            return $draft;
        }

        return TeamReportFormVersion::query()
            ->where('team_report_form_id', $form->id)
            ->orderByDesc('version')
            ->firstOrFail();
    }

    private function publishedVersion(TeamReportForm $form, int $version): ?TeamReportFormVersion
    {
        return TeamReportFormVersion::query()
            ->where('team_report_form_id', $form->id)
            ->where('version', $version)
            ->where('status', TeamReportFormVersion::STATUS_PUBLISHED)
            ->first();
    }

    private function recordChange(User $actor, TeamReportForm $form, string $changeType, array $metadata = []): void
    {
        DB::table('team_report_form_changes')->insert([
            'team_report_form_id' => $form->id,
            'version' => $metadata['version'] ?? $form->current_version,
            'change_type' => $changeType,
            'metadata' => $metadata === [] ? null : json_encode($metadata),
            'actor_id' => $actor->id,
            'created_at' => now(),
        ]);
    }

    private function assertTeamAssignable(User $actor, ServiceTeam $team, TeamReportForm $form): void
    {
        if ($form->branch_id !== null && (int) $team->branch_id !== (int) $form->branch_id) {
            throw ValidationException::withMessages(['team_ids' => ['Assigned teams must match the form branch scope.']]);
        }

        if (! $actor->isChurchWide()) {
            try {
                BranchScope::for($actor)->assertIncludes((int) $team->branch_id);
            } catch (BranchScopeException) {
                throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
            }
        }
    }

    private function assertCan(User $actor, string $action): void
    {
        if (! $this->authorization->allows($actor, $action)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function assertFormInScope(User $actor, TeamReportForm $form): void
    {
        if ($form->branch_id === null || $actor->isChurchWide()) {
            return;
        }

        try {
            BranchScope::for($actor)->assertIncludes((int) $form->branch_id);
        } catch (BranchScopeException) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function applyBranchScope(Builder $query, User $actor): void
    {
        if ($actor->isChurchWide()) {
            return;
        }

        try {
            $scope = BranchScope::for($actor);
            $query->where(function (Builder $inner) use ($scope): void {
                $inner->whereNull('branch_id')->orWhereIn('branch_id', $scope->branchIds());
            });
        } catch (BranchScopeException) {
            $query->whereRaw('1 = 0');
        }
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function audit(User $actor, string $action, TeamReportForm $form, ?array $metadata = null): void
    {
        $this->audit->record(
            actor: $actor,
            action: $action,
            category: AuditEvent::CATEGORY_BUSINESS,
            module: 'teams',
            branchId: $form->branch_id,
            subjectType: TeamReportForm::class,
            subjectId: $form->id,
            before: null,
            after: array_filter([
                'name' => $form->name,
                'status' => $form->status,
                'current_version' => $form->current_version,
                'metadata' => $metadata,
            ]),
        );
    }
}
