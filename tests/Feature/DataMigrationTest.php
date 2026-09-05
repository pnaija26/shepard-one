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
 * Story 15.1: map, clean, and validate legacy migration data.
 */
class DataMigrationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'MIG-HQ']);
        $this->branch = Organization::create([
            'name' => 'Branch A',
            'type' => 'branch',
            'identifier' => 'MIG-A',
            'parent_id' => $hq->id,
        ]);
    }

    /**
     * @param  list<string>  $actions
     */
    private function grant(User $user, array $actions): void
    {
        $role = Role::create(['name' => 'mig_' . $user->id . '_' . substr(md5(implode(',', $actions)), 0, 6)]);
        foreach ($actions as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);
    }

    private function admin(): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $this->grant($user, ['migration.read', 'migration.manage']);

        return $user;
    }

    /**
     * @return array{content_base64: string}
     */
    private function csvPayload(): array
    {
        $csv = implode("\n", [
            'first_name,last_name,membership_id,email,branch_id',
            'Ada,Member,M-001,ada@example.com,' . $this->branch->id,
            'Bob,Member,M-001,invalid-email,' . $this->branch->id,
        ]);

        return ['content_base64' => base64_encode($csv)];
    }

    public function test_admin_profiles_csv_source_without_importing_production_records(): void
    {
        $admin = $this->admin();
        $memberCountBefore = Member::query()->count();

        $source = $this->actingAsMfaVerified($admin)
            ->postJson('/api/data-migrations/sources', array_merge($this->csvPayload(), [
                'name' => 'Legacy members CSV',
                'source_type' => 'csv',
                'filename' => 'members.csv',
                'branch_id' => $this->branch->id,
            ]))
            ->assertCreated()
            ->json('data');

        $model = DataMigrationSource::query()->findOrFail($source['id']);
        Storage::disk('local')->assertExists((string) $model->storage_path);
        $this->assertStringStartsWith('data-migrations/', (string) $model->storage_path);

        $profile = $this->actingAsMfaVerified($admin)
            ->postJson("/api/data-migrations/sources/{$source['id']}/profile")
            ->assertOk()
            ->json('data');

        $this->assertSame(2, $profile['summary']['row_count']);
        $this->assertNotEmpty($profile['sensitive_fields']);
        $this->assertNotEmpty($profile['duplicate_keys']);
        $this->assertSame($memberCountBefore, Member::query()->count());
    }

    public function test_blocked_mapping_and_validation_reports_outcomes_without_production_changes(): void
    {
        $admin = $this->admin();

        $sourceId = $this->actingAsMfaVerified($admin)
            ->postJson('/api/data-migrations/sources', array_merge($this->csvPayload(), [
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

        $blocked = $this->actingAsMfaVerified($admin)
            ->postJson("/api/data-migrations/sources/{$sourceId}/mappings", [
                'name' => 'Member import map',
                'target_entity' => 'members',
                'branch_id' => $this->branch->id,
                'field_mappings' => [
                    'first_name' => 'first_name',
                    'last_name' => 'last_name',
                ],
                'duplicate_rules' => [
                    'match_on' => ['membership_id', 'email'],
                    'strategy' => 'reject',
                ],
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame('blocked', $blocked['status']);

        $mappingId = $this->actingAsMfaVerified($admin)
            ->postJson("/api/data-migrations/sources/{$sourceId}/mappings", [
                'name' => 'Member import map v2',
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
            ->postJson("/api/data-migrations/mappings/{$mappingId}/validate")
            ->assertOk()
            ->assertJsonPath('data.valid', true);

        $run = $this->actingAsMfaVerified($admin)
            ->postJson("/api/data-migrations/mappings/{$mappingId}/validate-run")
            ->assertCreated()
            ->json('data');

        $this->assertGreaterThan(0, $run['summary']['rejected'] + $run['summary']['duplicate_review']);
        $this->assertSame(0, Member::query()->count());
        $this->assertDatabaseHas('audit_events', [
            'action' => 'data_migration.validation_completed',
        ]);
    }
}
