<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\MemberNotification;
use App\Models\OperationalTask;
use App\Models\OperationalTaskReminder;
use App\Models\OperationalTaskTransition;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 9.1: Assign and Complete Operational Tasks.
 */
class OperationalTaskTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;

    private Organization $otherBranch;

    protected function setUp(): void
    {
        parent::setUp();

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'TASK-HQ']);
        $this->branch = Organization::create(['name' => 'Branch A', 'type' => 'branch', 'identifier' => 'TASK-A', 'parent_id' => $hq->id]);
        $this->otherBranch = Organization::create(['name' => 'Branch B', 'type' => 'branch', 'identifier' => 'TASK-B', 'parent_id' => $hq->id]);
    }

    private function grant(User $user, array $actions): void
    {
        $role = Role::create(['name' => 'task_' . $user->id . '_' . substr(md5(implode(',', $actions)), 0, 6)]);
        foreach ($actions as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);
    }

    private function linkedMember(User $user): Member
    {
        return Member::create([
            'membership_id' => 'TASK-M-' . $user->id,
            'branch_id' => $user->branch_id ?? $this->branch->id,
            'user_id' => $user->id,
            'registration_channel' => 'web',
            'first_name' => 'Task',
            'last_name' => 'User' . $user->id,
            'consent_data_processing' => true,
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
        ]);
    }

    /**
     * @param  array<int, string>  $actions
     */
    private function staff(array $actions, ?Organization $branch = null): User
    {
        $user = $this->privilegedUser(['branch_id' => ($branch ?? $this->branch)->id]);
        $this->grant($user, $actions);
        $this->linkedMember($user);

        return $user;
    }

    public function test_create_task_visible_to_creator_assignee_and_supervisor(): void
    {
        $supervisor = $this->staff(['tasks.read', 'tasks.manage']);
        $assignee = $this->staff(['tasks.read', 'tasks.work']);
        $outsider = $this->staff(['tasks.read', 'tasks.work']);

        $created = $this->actingAsMfaVerified($supervisor)
            ->postJson('/api/tasks', [
                'branch_id' => $this->branch->id,
                'department' => 'operations',
                'title' => 'Prepare Sunday foyer',
                'description' => 'Set up welcome tables and signage.',
                'assignee_id' => $assignee->id,
                'priority' => 'high',
                'due_date' => now()->addDays(2)->toDateString(),
                'attachments' => [[
                    'filename' => 'checklist.pdf',
                    'mime_type' => 'application/pdf',
                    'size_bytes' => 1200,
                    'content_hash' => hash('sha256', 'checklist'),
                ]],
                'source_type' => 'App\\Models\\ChurchService',
                'source_id' => 42,
            ])
            ->assertCreated()
            ->assertJsonPath('data.department', 'operations')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.assignee_id', $assignee->id)
            ->json('data');

        $this->assertNotEmpty($created['reference']);
        $this->assertCount(1, $created['attachments']);

        $this->actingAsMfaVerified($assignee)
            ->getJson('/api/tasks')
            ->assertOk()
            ->assertJsonPath('data.0.id', $created['id']);

        $this->actingAsMfaVerified($supervisor)
            ->getJson('/api/tasks/' . $created['id'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Prepare Sunday foyer');

        $this->actingAsMfaVerified($outsider)
            ->getJson('/api/tasks/' . $created['id'])
            ->assertForbidden();

        $notification = MemberNotification::query()
            ->where('user_id', $assignee->id)
            ->where('type', 'task.assigned')
            ->first();
        $this->assertNotNull($notification);
        $this->assertArrayNotHasKey('description', $notification->metadata ?? []);
    }

    public function test_assignment_outside_creator_scope_is_rejected(): void
    {
        $creator = $this->staff(['tasks.read', 'tasks.manage']);
        $foreignAssignee = $this->staff(['tasks.read', 'tasks.work'], $this->otherBranch);

        $this->actingAsMfaVerified($creator)
            ->postJson('/api/tasks', [
                'branch_id' => $this->branch->id,
                'department' => 'facilities',
                'title' => 'Out of scope assign',
                'description' => 'Should be rejected.',
                'assignee_id' => $foreignAssignee->id,
                'due_date' => now()->addDay()->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['assignee_id']);
    }

    public function test_status_transitions_record_actor_timestamp_and_completion_evidence(): void
    {
        $manager = $this->staff(['tasks.read', 'tasks.manage', 'tasks.work']);
        $assignee = $this->staff(['tasks.read', 'tasks.work']);

        $id = $this->actingAsMfaVerified($manager)
            ->postJson('/api/tasks', [
                'branch_id' => $this->branch->id,
                'department' => 'communications',
                'title' => 'Publish newsletter draft',
                'description' => 'Draft and circulate for review.',
                'assignee_id' => $assignee->id,
                'due_date' => now()->addDays(3)->toDateString(),
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($assignee)
            ->postJson("/api/tasks/{$id}/status", [
                'status' => 'in_progress',
                'notes' => 'Started drafting.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');

        $this->actingAsMfaVerified($assignee)
            ->postJson("/api/tasks/{$id}/status", [
                'status' => 'pending',
                'notes' => 'Waiting on approval.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');

        $this->actingAsMfaVerified($assignee)
            ->postJson("/api/tasks/{$id}/status", [
                'status' => 'completed',
                'notes' => 'Published.',
            ])
            ->assertStatus(422);

        $completed = $this->actingAsMfaVerified($assignee)
            ->postJson("/api/tasks/{$id}/status", [
                'status' => 'completed',
                'notes' => 'Published.',
                'completion_evidence' => [[
                    'filename' => 'proof.png',
                    'mime_type' => 'image/png',
                    'size_bytes' => 800,
                    'content_hash' => hash('sha256', 'proof'),
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->json('data');

        $this->assertNotNull($completed['completed_at']);
        $this->assertCount(1, $completed['completion_evidence']);
        $this->assertDatabaseHas('operational_task_transitions', [
            'operational_task_id' => $id,
            'to_status' => 'completed',
            'actor_id' => $assignee->id,
        ]);

        $this->actingAsMfaVerified($assignee)
            ->postJson("/api/tasks/{$id}/status", ['status' => 'open'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_status');
    }

    public function test_overdue_processor_marks_status_and_avoids_duplicate_reminders(): void
    {
        Carbon::setTestNow('2026-08-20 10:00:00');

        $manager = $this->staff(['tasks.read', 'tasks.manage', 'tasks.process_overdue']);
        $assignee = $this->staff(['tasks.read', 'tasks.work']);

        $id = $this->actingAsMfaVerified($manager)
            ->postJson('/api/tasks', [
                'branch_id' => $this->branch->id,
                'department' => 'operations',
                'title' => 'Past due setup',
                'description' => 'Should become overdue.',
                'assignee_id' => $assignee->id,
                'due_date' => '2026-08-18',
            ])
            ->assertCreated()
            ->json('data.id');

        $first = $this->actingAsMfaVerified($manager)
            ->postJson('/api/tasks/process-overdue')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $first['marked_overdue']);
        $this->assertSame(1, $first['reminded']);
        $this->assertDatabaseHas('operational_tasks', [
            'id' => $id,
            'status' => 'overdue',
        ]);
        $this->assertSame(1, OperationalTaskReminder::query()->where('operational_task_id', $id)->count());
        $this->assertSame(1, MemberNotification::query()->where('type', 'task.overdue')->where('user_id', $assignee->id)->count());

        $second = $this->actingAsMfaVerified($manager)
            ->postJson('/api/tasks/process-overdue')
            ->assertOk()
            ->json('data');

        $this->assertSame(0, $second['marked_overdue']);
        $this->assertSame(1, $second['skipped']);
        $this->assertSame(0, $second['reminded']);
        $this->assertSame(1, OperationalTaskReminder::query()->where('operational_task_id', $id)->count());

        Carbon::setTestNow('2026-08-21 12:00:00');

        $third = $this->actingAsMfaVerified($manager)
            ->postJson('/api/tasks/process-overdue')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $third['reminded']);
        $this->assertSame(2, OperationalTaskReminder::query()->where('operational_task_id', $id)->count());

        Carbon::setTestNow();
    }

    public function test_manual_overdue_status_is_rejected(): void
    {
        $manager = $this->staff(['tasks.read', 'tasks.manage', 'tasks.work']);
        $assignee = $this->staff(['tasks.read', 'tasks.work']);

        $id = $this->actingAsMfaVerified($manager)
            ->postJson('/api/tasks', [
                'branch_id' => $this->branch->id,
                'department' => 'other',
                'title' => 'Cannot force overdue',
                'description' => 'Manual overdue blocked.',
                'assignee_id' => $assignee->id,
                'due_date' => now()->addDay()->toDateString(),
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($manager)
            ->postJson("/api/tasks/{$id}/status", ['status' => 'overdue'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_transition');
    }
}
