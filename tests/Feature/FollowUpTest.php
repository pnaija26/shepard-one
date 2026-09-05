<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\FollowUp;
use App\Models\FollowUpEscalation;
use App\Models\Member;
use App\Models\MemberNotification;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\Visitor;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 3.4: Complete Assigned Follow-Up.
 */
class FollowUpTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'IDX-HQ']);
        $this->branch = Organization::create(['name' => 'Branch A', 'type' => 'branch', 'identifier' => 'IDX-A', 'parent_id' => $hq->id]);
    }

    private function coordinator(): User
    {
        $user = $this->privilegedUser(['branch_id' => null]);
        $role = Role::create(['name' => 'followup_coord_' . $user->id]);
        foreach (['followups.read', 'followups.manage', 'followups.escalate'] as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);

        return $user;
    }

    private function officer(array $overrides = []): User
    {
        $user = $this->privilegedUser(array_merge(['branch_id' => $this->branch->id], $overrides));
        $role = Role::create(['name' => 'followup_officer_' . $user->id]);
        foreach (['followups.read', 'followups.work'] as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);

        Member::create([
            'user_id' => $user->id,
            'membership_id' => 'S1-M-OFF-' . $user->id,
            'branch_id' => $this->branch->id,
            'registration_channel' => 'web',
            'first_name' => 'Officer',
            'last_name' => 'One',
            'consent_data_processing' => true,
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
        ]);

        return $user;
    }

    private function escalationLead(): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $role = Role::create(['name' => 'follow_up_lead']);
        foreach (['followups.read', 'followups.escalate', 'followups.work'] as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);

        return $user;
    }

    private function visitor(): Visitor
    {
        return Visitor::create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Tolu',
            'last_name' => 'Ade',
            'original_source' => 'service',
            'first_visit_at' => '2026-08-31',
            'created_by' => null,
            'updated_by' => null,
        ]);
    }

    // ------------------------------------------------------------------
    // AC1 — create with required fields and safe assignee notification
    // ------------------------------------------------------------------

    public function test_coordinator_can_create_follow_up_and_notify_assignee(): void
    {
        Carbon::setTestNow('2026-08-31');
        $coordinator = $this->coordinator();
        $officer = $this->officer();
        $visitor = $this->visitor();

        $this->actingAsMfaVerified($coordinator)
            ->postJson('/api/follow-ups', [
                'person_type' => Visitor::class,
                'person_id' => $visitor->id,
                'branch_id' => $this->branch->id,
                'reason' => 'Prayer need: confidential housing support',
                'assignee_id' => $officer->id,
                'due_date' => '2026-09-03',
                'contact_method' => 'phone',
                'priority' => 'high',
                'source_type' => 'manual',
                'is_restricted' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.assignee_id', $officer->id)
            ->assertJsonPath('data.reason', 'Restricted follow-up')
            ->assertJsonPath('data.priority', 'high');

        $this->assertDatabaseHas('follow_ups', [
            'person_id' => $visitor->id,
            'assignee_id' => $officer->id,
            'contact_method' => 'phone',
            'status' => FollowUp::STATUS_ASSIGNED,
        ]);

        $this->assertDatabaseHas('member_notifications', [
            'user_id' => $officer->id,
            'type' => 'followup.assigned',
            'message' => 'You have been assigned a new follow-up task.',
        ]);

        $this->assertDatabaseMissing('member_notifications', [
            'message' => 'Prayer need: confidential housing support',
        ]);
    }

    // ------------------------------------------------------------------
    // AC2 — officer records activity history and can close
    // ------------------------------------------------------------------

    public function test_officer_records_contact_history_and_closes_follow_up(): void
    {
        Carbon::setTestNow('2026-08-31');
        $coordinator = $this->coordinator();
        $officer = $this->officer();
        $visitor = $this->visitor();

        $this->actingAsMfaVerified($coordinator)
            ->postJson('/api/follow-ups', [
                'person_type' => Visitor::class,
                'person_id' => $visitor->id,
                'branch_id' => $this->branch->id,
                'reason' => 'Welcome call after first visit',
                'assignee_id' => $officer->id,
                'due_date' => '2026-09-02',
                'contact_method' => 'phone',
                'priority' => 'normal',
            ])
            ->assertCreated();

        $followUp = FollowUp::firstOrFail();

        $this->actingAsMfaVerified($officer)
            ->postJson("/api/follow-ups/{$followUp->id}/activities", [
                'activity_type' => 'contact_attempt',
                'outcome' => 'no_answer',
                'notes' => 'Left voicemail',
                'next_action' => 'continue',
            ])
            ->assertOk()
            ->assertJsonPath('data.follow_up.status', FollowUp::STATUS_IN_PROGRESS);

        $this->actingAsMfaVerified($officer)
            ->postJson("/api/follow-ups/{$followUp->id}/activities", [
                'activity_type' => 'outcome',
                'outcome' => 'successful',
                'notes' => 'Spoke with visitor and scheduled visit',
                'next_action' => 'close',
            ])
            ->assertOk()
            ->assertJsonPath('data.follow_up.status', FollowUp::STATUS_CLOSED);

        $this->assertDatabaseCount('follow_up_activities', 2);
        $this->assertDatabaseHas('audit_events', ['action' => 'followup.activity.recorded']);

        $this->actingAsMfaVerified($coordinator)
            ->getJson("/api/follow-ups/{$followUp->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.activities');
    }

    // ------------------------------------------------------------------
    // AC3 — escalation without duplicates
    // ------------------------------------------------------------------

    public function test_overdue_follow_up_escalates_once_to_branch_lead(): void
    {
        Carbon::setTestNow('2026-09-10');
        $coordinator = $this->coordinator();
        $officer = $this->officer();
        $lead = $this->escalationLead();
        $visitor = $this->visitor();

        $this->actingAsMfaVerified($coordinator)
            ->postJson('/api/follow-ups', [
                'person_type' => Visitor::class,
                'person_id' => $visitor->id,
                'branch_id' => $this->branch->id,
                'reason' => 'Overdue welcome follow-up',
                'assignee_id' => $officer->id,
                'due_date' => '2026-09-01',
                'contact_method' => 'phone',
                'priority' => 'high',
            ])
            ->assertCreated();

        $followUp = FollowUp::firstOrFail();

        $this->actingAsMfaVerified($coordinator)
            ->postJson('/api/follow-ups/process-escalations')
            ->assertOk()
            ->assertJsonPath('data.escalated', 1);

        $followUp->refresh();
        $this->assertSame($lead->id, $followUp->assignee_id);
        $this->assertSame(FollowUp::STATUS_ESCALATED, $followUp->status);
        $this->assertDatabaseCount('follow_up_escalations', 1);
        $this->assertDatabaseHas('audit_events', ['action' => 'followup.escalated']);

        $this->actingAsMfaVerified($coordinator)
            ->postJson('/api/follow-ups/process-escalations')
            ->assertOk()
            ->assertJsonPath('data.escalated', 0);

        $this->assertDatabaseCount('follow_up_escalations', 1);
    }

    public function test_unsuccessful_outcome_triggers_escalation(): void
    {
        Carbon::setTestNow('2026-09-05');
        $coordinator = $this->coordinator();
        $officer = $this->officer();
        $lead = $this->escalationLead();
        $visitor = $this->visitor();

        $this->actingAsMfaVerified($coordinator)
            ->postJson('/api/follow-ups', [
                'person_type' => Visitor::class,
                'person_id' => $visitor->id,
                'branch_id' => $this->branch->id,
                'reason' => 'Unable to reach visitor',
                'assignee_id' => $officer->id,
                'due_date' => '2026-09-10',
                'contact_method' => 'phone',
                'priority' => 'normal',
            ])
            ->assertCreated();

        $followUp = FollowUp::firstOrFail();

        $this->actingAsMfaVerified($officer)
            ->postJson("/api/follow-ups/{$followUp->id}/activities", [
                'activity_type' => 'outcome',
                'outcome' => 'unsuccessful',
                'notes' => 'Number unreachable after three attempts',
                'next_action' => 'escalate',
            ])
            ->assertOk();

        $this->actingAsMfaVerified($coordinator)
            ->postJson('/api/follow-ups/process-escalations')
            ->assertOk()
            ->assertJsonPath('data.escalated', 1);

        $followUp->refresh();
        $this->assertSame($lead->id, $followUp->assignee_id);
        $this->assertDatabaseHas('follow_up_escalations', [
            'follow_up_id' => $followUp->id,
            'trigger_type' => 'unsuccessful',
            'to_assignee_id' => $lead->id,
        ]);
    }
}
