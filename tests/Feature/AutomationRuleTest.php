<?php

namespace Tests\Feature;

use App\Models\AutomationRule;
use App\Models\AutomationRuleEvaluation;
use App\Models\AutomationRuleExecution;
use App\Models\OperationalTask;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 9.4: Configure Event-Driven Automation Rules.
 */
class AutomationRuleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'AUTO-HQ']);
        $this->branch = Organization::create(['name' => 'Branch A', 'type' => 'branch', 'identifier' => 'AUTO-A', 'parent_id' => $hq->id]);
    }

    private function grant(User $user, array $actions): void
    {
        $role = Role::create(['name' => 'auto_' . $user->id . '_' . substr(md5(implode(',', $actions)), 0, 6)]);
        foreach ($actions as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);
    }

    private function admin(array $extra = []): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $this->grant($user, array_merge([
            'automation.rules.read',
            'automation.rules.manage',
            'automation.rules.publish',
            'automation.rules.evaluate',
        ], $extra));

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function rulePayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Attendance follow-up task',
            'branch_id' => $this->branch->id,
            'event_type' => 'attendance.exception_detected',
            'conditions' => [
                ['field' => 'consecutive_days', 'operator' => 'gte', 'value' => 3],
            ],
            'action_type' => 'create_task',
            'action_params' => [
                'title' => 'Follow up on attendance exception',
                'department' => 'pastoral',
                'priority' => 'high',
                'assignee_id' => null,
            ],
            'scope_type' => 'branch',
            'priority' => 80,
            'stop_behavior' => 'stop_on_match',
            'failure_policy' => 'retry',
            'requires_consent' => false,
        ], $overrides);
    }

    public function test_admin_can_create_simulate_and_publish_rule(): void
    {
        $admin = $this->admin();
        $payload = $this->rulePayload();
        $payload['action_params']['assignee_id'] = $admin->id;

        $created = $this->actingAsMfaVerified($admin)
            ->postJson('/api/automation-rules', $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.event_type', 'attendance.exception_detected')
            ->json('data');

        $id = $created['id'];

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/automation-rules/{$id}/validate")
            ->assertOk()
            ->assertJsonPath('data.validation.valid', true);

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/automation-rules/{$id}/simulate", [
                'sample' => [
                    'consecutive_days' => 4,
                    'branch_id' => $this->branch->id,
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.passed', true)
            ->assertJsonPath('data.result.would_execute', true);

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/automation-rules/{$id}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.current_version', 1);

        $this->assertDatabaseHas('automation_rule_versions', [
            'automation_rule_id' => $id,
            'version' => 1,
            'status' => 'published',
        ]);
    }

    public function test_published_rule_executes_once_with_traceable_references(): void
    {
        $admin = $this->admin();
        $payload = $this->rulePayload();
        $payload['action_params']['assignee_id'] = $admin->id;

        $id = $this->actingAsMfaVerified($admin)
            ->postJson('/api/automation-rules', $payload)
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/automation-rules/{$id}/publish")
            ->assertOk();

        $eventKey = 'exc-2026-001';

        $first = $this->actingAsMfaVerified($admin)
            ->postJson('/api/automation-rules/evaluate', [
                'event_type' => 'attendance.exception_detected',
                'payload' => [
                    'event_key' => $eventKey,
                    'branch_id' => $this->branch->id,
                    'consecutive_days' => 5,
                    'assignee_id' => $admin->id,
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.executed', 1)
            ->json('data');

        $this->assertSame(1, OperationalTask::query()->count());
        $task = OperationalTask::query()->first();
        $this->assertSame(AutomationRule::class, $task->source_type);
        $this->assertSame($id, $task->source_id);

        $this->assertDatabaseHas('automation_rule_executions', [
            'automation_rule_id' => $id,
            'event_key' => $eventKey,
            'status' => 'completed',
        ]);

        $this->assertDatabaseHas('automation_rule_evaluations', [
            'automation_rule_id' => $id,
            'event_key' => $eventKey,
            'outcome' => AutomationRuleEvaluation::OUTCOME_EXECUTED,
            'action_reference_id' => $task->id,
        ]);

        $second = $this->actingAsMfaVerified($admin)
            ->postJson('/api/automation-rules/evaluate', [
                'event_type' => 'attendance.exception_detected',
                'payload' => [
                    'event_key' => $eventKey,
                    'branch_id' => $this->branch->id,
                    'consecutive_days' => 5,
                ],
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame(0, $second['executed']);
        $this->assertGreaterThanOrEqual(1, $second['skipped']);
        $this->assertSame(1, OperationalTask::query()->count());
        $this->assertSame(1, AutomationRuleExecution::query()->where('automation_rule_id', $id)->count());
        $this->assertNotEmpty($first['results']);
    }

    public function test_disabled_outside_period_and_missing_consent_skip_without_sensitive_leakage(): void
    {
        $admin = $this->admin();

        $disabledId = $this->actingAsMfaVerified($admin)
            ->postJson('/api/automation-rules', $this->rulePayload([
                'name' => 'Disabled rule',
                'action_params' => [
                    'title' => 'Should not run',
                    'department' => 'pastoral',
                    'priority' => 'normal',
                    'assignee_id' => $admin->id,
                ],
                'priority' => 90,
                'stop_behavior' => 'continue',
            ]))
            ->json('data.id');
        $this->actingAsMfaVerified($admin)->postJson("/api/automation-rules/{$disabledId}/publish")->assertOk();
        $this->actingAsMfaVerified($admin)
            ->postJson("/api/automation-rules/{$disabledId}/enabled", ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.enabled', false);

        $periodId = $this->actingAsMfaVerified($admin)
            ->postJson('/api/automation-rules', $this->rulePayload([
                'name' => 'Future rule',
                'effective_from' => Carbon::now()->addWeek()->toDateTimeString(),
                'action_params' => [
                    'title' => 'Future task',
                    'department' => 'pastoral',
                    'priority' => 'normal',
                    'assignee_id' => $admin->id,
                ],
                'priority' => 70,
                'stop_behavior' => 'continue',
            ]))
            ->json('data.id');
        $this->actingAsMfaVerified($admin)->postJson("/api/automation-rules/{$periodId}/publish")->assertOk();

        $consentId = $this->actingAsMfaVerified($admin)
            ->postJson('/api/automation-rules', $this->rulePayload([
                'name' => 'Consent rule',
                'requires_consent' => true,
                'action_params' => [
                    'title' => 'Consent task',
                    'department' => 'pastoral',
                    'priority' => 'normal',
                    'assignee_id' => $admin->id,
                ],
                'priority' => 60,
                'stop_behavior' => 'continue',
            ]))
            ->json('data.id');
        $this->actingAsMfaVerified($admin)->postJson("/api/automation-rules/{$consentId}/publish")->assertOk();

        $result = $this->actingAsMfaVerified($admin)
            ->postJson('/api/automation-rules/evaluate', [
                'event_type' => 'attendance.exception_detected',
                'payload' => [
                    'event_key' => 'skip-check-1',
                    'branch_id' => $this->branch->id,
                    'consecutive_days' => 4,
                    'request_body' => 'secret prayer text',
                    'national_id' => 'NIN-999',
                ],
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame(0, $result['executed']);
        $this->assertSame(0, OperationalTask::query()->count());

        $reasons = collect($result['results'])->pluck('reason')->all();
        $this->assertContains('outside_effective_period', $reasons);
        $this->assertContains('missing_consent', $reasons);

        $show = $this->actingAsMfaVerified($admin)
            ->getJson("/api/automation-rules/{$consentId}")
            ->assertOk()
            ->json('data');

        $evalJson = json_encode($show['evaluations'] ?? []);
        $this->assertStringNotContainsString('secret prayer text', $evalJson);
        $this->assertStringNotContainsString('NIN-999', $evalJson);
    }

    public function test_blocks_circular_chains_conflicts_and_excessive_fan_out(): void
    {
        $admin = $this->admin();

        $emitA = $this->actingAsMfaVerified($admin)
            ->postJson('/api/automation-rules', $this->rulePayload([
                'name' => 'Emit A to B',
                'event_type' => 'member.birthday',
                'conditions' => [],
                'action_type' => 'emit_event',
                'action_params' => ['event' => 'member.anniversary'],
                'priority' => 50,
                'stop_behavior' => 'stop_on_match',
            ]))
            ->json('data.id');
        $this->actingAsMfaVerified($admin)->postJson("/api/automation-rules/{$emitA}/publish")->assertOk();

        $emitB = $this->actingAsMfaVerified($admin)
            ->postJson('/api/automation-rules', $this->rulePayload([
                'name' => 'Emit B to A cycle',
                'event_type' => 'member.anniversary',
                'conditions' => [],
                'action_type' => 'emit_event',
                'action_params' => ['event' => 'member.birthday'],
                'priority' => 50,
                'stop_behavior' => 'stop_on_match',
            ]))
            ->json('data.id');

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/automation-rules/{$emitB}/publish")
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_definition');

        $conflictBase = $this->actingAsMfaVerified($admin)
            ->postJson('/api/automation-rules', $this->rulePayload([
                'name' => 'Conflict base',
                'event_type' => 'team.roster_published',
                'conditions' => [],
                'action_type' => 'log_only',
                'action_params' => [],
                'priority' => 40,
                'stop_behavior' => 'continue',
            ]))
            ->json('data.id');
        $this->actingAsMfaVerified($admin)->postJson("/api/automation-rules/{$conflictBase}/publish")->assertOk();

        $conflictPeer = $this->actingAsMfaVerified($admin)
            ->postJson('/api/automation-rules', $this->rulePayload([
                'name' => 'Conflict peer',
                'event_type' => 'team.roster_published',
                'conditions' => [],
                'action_type' => 'log_only',
                'action_params' => [],
                'priority' => 40,
                'stop_behavior' => 'continue',
            ]))
            ->json('data.id');

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/automation-rules/{$conflictPeer}/publish")
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_definition');

        config(['automation_rules.max_fan_out' => 2]);

        for ($i = 1; $i <= 2; $i++) {
            $fanId = $this->actingAsMfaVerified($admin)
                ->postJson('/api/automation-rules', $this->rulePayload([
                    'name' => "Fan out {$i}",
                    'event_type' => 'welfare.request_submitted',
                    'conditions' => [],
                    'action_type' => 'log_only',
                    'action_params' => [],
                    'priority' => 10 + $i,
                    'stop_behavior' => 'stop_on_match',
                ]))
                ->json('data.id');
            $this->actingAsMfaVerified($admin)->postJson("/api/automation-rules/{$fanId}/publish")->assertOk();
        }

        $overflow = $this->actingAsMfaVerified($admin)
            ->postJson('/api/automation-rules', $this->rulePayload([
                'name' => 'Fan overflow',
                'event_type' => 'welfare.request_submitted',
                'conditions' => [],
                'action_type' => 'log_only',
                'action_params' => [],
                'priority' => 5,
                'stop_behavior' => 'stop_on_match',
            ]))
            ->json('data.id');

        $blocked = $this->actingAsMfaVerified($admin)
            ->postJson("/api/automation-rules/{$overflow}/publish")
            ->assertStatus(422)
            ->json();

        $this->assertSame('invalid_definition', $blocked['code']);
        $codes = collect($blocked['details']['errors'] ?? [])->pluck('code')->all();
        $this->assertContains('excessive_fan_out', $codes);

        $this->actingAsMfaVerified($admin)
            ->postJson('/api/automation-rules', $this->rulePayload([
                'name' => 'Unsupported action attempt',
                'action_type' => 'delete_database',
                'action_params' => [],
            ]))
            ->assertStatus(422);
    }
}
