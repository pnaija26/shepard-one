<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Models\WorkflowVersion;
use App\Models\WorkflowVersionTest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 9.2: Design and Publish a Workflow.
 */
class WorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'WF-HQ']);
        $this->branch = Organization::create(['name' => 'Branch A', 'type' => 'branch', 'identifier' => 'WF-A', 'parent_id' => $hq->id]);
    }

    private function grant(User $user, array $actions): void
    {
        $role = Role::create(['name' => 'wf_' . $user->id . '_' . substr(md5(implode(',', $actions)), 0, 6)]);
        foreach ($actions as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);
    }

    private function admin(array $extra = []): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $this->grant($user, array_merge([
            'workflows.read',
            'workflows.manage',
            'workflows.publish',
            'tasks.work',
            'tasks.manage',
        ], $extra));

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function validDefinition(array $overrides = []): array
    {
        $base = [
            'trigger' => ['type' => 'manual'],
            'conditions' => [
                ['field' => 'amount', 'operator' => 'gte', 'value' => 0],
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
            ],
            'assignments' => [
                ['state' => 'review', 'permission' => 'tasks.work'],
            ],
            'approvals' => [
                ['state' => 'review', 'quorum' => 1],
            ],
            'rejection' => ['target_state' => 'rejected'],
            'escalation' => [
                'after_hours' => 48,
                'to_permission' => 'tasks.manage',
                'max_loops' => 3,
            ],
            'notifications' => [
                ['state' => 'approved', 'channel' => 'in_app', 'template' => 'workflow.approved'],
            ],
            'deadlines' => [
                ['state' => 'review', 'hours' => 24],
            ],
            'reminders' => [
                ['state' => 'review', 'every_hours' => 12, 'max_count' => 3],
            ],
            'end_states' => ['approved', 'rejected'],
        ];

        return array_replace_recursive($base, $overrides);
    }

    public function test_admin_can_create_visualize_validate_test_and_publish_workflow(): void
    {
        $admin = $this->admin();

        $created = $this->actingAsMfaVerified($admin)
            ->postJson('/api/workflows', [
                'name' => 'Welfare handoff',
                'branch_id' => $this->branch->id,
                'definition' => $this->validDefinition(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.draft_version', 1)
            ->json('data');

        $id = $created['id'];

        $viz = $this->actingAsMfaVerified($admin)
            ->getJson("/api/workflows/{$id}/visualize")
            ->assertOk()
            ->json('data');

        $this->assertCount(4, $viz['nodes']);
        $this->assertCount(3, $viz['edges']);
        $this->assertTrue($viz['validation']['valid']);

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/workflows/{$id}/validate")
            ->assertOk()
            ->assertJsonPath('data.validation.valid', true);

        $test = $this->actingAsMfaVerified($admin)
            ->postJson("/api/workflows/{$id}/test", [
                'sample' => ['amount' => 50, '_next_action' => 'approve'],
            ])
            ->assertOk()
            ->assertJsonPath('data.passed', true)
            ->json('data');

        $this->assertDatabaseHas('workflow_version_tests', [
            'id' => $test['test_id'],
            'passed' => 1,
        ]);

        $published = $this->actingAsMfaVerified($admin)
            ->postJson("/api/workflows/{$id}/publish", [
                'migration_policy' => 'keep_locked',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.current_version', 1)
            ->json('data');

        $this->assertSame('published', collect($published['versions'])->firstWhere('version', 1)['status']);
        $this->assertNotEmpty(collect($published['versions'])->firstWhere('version', 1)['tests']);
    }

    public function test_invalid_definitions_are_blocked(): void
    {
        $admin = $this->admin();

        // Unreachable state
        $unreachable = $this->validDefinition();
        $unreachable['states'] = [
            ['key' => 'start', 'type' => 'start', 'label' => 'Start'],
            ['key' => 'review', 'type' => 'approval', 'label' => 'Review'],
            ['key' => 'orphan', 'type' => 'action', 'label' => 'Orphan'],
            ['key' => 'approved', 'type' => 'end', 'label' => 'Approved'],
        ];
        $unreachable['transitions'] = [
            ['from' => 'start', 'to' => 'review', 'action' => 'submit'],
            ['from' => 'review', 'to' => 'approved', 'on' => 'approve'],
        ];
        $unreachable['assignments'] = [
            ['state' => 'review', 'permission' => 'tasks.work'],
            ['state' => 'orphan', 'permission' => 'tasks.work'],
        ];
        $unreachable['end_states'] = ['approved'];
        $unreachable['rejection'] = null;

        $this->actingAsMfaVerified($admin)
            ->postJson('/api/workflows', [
                'name' => 'Unreachable',
                'definition' => $unreachable,
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_definition');

        // Missing actor on approval
        $missingActor = $this->validDefinition();
        $missingActor['assignments'] = [];

        $this->actingAsMfaVerified($admin)
            ->postJson('/api/workflows', [
                'name' => 'Missing actor',
                'definition' => $missingActor,
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_definition');

        // Privilege escalation via unknown permission
        $escalation = $this->validDefinition();
        $escalation['assignments'] = [
            ['state' => 'review', 'permission' => 'superadmin.all'],
        ];

        $this->actingAsMfaVerified($admin)
            ->postJson('/api/workflows', [
                'name' => 'Escalation attempt',
                'definition' => $escalation,
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_definition');

        // Loop without limit
        $loop = [
            'trigger' => ['type' => 'manual'],
            'conditions' => [],
            'states' => [
                ['key' => 'start', 'type' => 'start', 'label' => 'Start'],
                ['key' => 'a', 'type' => 'action', 'label' => 'A'],
                ['key' => 'b', 'type' => 'action', 'label' => 'B'],
            ],
            'transitions' => [
                ['from' => 'start', 'to' => 'a', 'action' => 'go'],
                ['from' => 'a', 'to' => 'b', 'action' => 'go'],
                ['from' => 'b', 'to' => 'a', 'action' => 'loop'],
            ],
            'assignments' => [
                ['state' => 'a', 'permission' => 'tasks.work'],
                ['state' => 'b', 'permission' => 'tasks.work'],
            ],
            'approvals' => [],
            'rejection' => null,
            'escalation' => null,
            'notifications' => [],
            'deadlines' => [],
            'reminders' => [],
            'end_states' => [],
        ];

        $this->actingAsMfaVerified($admin)
            ->postJson('/api/workflows', [
                'name' => 'Unbounded loop',
                'definition' => $loop,
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_definition');
    }

    public function test_publish_applies_migration_policy_and_keeps_prior_versions_inspectable(): void
    {
        $admin = $this->admin();

        $id = $this->actingAsMfaVerified($admin)
            ->postJson('/api/workflows', [
                'name' => 'Migratable flow',
                'branch_id' => $this->branch->id,
                'migration_policy' => 'keep_locked',
                'definition' => $this->validDefinition(),
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/workflows/{$id}/publish")
            ->assertOk()
            ->assertJsonPath('data.current_version', 1);

        $v1 = WorkflowVersion::query()->where('workflow_id', $id)->where('version', 1)->firstOrFail();

        $open = WorkflowInstance::create([
            'workflow_id' => $id,
            'workflow_version_id' => $v1->id,
            'workflow_version' => 1,
            'branch_id' => $this->branch->id,
            'status' => WorkflowInstance::STATUS_PENDING,
            'current_state' => 'review',
            'created_by' => $admin->id,
        ]);

        $locked = WorkflowInstance::create([
            'workflow_id' => $id,
            'workflow_version_id' => $v1->id,
            'workflow_version' => 1,
            'branch_id' => $this->branch->id,
            'status' => WorkflowInstance::STATUS_COMPLETED,
            'current_state' => 'approved',
            'created_by' => $admin->id,
        ]);

        // New draft after publish
        $this->actingAsMfaVerified($admin)
            ->putJson("/api/workflows/{$id}/draft", [
                'definition' => $this->validDefinition([
                    'deadlines' => [
                        ['state' => 'review', 'hours' => 12],
                    ],
                ]),
                'migration_policy' => 'migrate_pending',
            ])
            ->assertOk()
            ->assertJsonPath('data.draft_version', 2)
            ->assertJsonPath('data.draft_status', 'draft');

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/workflows/{$id}/test", [
                'sample' => ['amount' => 10, '_next_action' => 'approve'],
            ])
            ->assertOk();

        $published = $this->actingAsMfaVerified($admin)
            ->postJson("/api/workflows/{$id}/publish", [
                'migration_policy' => 'migrate_pending',
            ])
            ->assertOk()
            ->assertJsonPath('data.current_version', 2)
            ->json('data');

        $open->refresh();
        $locked->refresh();

        $this->assertSame(2, $open->workflow_version);
        $this->assertNotNull($open->migrated_at);
        $this->assertSame(1, $open->migrated_from_version);

        $this->assertSame(1, $locked->workflow_version);
        $this->assertNull($locked->migrated_at);

        $versions = collect($published['versions']);
        $this->assertSame('published', $versions->firstWhere('version', 1)['status']);
        $this->assertSame('published', $versions->firstWhere('version', 2)['status']);
        $this->assertNotEmpty($versions->firstWhere('version', 2)['tests']);

        $this->assertSame(2, WorkflowVersion::query()->where('workflow_id', $id)->count());
        $this->assertSame(1, WorkflowVersionTest::query()->where('workflow_version_id', $versions->firstWhere('version', 2)['id'] ?? 0)->count());
    }
}
