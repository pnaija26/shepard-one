<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\MemberNotification;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\TeamReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 5.6: Submit and Approve Team Reports.
 */
class TeamReportTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'IDX-HQ']);
        $this->branch = Organization::create(['name' => 'Branch A', 'type' => 'branch', 'identifier' => 'IDX-A', 'parent_id' => $hq->id]);
    }

    private function leader(): User
    {
        $user = $this->privilegedUser();
        $role = Role::create(['name' => 'team_report_lead_' . $user->id]);
        foreach (['teams.read', 'teams.manage', 'teams.reports.read', 'teams.reports.submit'] as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);

        Member::create([
            'user_id' => $user->id,
            'membership_id' => 'S56-LEAD-' . $user->id,
            'branch_id' => $this->branch->id,
            'registration_channel' => 'web',
            'first_name' => 'Report',
            'last_name' => 'Leader',
            'consent_data_processing' => true,
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
        ]);

        return $user;
    }

    private function reviewer(): User
    {
        $user = $this->privilegedUser();
        $role = Role::create(['name' => 'team_report_rev_' . $user->id]);
        foreach (['teams.reports.read', 'teams.reports.review'] as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);

        return $user;
    }

    private function teamLeader(): User
    {
        return User::factory()->create(['roles' => ['member'], 'has_mfa_enrolled' => true, 'branch_id' => $this->branch->id]);
    }

    private function createActiveTeam(User $coordinator, User $reviewerUser): int
    {
        Member::create([
            'user_id' => $reviewerUser->id,
            'membership_id' => 'S56-REV-' . $reviewerUser->id,
            'branch_id' => $this->branch->id,
            'registration_channel' => 'web',
            'first_name' => 'Review',
            'last_name' => 'Lead',
            'consent_data_processing' => true,
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
        ]);

        $payload = [
            'branch_id' => $this->branch->id,
            'name' => 'Worship Team ' . uniqid(),
            'category' => 'worship',
            'description' => 'Sunday worship team.',
            'leaders' => [['user_id' => $reviewerUser->id, 'role' => 'lead']],
            'required_skills' => ['vocals', 'keyboard'],
            'minimum_staffing' => ['minimum_per_session' => 2, 'maximum_per_session' => 8],
            'schedules' => [['type' => 'weekly', 'label' => 'Sunday service', 'required_volunteers' => 2]],
            'objectives' => ['Lead worship.'],
            'attendance_rules' => ['require_check_in' => true, 'methods' => ['manual']],
            'reporting_template' => ['frequency' => 'weekly', 'fields' => ['attendance', 'issues']],
            'approval_hierarchy' => [
                'requires_approval' => true,
                'levels' => [['user_id' => $reviewerUser->id, 'role' => 'department_head']],
            ],
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

    private function reportContent(): array
    {
        return [
            'field_values' => [
                'attendance' => 42,
                'issues' => 'Sound check delayed by 10 minutes.',
            ],
            'attachments' => [[
                'label' => 'Service summary',
                'type' => 'document',
                'reference' => 'https://files.example/team-summary.pdf',
            ]],
            'incidents' => [['summary' => 'Microphone feedback during worship set.']],
            'concerns' => 'Need one more keyboard player next month.',
            'results' => ['attendance_count' => 42, 'services_covered' => 1],
            'recommendations' => ['Schedule extra rehearsal for new songs.'],
        ];
    }

    // ------------------------------------------------------------------
    // AC1 — versioned drafts and single valid submission
    // ------------------------------------------------------------------

    public function test_leader_saves_draft_and_submits_versioned_report(): void
    {
        $leader = $this->leader();
        $reviewer = $this->reviewer();
        $teamId = $this->createActiveTeam($leader, $reviewer);

        $created = $this->actingAsMfaVerified($leader)
            ->postJson("/api/service-teams/{$teamId}/reports", [
                'reporting_period_start' => '2026-08-01',
                'reporting_period_end' => '2026-08-07',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', TeamReport::STATUS_DRAFT)
            ->assertJsonPath('data.version', 1);

        $reportId = $created->json('data.id');

        $this->actingAsMfaVerified($leader)
            ->putJson("/api/team-reports/{$reportId}", $this->reportContent())
            ->assertOk()
            ->assertJsonPath('data.version', 2);

        $this->actingAsMfaVerified($leader)
            ->postJson("/api/team-reports/{$reportId}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', TeamReport::STATUS_SUBMITTED)
            ->assertJsonPath('data.is_locked', true)
            ->assertJsonPath('data.is_editable', false);

        $this->assertDatabaseHas('team_report_versions', [
            'team_report_id' => $reportId,
            'change_type' => 'submitted',
        ]);
    }

    public function test_submitted_report_is_read_only_until_returned(): void
    {
        $leader = $this->leader();
        $reviewer = $this->reviewer();
        $teamId = $this->createActiveTeam($leader, $reviewer);

        $reportId = $this->actingAsMfaVerified($leader)
            ->postJson("/api/service-teams/{$teamId}/reports", [
                'reporting_period_start' => '2026-08-08',
                'reporting_period_end' => '2026-08-14',
            ])
            ->json('data.id');

        $this->actingAsMfaVerified($leader)
            ->putJson("/api/team-reports/{$reportId}", $this->reportContent())
            ->assertOk();

        $this->actingAsMfaVerified($leader)
            ->postJson("/api/team-reports/{$reportId}/submit")
            ->assertOk();

        $this->actingAsMfaVerified($leader)
            ->putJson("/api/team-reports/{$reportId}", ['concerns' => 'Changed after submit'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->actingAsMfaVerified($reviewer)
            ->postJson("/api/team-reports/{$reportId}/review", [
                'decision' => 'returned',
                'comments' => 'Please add incident follow-up details.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', TeamReport::STATUS_RETURNED)
            ->assertJsonPath('data.is_editable', true);

        $this->actingAsMfaVerified($leader)
            ->putJson("/api/team-reports/{$reportId}", array_merge($this->reportContent(), [
                'concerns' => 'Updated after return with follow-up notes.',
            ]))
            ->assertOk();
    }

    // ------------------------------------------------------------------
    // AC2 — review workflow, notifications, consolidated metrics
    // ------------------------------------------------------------------

    public function test_reviewer_approval_records_decision_and_notifies_parties(): void
    {
        $leader = $this->leader();
        $reviewer = $this->reviewer();
        $teamId = $this->createActiveTeam($leader, $reviewer);

        $reportId = $this->actingAsMfaVerified($leader)
            ->postJson("/api/service-teams/{$teamId}/reports", [
                'reporting_period_start' => '2026-08-15',
                'reporting_period_end' => '2026-08-21',
            ])
            ->json('data.id');

        $this->actingAsMfaVerified($leader)
            ->putJson("/api/team-reports/{$reportId}", $this->reportContent())
            ->assertOk();

        $this->actingAsMfaVerified($leader)
            ->postJson("/api/team-reports/{$reportId}/submit")
            ->assertOk();

        $this->actingAsMfaVerified($reviewer)
            ->postJson("/api/team-reports/{$reportId}/review", [
                'decision' => 'approved',
                'comments' => 'Well documented service report.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', TeamReport::STATUS_APPROVED)
            ->assertJsonPath('data.review_decision', 'approved');

        $this->assertDatabaseHas('member_notifications', ['type' => 'team_report.submitted']);
        $this->assertDatabaseHas('member_notifications', ['type' => 'team_report.reviewed']);
        $this->assertDatabaseHas('audit_events', ['action' => 'team_report.approved']);
    }

    public function test_only_approved_reports_feed_consolidated_metrics(): void
    {
        $leader = $this->leader();
        $reviewer = $this->reviewer();
        $teamId = $this->createActiveTeam($leader, $reviewer);

        $approvedId = $this->actingAsMfaVerified($leader)
            ->postJson("/api/service-teams/{$teamId}/reports", [
                'reporting_period_start' => '2026-08-22',
                'reporting_period_end' => '2026-08-28',
            ])
            ->json('data.id');

        $this->actingAsMfaVerified($leader)
            ->putJson("/api/team-reports/{$approvedId}", $this->reportContent())
            ->assertOk();

        $this->actingAsMfaVerified($leader)
            ->postJson("/api/team-reports/{$approvedId}/submit")
            ->assertOk();

        $this->actingAsMfaVerified($reviewer)
            ->postJson("/api/team-reports/{$approvedId}/review", ['decision' => 'approved'])
            ->assertOk();

        $submittedId = $this->actingAsMfaVerified($leader)
            ->postJson("/api/service-teams/{$teamId}/reports", [
                'reporting_period_start' => '2026-08-29',
                'reporting_period_end' => '2026-09-04',
            ])
            ->json('data.id');

        $this->actingAsMfaVerified($leader)
            ->putJson("/api/team-reports/{$submittedId}", $this->reportContent())
            ->assertOk();

        $this->actingAsMfaVerified($leader)
            ->postJson("/api/team-reports/{$submittedId}/submit")
            ->assertOk();

        $this->actingAsMfaVerified($leader)
            ->getJson("/api/service-teams/{$teamId}/report-metrics")
            ->assertOk()
            ->assertJsonPath('data.approved_reports', 1)
            ->assertJsonPath('data.attendance_totals', 42)
            ->assertJsonPath('data.pending_in_consolidated_metrics', 1);
    }
}
