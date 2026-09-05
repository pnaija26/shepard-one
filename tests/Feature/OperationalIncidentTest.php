<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Member;
use App\Models\OperationalIncident;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 4.6: Resolve Operational Incidents.
 */
class OperationalIncidentTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'IDX-HQ']);
        $this->branch = Organization::create(['name' => 'Branch A', 'type' => 'branch', 'identifier' => 'IDX-A', 'parent_id' => $hq->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function reporter(): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $role = Role::create(['name' => 'incident_reporter_' . $user->id]);
        RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => 'incidents.report']);
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);

        return $user;
    }

    private function responder(): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $role = Role::create(['name' => 'incident_responder_' . $user->id]);
        foreach (['incidents.read', 'incidents.respond'] as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);

        Member::create([
            'user_id' => $user->id,
            'membership_id' => 'S1-M-INC-R-' . $user->id,
            'branch_id' => $this->branch->id,
            'registration_channel' => 'web',
            'first_name' => 'Responder',
            'last_name' => 'One',
            'consent_data_processing' => true,
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
        ]);

        return $user;
    }

    private function reviewer(): User
    {
        $user = $this->privilegedUser(['branch_id' => null]);
        $role = Role::create(['name' => 'incident_reviewer_' . $user->id]);
        foreach (['incidents.read', 'incidents.review'] as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);

        return $user;
    }

    private function incidentPayload(array $overrides = []): array
    {
        return array_merge([
            'branch_id' => $this->branch->id,
            'classification' => 'equipment',
            'priority' => 'normal',
            'occurred_at' => '2026-09-07T11:00:00+00:00',
            'location' => 'Main Auditorium',
            'description' => 'Projector failed during the sermon presentation.',
            'evidence' => ['Checked HDMI cable connection at the media desk.'],
        ], $overrides);
    }

    // ------------------------------------------------------------------
    // AC1 — incident creation, assignment, and restricted details
    // ------------------------------------------------------------------

    public function test_authorized_user_reports_incident_with_assignment_and_evidence(): void
    {
        $reporter = $this->reporter();
        $responder = $this->responder();

        $this->actingAsMfaVerified($reporter)
            ->postJson('/api/incidents', $this->incidentPayload())
            ->assertCreated()
            ->assertJsonPath('data.classification', 'equipment')
            ->assertJsonPath('data.assigned_team', 'facilities')
            ->assertJsonPath('data.owner.id', $responder->id)
            ->assertJsonStructure(['data' => ['reference']]);

        $this->assertDatabaseHas('operational_incidents', [
            'classification' => 'equipment',
            'assigned_team' => 'facilities',
            'owner_id' => $responder->id,
        ]);

        $this->assertDatabaseHas('audit_events', ['action' => 'incident.reported']);
    }

    public function test_restricted_incident_hides_sensitive_details_from_unauthorized_readers(): void
    {
        $reporter = $this->reporter();
        $responder = $this->responder();

        $created = $this->actingAsMfaVerified($reporter)
            ->postJson('/api/incidents', $this->incidentPayload([
                'classification' => 'child_safety',
                'priority' => 'high',
                'description' => 'Child left unattended near the playground gate.',
                'sensitive_details' => 'Parent contact withheld pending safeguarding review.',
            ]))
            ->assertCreated();

        $incidentId = $created->json('data.id');

        $this->actingAsMfaVerified($reporter)
            ->getJson("/api/incidents/{$incidentId}")
            ->assertOk()
            ->assertJsonPath('data.description', 'Restricted incident details.')
            ->assertJsonPath('data.is_restricted', true);

        $this->actingAsMfaVerified($responder)
            ->getJson("/api/incidents/{$incidentId}")
            ->assertOk()
            ->assertJsonPath('data.description', 'Child left unattended near the playground gate.');
    }

    public function test_attachment_submission_is_rejected(): void
    {
        $reporter = $this->reporter();

        $this->actingAsMfaVerified($reporter)
            ->postJson('/api/incidents', array_merge($this->incidentPayload(), [
                'attachments' => ['photo.jpg'],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['evidence']);
    }

    // ------------------------------------------------------------------
    // AC2 — response workflow and escalation without duplicates
    // ------------------------------------------------------------------

    public function test_responder_records_history_and_escalation_is_not_duplicated(): void
    {
        $reporter = $this->reporter();
        $responder = $this->responder();
        $this->reviewer();

        Carbon::setTestNow('2026-09-07 12:00:00');

        $created = $this->actingAsMfaVerified($reporter)
            ->postJson('/api/incidents', $this->incidentPayload(['priority' => 'normal']))
            ->assertCreated();

        $incidentId = $created->json('data.id');

        $this->actingAsMfaVerified($responder)
            ->postJson("/api/incidents/{$incidentId}/activities", [
                'activity_type' => 'investigation',
                'notes' => 'Inspected projector and replaced HDMI cable.',
            ])
            ->assertOk()
            ->assertJsonPath('data.incident.status', OperationalIncident::STATUS_INVESTIGATING);

        Carbon::setTestNow('2026-09-08 13:00:00');

        $first = $this->actingAsMfaVerified($responder)
            ->postJson('/api/incidents/process-escalations', ['branch_id' => $this->branch->id])
            ->assertOk();

        $second = $this->actingAsMfaVerified($responder)
            ->postJson('/api/incidents/process-escalations', ['branch_id' => $this->branch->id])
            ->assertOk()
            ->assertJsonPath('data.escalated', 0);

        $this->assertSame(1, $first->json('data.escalated'));
        $this->assertDatabaseCount('operational_incident_escalations', 1);

        $this->assertDatabaseHas('operational_incident_activities', [
            'operational_incident_id' => $incidentId,
            'activity_type' => 'investigation',
        ]);
    }

    // ------------------------------------------------------------------
    // AC3 — management review approves or returns closure
    // ------------------------------------------------------------------

    public function test_reviewer_approves_closure_with_outcome_and_audit(): void
    {
        $reporter = $this->reporter();
        $responder = $this->responder();
        $reviewer = $this->reviewer();

        $created = $this->actingAsMfaVerified($reporter)
            ->postJson('/api/incidents', $this->incidentPayload(['priority' => 'high']))
            ->assertCreated();

        $incidentId = $created->json('data.id');

        $this->actingAsMfaVerified($responder)
            ->postJson("/api/incidents/{$incidentId}/activities", [
                'activity_type' => 'resolution',
                'closure_outcome' => 'Projector replaced and tested successfully.',
                'follow_up_actions' => 'Schedule preventive maintenance.',
            ])
            ->assertOk()
            ->assertJsonPath('data.incident.status', OperationalIncident::STATUS_PENDING_REVIEW);

        $this->actingAsMfaVerified($reviewer)
            ->postJson("/api/incidents/{$incidentId}/review", [
                'decision' => 'approve',
                'notes' => 'Closure approved by duty manager.',
                'follow_up_actions' => 'Facilities to complete maintenance checklist.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', OperationalIncident::STATUS_CLOSED)
            ->assertJsonPath('data.follow_up_actions', 'Facilities to complete maintenance checklist.');

        $this->assertDatabaseHas('audit_events', ['action' => 'incident.reviewed']);
    }

    public function test_reviewer_returns_incident_to_accountable_owner(): void
    {
        $reporter = $this->reporter();
        $responder = $this->responder();
        $reviewer = $this->reviewer();

        $created = $this->actingAsMfaVerified($reporter)
            ->postJson('/api/incidents', $this->incidentPayload(['priority' => 'high']))
            ->assertCreated();

        $incidentId = $created->json('data.id');

        $this->actingAsMfaVerified($responder)
            ->postJson("/api/incidents/{$incidentId}/activities", [
                'activity_type' => 'resolution',
                'closure_outcome' => 'Initial fix attempted.',
            ])
            ->assertOk();

        $this->actingAsMfaVerified($reviewer)
            ->postJson("/api/incidents/{$incidentId}/review", [
                'decision' => 'return',
                'owner_id' => $responder->id,
                'notes' => 'More investigation required before closure.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', OperationalIncident::STATUS_RETURNED)
            ->assertJsonPath('data.owner.id', $responder->id);

        $this->assertDatabaseHas('operational_incident_activities', [
            'operational_incident_id' => $incidentId,
            'activity_type' => 'review_returned',
        ]);
    }
}
