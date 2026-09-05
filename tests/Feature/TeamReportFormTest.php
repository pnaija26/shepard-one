<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\TeamReport;
use App\Models\TeamReportForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 5.7: Build Team-Specific Report Forms.
 */
class TeamReportFormTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'IDX-HQ']);
        $this->branch = Organization::create(['name' => 'Branch A', 'type' => 'branch', 'identifier' => 'IDX-A', 'parent_id' => $hq->id]);
    }

    private function formAdmin(): User
    {
        $user = $this->privilegedUser();
        $role = Role::create(['name' => 'team_form_admin_' . $user->id]);
        foreach (['teams.read', 'teams.manage', 'teams.report_forms.read', 'teams.report_forms.manage', 'teams.reports.read', 'teams.reports.submit'] as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);

        return $user;
    }

    private function createActiveTeam(User $coordinator): int
    {
        $leader = User::factory()->create(['roles' => ['member'], 'has_mfa_enrolled' => true, 'branch_id' => $this->branch->id]);

        $payload = [
            'branch_id' => $this->branch->id,
            'name' => 'Media Team ' . uniqid(),
            'category' => 'media',
            'description' => 'Sunday media team.',
            'leaders' => [['user_id' => $leader->id, 'role' => 'lead']],
            'required_skills' => ['camera'],
            'minimum_staffing' => ['minimum_per_session' => 1, 'maximum_per_session' => 4],
            'schedules' => [['type' => 'weekly', 'label' => 'Sunday service', 'required_volunteers' => 1]],
            'objectives' => ['Capture service.'],
            'attendance_rules' => ['require_check_in' => true, 'methods' => ['manual']],
            'reporting_template' => ['frequency' => 'weekly', 'fields' => ['attendance']],
            'approval_hierarchy' => ['requires_approval' => false, 'levels' => []],
        ];

        $created = $this->actingAsMfaVerified($coordinator)
            ->postJson('/api/service-teams', $payload)
            ->assertCreated();

        $teamId = $created->json('data.id');

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/service-teams/{$teamId}/activate")
            ->assertOk();

        return $teamId;
    }

  /**
     * @return array<int, array<string, mixed>>
     */
    private function sampleFields(): array
    {
        return [
            [
                'key' => 'attendance_count',
                'label' => 'Attendance count',
                'type' => 'number',
                'required' => true,
                'help_text' => 'Total volunteers present.',
            ],
            [
                'key' => 'service_date',
                'label' => 'Service date',
                'type' => 'date',
                'required' => true,
            ],
            [
                'key' => 'coverage',
                'label' => 'Coverage quality',
                'type' => 'dropdown',
                'required' => true,
                'options' => ['excellent', 'good', 'needs_improvement'],
            ],
            [
                'key' => 'satisfaction',
                'label' => 'Team satisfaction',
                'type' => 'rating',
                'required' => false,
            ],
            [
                'key' => 'summary',
                'label' => 'Summary',
                'type' => 'text',
                'required' => true,
            ],
            [
                'key' => 'on_time',
                'label' => 'Started on time',
                'type' => 'checkbox',
                'required' => false,
            ],
            [
                'key' => 'completion_rate',
                'label' => 'Completion rate',
                'type' => 'percentage',
                'required' => false,
            ],
            [
                'key' => 'photo',
                'label' => 'Setup photo',
                'type' => 'image',
                'required' => false,
                'constraints' => ['allowed_mime_types' => ['image/jpeg', 'image/png']],
            ],
            [
                'key' => 'run_sheet',
                'label' => 'Run sheet',
                'type' => 'attachment',
                'required' => false,
                'constraints' => ['allowed_mime_types' => ['application/pdf']],
            ],
        ];
    }

    // ------------------------------------------------------------------
    // AC1 — draft preview and publish with validation
    // ------------------------------------------------------------------

    public function test_admin_creates_previews_and_publishes_form_for_teams(): void
    {
        $admin = $this->formAdmin();
        $teamId = $this->createActiveTeam($admin);

        $created = $this->actingAsMfaVerified($admin)
            ->postJson('/api/team-report-forms', [
                'name' => 'Media weekly report',
                'branch_id' => $this->branch->id,
                'fields' => $this->sampleFields(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', TeamReportForm::STATUS_DRAFT)
            ->assertJsonPath('data.draft_version', 1);

        $formId = $created->json('data.id');

        $this->actingAsMfaVerified($admin)
            ->getJson("/api/team-report-forms/{$formId}/preview")
            ->assertOk()
            ->assertJsonPath('data.version', 1)
            ->assertJsonCount(9, 'data.validation_preview');

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/team-report-forms/{$formId}/publish", ['team_ids' => [$teamId]])
            ->assertOk()
            ->assertJsonPath('data.status', TeamReportForm::STATUS_PUBLISHED)
            ->assertJsonPath('data.current_version', 1);

        $this->actingAsMfaVerified($admin)
            ->getJson("/api/service-teams/{$teamId}/report-form")
            ->assertOk()
            ->assertJsonPath('data.form_id', $formId)
            ->assertJsonPath('data.version', 1)
            ->assertJsonCount(9, 'data.fields');

        $this->assertDatabaseHas('team_report_form_assignments', [
            'team_report_form_id' => $formId,
            'service_team_id' => $teamId,
            'form_version' => 1,
        ]);
    }

    public function test_dropdown_and_required_rules_are_enforced(): void
    {
        $admin = $this->formAdmin();
        $teamId = $this->createActiveTeam($admin);

        $formId = $this->actingAsMfaVerified($admin)
            ->postJson('/api/team-report-forms', [
                'name' => 'Strict form',
                'branch_id' => $this->branch->id,
                'fields' => $this->sampleFields(),
            ])
            ->json('data.id');

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/team-report-forms/{$formId}/publish", ['team_ids' => [$teamId]])
            ->assertOk();

        $reportId = $this->actingAsMfaVerified($admin)
            ->postJson("/api/service-teams/{$teamId}/reports", [
                'reporting_period_start' => '2026-08-01',
                'reporting_period_end' => '2026-08-07',
            ])
            ->assertCreated()
            ->assertJsonPath('data.team_report_form_id', $formId)
            ->assertJsonPath('data.team_report_form_version', 1)
            ->json('data.id');

        $this->actingAsMfaVerified($admin)
            ->putJson("/api/team-reports/{$reportId}", [
                'field_values' => [
                    'attendance_count' => 8,
                    'service_date' => '2026-08-03',
                    'coverage' => 'invalid_option',
                    'summary' => 'Service captured successfully.',
                ],
            ])
            ->assertStatus(422);

        $this->actingAsMfaVerified($admin)
            ->putJson("/api/team-reports/{$reportId}", [
                'field_values' => [
                    'attendance_count' => 8,
                    'service_date' => '2026-08-03',
                    'coverage' => 'good',
                    'summary' => 'Service captured successfully.',
                ],
            ])
            ->assertOk();

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/team-reports/{$reportId}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', TeamReport::STATUS_SUBMITTED);
    }

    // ------------------------------------------------------------------
    // AC2 — versioning, incompatible changes, historical renderability
    // ------------------------------------------------------------------

    public function test_new_form_version_applies_to_future_reports_only(): void
    {
        $admin = $this->formAdmin();
        $teamId = $this->createActiveTeam($admin);

        $formId = $this->actingAsMfaVerified($admin)
            ->postJson('/api/team-report-forms', [
                'name' => 'Versioned form',
                'branch_id' => $this->branch->id,
                'fields' => $this->sampleFields(),
            ])
            ->json('data.id');

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/team-report-forms/{$formId}/publish", ['team_ids' => [$teamId]])
            ->assertOk();

        $firstReportId = $this->actingAsMfaVerified($admin)
            ->postJson("/api/service-teams/{$teamId}/reports", [
                'reporting_period_start' => '2026-08-08',
                'reporting_period_end' => '2026-08-14',
            ])
            ->assertJsonPath('data.team_report_form_version', 1)
            ->json('data.id');

        $updatedFields = $this->sampleFields();
        $updatedFields[] = [
            'key' => 'equipment_notes',
            'label' => 'Equipment notes',
            'type' => 'text',
            'required' => false,
        ];

        $this->actingAsMfaVerified($admin)
            ->putJson("/api/team-report-forms/{$formId}/draft", ['fields' => $updatedFields])
            ->assertOk()
            ->assertJsonPath('data.draft_version', 2);

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/team-report-forms/{$formId}/publish", ['team_ids' => [$teamId]])
            ->assertOk()
            ->assertJsonPath('data.current_version', 2);

        $secondReportId = $this->actingAsMfaVerified($admin)
            ->postJson("/api/service-teams/{$teamId}/reports", [
                'reporting_period_start' => '2026-08-15',
                'reporting_period_end' => '2026-08-21',
            ])
            ->assertJsonPath('data.team_report_form_version', 2)
            ->json('data.id');

        $this->actingAsMfaVerified($admin)
            ->getJson("/api/team-reports/{$firstReportId}")
            ->assertOk()
            ->assertJsonPath('data.team_report_form_version', 1)
            ->assertJsonCount(9, 'data.template_snapshot.fields');

        $this->actingAsMfaVerified($admin)
            ->getJson("/api/team-reports/{$secondReportId}")
            ->assertOk()
            ->assertJsonPath('data.team_report_form_version', 2)
            ->assertJsonCount(10, 'data.template_snapshot.fields');
    }

    public function test_incompatible_field_changes_require_explicit_migration(): void
    {
        $admin = $this->formAdmin();
        $teamId = $this->createActiveTeam($admin);

        $formId = $this->actingAsMfaVerified($admin)
            ->postJson('/api/team-report-forms', [
                'name' => 'Guarded form',
                'branch_id' => $this->branch->id,
                'fields' => $this->sampleFields(),
            ])
            ->json('data.id');

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/team-report-forms/{$formId}/publish", ['team_ids' => [$teamId]])
            ->assertOk();

        $brokenFields = $this->sampleFields();
        $brokenFields[0]['type'] = 'text';

        $this->actingAsMfaVerified($admin)
            ->putJson("/api/team-report-forms/{$formId}/draft", ['fields' => $brokenFields])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'incompatible_changes');

        $this->actingAsMfaVerified($admin)
            ->putJson("/api/team-report-forms/{$formId}/draft", [
                'fields' => $brokenFields,
                'allow_incompatible_changes' => true,
                'migration_notes' => 'Attendance count now captured as text notes.',
            ])
            ->assertOk()
            ->assertJsonPath('data.draft_version', 2);
    }
}
