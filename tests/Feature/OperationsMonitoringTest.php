<?php

namespace Tests\Feature;

use App\Models\ApiAccessEvent;
use App\Models\OperationsAlert;
use App\Models\OperationsSnapshot;
use App\Models\Organization;
use App\Models\RecoveryExercise;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 15.6: monitor operations, backups, and recovery.
 */
class OperationsMonitoringTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'OPS-HQ']);
        $this->branch = Organization::create([
            'name' => 'Branch A',
            'type' => 'branch',
            'identifier' => 'OPS-A',
            'parent_id' => $hq->id,
        ]);
    }

    /**
     * @param  list<string>  $actions
     */
    private function grant(User $user, array $actions): void
    {
        $role = Role::create(['name' => 'ops_' . $user->id . '_' . substr(md5(implode(',', $actions)), 0, 6)]);
        foreach ($actions as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);
    }

    private function operator(): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $this->grant($user, ['operations.read', 'operations.manage']);

        return $user;
    }

    public function test_operator_views_telemetry_and_receives_deduplicated_threshold_alert_with_correlation(): void
    {
        $operator = $this->operator();

        for ($i = 0; $i < 8; $i++) {
            ApiAccessEvent::create([
                'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
                'method' => 'GET',
                'path' => '/api/v1/members',
                'status_code' => 401,
                'outcome' => 'denied',
                'error_code' => 'unauthenticated',
                'created_at' => now(),
            ]);
        }

        ApiAccessEvent::create([
            'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
            'method' => 'GET',
            'path' => '/api/v1/members',
            'status_code' => 200,
            'outcome' => 'allowed',
            'created_at' => now(),
        ]);

        $collect = $this->actingAsMfaVerified($operator)
            ->postJson('/api/operations-monitoring/collect-telemetry')
            ->assertOk()
            ->json('data');

        $this->assertGreaterThanOrEqual(10, $collect['snapshots']);
        $this->assertGreaterThanOrEqual(1, $collect['alerts']);

        $dashboard = $this->actingAsMfaVerified($operator)
            ->getJson('/api/operations-monitoring/dashboard')
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($dashboard['components']);
        $this->assertNotEmpty($dashboard['open_alerts']);

        $snapshot = OperationsSnapshot::query()->where('component', 'api')->latest('id')->first();
        $this->assertNotNull($snapshot?->correlation_id);
        $this->assertStringNotContainsString('password', json_encode($snapshot->metrics ?? []));

        $firstAlertCount = OperationsAlert::query()->count();

        $this->actingAsMfaVerified($operator)
            ->postJson('/api/operations-monitoring/collect-telemetry')
            ->assertOk();

        $this->assertSame($firstAlertCount, OperationsAlert::query()->count());

        $alert = OperationsAlert::query()->firstOrFail();
        $acknowledged = $this->actingAsMfaVerified($operator)
            ->postJson("/api/operations-monitoring/alerts/{$alert->id}/acknowledge")
            ->assertOk()
            ->json('data');

        $this->assertSame('acknowledged', $acknowledged['status']);
        $this->assertNotNull($acknowledged['time_to_acknowledge_minutes']);
        $this->assertNotNull($acknowledged['runbook']);
    }

    public function test_failed_backup_triggers_alert_and_recovery_exercise_records_rpo_rto_targets(): void
    {
        $operator = $this->operator();

        $this->actingAsMfaVerified($operator)
            ->postJson('/api/operations-monitoring/backups', [
                'run_type' => 'incremental',
                'status' => 'failed',
                'failure_reason' => 'Off-site replication unavailable',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('operations_alerts', [
            'component' => 'backups',
            'metric' => 'backup_failure',
            'severity' => 'critical',
        ]);

        $exercise = $this->actingAsMfaVerified($operator)
            ->postJson('/api/operations-monitoring/recovery-exercises', [
                'exercise_type' => 'disaster_recovery',
                'measured_rpo_minutes' => 45,
                'measured_rto_minutes' => 180,
                'verification_evidence' => [
                    'database_restored' => true,
                    'api_smoke_tests_passed' => true,
                    'email' => 'admin@example.com',
                ],
                'findings' => ['Minor queue lag during restore'],
                'corrective_actions' => ['Increase worker capacity before next exercise'],
            ])
            ->assertCreated()
            ->json('data');

        $this->assertTrue($exercise['rpo_met']);
        $this->assertTrue($exercise['rto_met']);

        $model = RecoveryExercise::query()->firstOrFail();
        $this->assertSame('[REDACTED]', $model->verification_evidence['email'] ?? null);
        $this->assertTrue($model->verification_evidence['database_restored']);

        $this->assertDatabaseHas('audit_events', ['action' => 'operations.recovery_exercise_completed']);
    }
}
