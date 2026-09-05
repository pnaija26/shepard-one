<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\MemberNotification;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Models\WorkflowInstanceEvent;
use App\Models\WorkflowSchedulerAction;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 9.3: Execute Workflow Actions and Escalations.
 */
class WorkflowExecutionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'WFX-HQ']);
        $this->branch = Organization::create(['name' => 'Branch A', 'type' => 'branch', 'identifier' => 'WFX-A', 'parent_id' => $hq->id]);
    }

    private function grant(User $user, array $actions): void
    {
        $role = Role::create(['name' => 'wfx_' . $user->id . '_' . substr(md5(implode(',', $actions)), 0, 6)]);
        foreach ($actions as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);
    }

    private function linkedMember(User $user): void
    {
        Member::create([
            'membership_id' => 'WFX-M-' . $user->id,
            'branch_id' => $this->branch->id,
            'user_id' => $user->id,
            'registration_channel' => 'web',
            'first_name' => 'Flow',
            'last_name' => 'User' . $user->id,
            'consent_data_processing' => true,
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
        ]);
    }

    private function admin(): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $this->grant($user, [
            'workflows.read',
            'workflows.manage',
            'workflows.publish',
            'workflows.start',
            'workflows.process_deadlines',
            'tasks.work',
            'tasks.manage',
        ]);
        $this->linkedMember($user);

        return $user;
    }

    private function participant(): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $this->grant($user, [
            'workflows.participate',
            'workflows.read',
            'tasks.work',
        ]);
        $this->linkedMember($user);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function definition(): array
    {
        return [
            'trigger' => ['type' => 'event', 'event' => 'welfare.submitted'],
            'conditions' => [
                ['field' => 'amount', 'operator' => 'gte', 'value' => 10],
            ],
            'states' => [
                ['key' => 'start', 'type' => 'start', 'label' => 'Start'],
                ['key' => 'review', 'type' => 'approval', 'label' => 'Review'],
                ['key' => 'approved', 'type' => 'end', 'label' => 'Approved'],
                ['key' => 'rejected', 'type' => 'end', 'label' => 'Rejected'],
            ],
            'transitions' => [
                ['from' => 'start', 'to' => 'review', 'action' => 'submit'],
                ['from' => 'review', 'to' => 'approved', 'on' => 'approve'],
                ['from' => 'review', 'to' => 'rejected', 'on' => 'reject'],
                ['from' => 'review', 'to' => 'review', 'on' => 'return'],
            ],
            'assignments' => [
                ['state' => 'review', 'permission' => 'tasks.work'],
            ],
            'approvals' => [['state' => 'review', 'quorum' => 1]],
            'rejection' => ['target_state' => 'rejected'],
            'escalation' => [
                'after_hours' => 1,
                'to_permission' => 'tasks.manage',
                'max_loops' => 2,
            ],
            'notifications' => [],
            'deadlines' => [
                ['state' => 'review', 'hours' => 1],
            ],
            'reminders' => [
                ['state' => 'review', 'every_hours' => 1, 'max_count' => 3],
            ],
            'end_states' => ['approved', 'rejected'],
        ];
    }

    private function publishWorkflow(User $admin): int
    {
        $id = $this->actingAsMfaVerified($admin)
            ->postJson('/api/workflows', [
                'name' => 'Execution flow',
                'branch_id' => $this->branch->id,
                'definition' => $this->definition(),
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/workflows/{$id}/publish")
            ->assertOk();

        return $id;
    }

    public function test_event_starts_instance_idempotently_with_version_and_assignment(): void
    {
        $admin = $this->admin();
        $participant = $this->participant();
        $workflowId = $this->publishWorkflow($admin);

        $payload = [
            'trigger_type' => 'event',
            'trigger_event' => 'welfare.submitted',
            'branch_id' => $this->branch->id,
            'assignee_id' => $participant->id,
            'source_type' => 'App\\Models\\WelfareRequest',
            'source_id' => 99,
            'context' => ['amount' => 50],
        ];

        $first = $this->actingAsMfaVerified($admin)
            ->postJson("/api/workflows/{$workflowId}/instances", $payload)
            ->assertCreated()
            ->assertJsonPath('data.current_state', 'review')
            ->assertJsonPath('data.workflow_version', 1)
            ->assertJsonPath('data.assignee_id', $participant->id)
            ->json('data');

        $this->assertNotNull($first['due_at']);
        $this->assertNotNull($first['started_at']);

        $second = $this->actingAsMfaVerified($admin)
            ->postJson("/api/workflows/{$workflowId}/instances", $payload)
            ->assertCreated()
            ->json('data');

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(1, WorkflowInstance::query()->where('workflow_id', $workflowId)->count());

        $this->assertDatabaseHas('workflow_instance_events', [
            'workflow_instance_id' => $first['id'],
            'event_type' => 'started',
        ]);
    }

    public function test_incomplete_or_prohibited_context_fails_securely(): void
    {
        $admin = $this->admin();
        $workflowId = $this->publishWorkflow($admin);

        $denied = $this->actingAsMfaVerified($admin)
            ->postJson("/api/workflows/{$workflowId}/instances", [
                'trigger_type' => 'event',
                'trigger_event' => 'welfare.submitted',
                'branch_id' => $this->branch->id,
                'context' => ['amount' => 1],
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'incomplete_context')
            ->json();

        $this->assertArrayNotHasKey('password', $denied['details'] ?? []);
        $this->assertSame(0, WorkflowInstance::query()->count());

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/workflows/{$workflowId}/instances", [
                'trigger_type' => 'event',
                'trigger_event' => 'wrong.event',
                'branch_id' => $this->branch->id,
                'context' => ['amount' => 50],
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'trigger_mismatch');
    }

    public function test_participant_actions_record_immutable_history(): void
    {
        $admin = $this->admin();
        $participant = $this->participant();
        $workflowId = $this->publishWorkflow($admin);

        $id = $this->actingAsMfaVerified($admin)
            ->postJson("/api/workflows/{$workflowId}/instances", [
                'trigger_type' => 'event',
                'trigger_event' => 'welfare.submitted',
                'branch_id' => $this->branch->id,
                'assignee_id' => $participant->id,
                'idempotency_key' => 'act-1',
                'context' => ['amount' => 40],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($participant)
            ->postJson("/api/workflow-instances/{$id}/act", [
                'decision' => 'return',
                'comment' => 'Need more detail.',
            ])
            ->assertOk()
            ->assertJsonPath('data.current_state', 'review');

        $approved = $this->actingAsMfaVerified($participant)
            ->postJson("/api/workflow-instances/{$id}/act", [
                'decision' => 'approve',
                'comment' => 'Looks good.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.current_state', 'approved')
            ->json('data');

        $this->assertNotNull($approved['completed_at']);
        $this->assertTrue(collect($approved['events'])->contains(fn ($e) => $e['decision'] === 'approve' && $e['comment'] === 'Looks good.'));

        $event = WorkflowInstanceEvent::query()->where('decision', 'approve')->firstOrFail();
        try {
            $event->update(['comment' => 'tamper']);
            $this->fail('Expected immutable event update to throw.');
        } catch (\App\Services\WorkflowException $exception) {
            $this->assertSame('immutable', $exception->codeKey());
        }
    }

    public function test_deadline_processor_reminds_and_escalates_once_per_window(): void
    {
        Carbon::setTestNow('2026-08-20 10:00:00');

        $admin = $this->admin();
        $participant = $this->participant();
        $workflowId = $this->publishWorkflow($admin);

        $id = $this->actingAsMfaVerified($admin)
            ->postJson("/api/workflows/{$workflowId}/instances", [
                'trigger_type' => 'event',
                'trigger_event' => 'welfare.submitted',
                'branch_id' => $this->branch->id,
                'assignee_id' => $participant->id,
                'idempotency_key' => 'due-1',
                'context' => ['amount' => 40],
            ])
            ->assertCreated()
            ->json('data.id');

        WorkflowInstance::query()->whereKey($id)->update([
            'due_at' => '2026-08-20 08:00:00',
        ]);

        // First pass: overdue past escalation after_hours → escalate
        $first = $this->actingAsMfaVerified($admin)
            ->postJson('/api/workflow-instances/process-deadlines')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $first['processed']);
        $this->assertSame(1, $first['escalated']);
        $this->assertSame(1, WorkflowSchedulerAction::query()->where('action_type', 'escalation')->count());
        $this->assertDatabaseHas('workflow_instance_events', [
            'workflow_instance_id' => $id,
            'event_type' => 'escalated',
        ]);

        $second = $this->actingAsMfaVerified($admin)
            ->postJson('/api/workflow-instances/process-deadlines')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $second['skipped']);
        $this->assertSame(1, WorkflowSchedulerAction::query()->where('action_type', 'escalation')->count());

        // Force reminder path by exhausting escalation loops for this instance
        WorkflowInstance::query()->whereKey($id)->update([
            'escalation_count' => 2,
            'due_at' => '2026-08-20 09:30:00',
        ]);

        $third = $this->actingAsMfaVerified($admin)
            ->postJson('/api/workflow-instances/process-deadlines')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $third['reminded']);
        $this->assertSame(1, WorkflowSchedulerAction::query()->where('action_type', 'reminder')->count());
        $this->assertSame(
            1,
            MemberNotification::query()->where('type', 'workflow.step.reminder')->count()
        );

        $fourth = $this->actingAsMfaVerified($admin)
            ->postJson('/api/workflow-instances/process-deadlines')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $fourth['skipped']);
        $this->assertSame(1, WorkflowSchedulerAction::query()->where('action_type', 'reminder')->count());

        Carbon::setTestNow();
    }
}
