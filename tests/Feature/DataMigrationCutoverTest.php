<?php

namespace Tests\Feature;

use App\Models\DataMigrationSource;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Story 15.2: rehearse and execute migration cutover.
 */
class DataMigrationCutoverTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'CUT-HQ']);
        $this->branch = Organization::create([
            'name' => 'Branch A',
            'type' => 'branch',
            'identifier' => 'CUT-A',
            'parent_id' => $hq->id,
        ]);
    }

    /**
     * @param  list<string>  $actions
     */
    private function grant(User $user, array $actions): void
    {
        $role = Role::create(['name' => 'cut_' . $user->id . '_' . substr(md5(implode(',', $actions)), 0, 6)]);
        foreach ($actions as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);
    }

    private function migrationLead(): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $this->grant($user, ['migration.read', 'migration.manage', 'migration.execute', 'migration.approve']);

        return $user;
    }

    /**
     * @return array{content_base64: string}
     */
    private function csvPayload(string $csv): array
    {
        return ['content_base64' => base64_encode($csv)];
    }

    /**
     * @return array{mapping_id: int, plan_id: int}
     */
    private function approvedPlan(User $admin, string $csv, array $planPayload = []): array
    {
        $sourceId = $this->actingAsMfaVerified($admin)
            ->postJson('/api/data-migrations/sources', array_merge($this->csvPayload($csv), [
                'name' => 'Legacy members CSV',
                'source_type' => 'csv',
                'filename' => 'members.csv',
                'branch_id' => $this->branch->id,
            ]))
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/data-migrations/sources/{$sourceId}/profile")
            ->assertOk();

        $mappingId = $this->actingAsMfaVerified($admin)
            ->postJson("/api/data-migrations/sources/{$sourceId}/mappings", [
                'name' => 'Member import map',
                'target_entity' => 'members',
                'branch_id' => $this->branch->id,
                'field_mappings' => [
                    'first_name' => 'first_name',
                    'last_name' => 'last_name',
                    'membership_id' => 'membership_id',
                    'email' => 'email',
                ],
                'defaults' => [
                    'branch_id' => (string) $this->branch->id,
                ],
                'transformations' => [
                    'email' => 'lowercase',
                ],
                'duplicate_rules' => [
                    'match_on' => ['membership_id'],
                    'strategy' => 'review',
                ],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/data-migrations/mappings/{$mappingId}/validate-run")
            ->assertCreated();

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/data-migrations/mappings/{$mappingId}/approve")
            ->assertOk();

        $planId = $this->actingAsMfaVerified($admin)
            ->postJson("/api/data-migrations/mappings/{$mappingId}/cutover-plans", array_merge([
                'backup_confirmed' => true,
                'maintenance_window_start' => now()->subHour()->toIso8601String(),
                'maintenance_window_end' => now()->addHour()->toIso8601String(),
            ], $planPayload))
            ->assertCreated()
            ->json('data.id');

        return ['mapping_id' => $mappingId, 'plan_id' => $planId];
    }

    public function test_test_migration_imports_idempotently_with_lineage_and_uat_sign_off(): void
    {
        $admin = $this->migrationLead();
        $csv = implode("\n", [
            'first_name,last_name,membership_id,email,branch_id',
            'Ada,Member,M-201,ada@example.com,' . $this->branch->id,
            'Bob,Member,M-202,bob@example.com,' . $this->branch->id,
        ]);

        $plan = $this->approvedPlan($admin, $csv);
        $planId = $plan['plan_id'];

        $firstRun = $this->actingAsMfaVerified($admin)
            ->postJson("/api/data-migrations/cutover-plans/{$planId}/test-run")
            ->assertCreated()
            ->json('data');

        $this->assertSame('completed', $firstRun['status']);
        $this->assertSame(2, $firstRun['summary']['imported']);
        $this->assertSame(2, Member::query()->count());
        $this->assertDatabaseHas('data_migration_import_records', [
            'status' => 'imported',
            'target_type' => 'members',
        ]);

        $secondRun = $this->actingAsMfaVerified($admin)
            ->postJson("/api/data-migrations/cutover-plans/{$planId}/test-run", [
                'idempotency_key' => 'test-repeat-' . $planId,
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame('completed', $secondRun['status']);
        $this->assertSame(0, $secondRun['summary']['imported']);
        $this->assertSame(2, $secondRun['summary']['skipped']);
        $this->assertSame(2, Member::query()->count());
        $this->assertNotNull($secondRun['performance']['duration_ms']);

        $signedOff = $this->actingAsMfaVerified($admin)
            ->postJson("/api/data-migrations/cutover-plans/{$planId}/uat-sign-off")
            ->assertOk()
            ->json('data');

        $this->assertSame('uat_signed_off', $signedOff['status']);
        $this->assertNotNull($signedOff['uat_signed_off_at']);
        $this->assertDatabaseHas('audit_events', ['action' => 'data_migration.uat_signed_off']);
    }

    public function test_production_cutover_rolls_back_when_acceptance_thresholds_fail(): void
    {
        $admin = $this->migrationLead();
        $csv = implode("\n", [
            'first_name,last_name,membership_id,email,branch_id',
            'Good,Member,M-301,good@example.com,' . $this->branch->id,
            'Bad,Member,M-302,not-an-email,' . $this->branch->id,
        ]);

        $plan = $this->approvedPlan($admin, $csv, [
            'acceptance_thresholds' => [
                'max_error_rate' => 0,
                'min_success_rate' => 1,
            ],
        ]);
        $planId = $plan['plan_id'];

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/data-migrations/cutover-plans/{$planId}/test-run")
            ->assertCreated();

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/data-migrations/cutover-plans/{$planId}/uat-sign-off")
            ->assertOk();

        $productionRun = $this->actingAsMfaVerified($admin)
            ->postJson("/api/data-migrations/cutover-plans/{$planId}/execute-production")
            ->assertCreated()
            ->json('data');

        $this->assertSame('rolled_back', $productionRun['status']);
        $this->assertSame(0, Member::query()->count());
        $this->assertDatabaseHas('data_migration_runs', [
            'id' => $productionRun['id'],
            'status' => 'rolled_back',
        ]);
        $this->assertDatabaseHas('audit_events', ['action' => 'data_migration.cutover_plan_created']);
    }
}
