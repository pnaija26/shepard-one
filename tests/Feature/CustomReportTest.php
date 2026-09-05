<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 13.3: build and run custom reports without code.
 */
class CustomReportTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'CR-HQ']);
        $this->branch = Organization::create([
            'name' => 'Branch A',
            'type' => 'branch',
            'identifier' => 'CR-A',
            'parent_id' => $hq->id,
        ]);
    }

    /**
     * @param  list<string>  $actions
     */
    private function grant(User $user, array $actions): void
    {
        $role = Role::create(['name' => 'cr_' . $user->id . '_' . substr(md5(implode(',', $actions)), 0, 6)]);
        foreach ($actions as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);
    }

    private function designer(array $extra = []): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $this->grant($user, array_merge([
            'reports.custom.read',
            'reports.custom.manage',
            'reports.custom.publish',
            'members.read',
        ], $extra));

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function validDefinition(): array
    {
        return [
            'data_source' => 'members',
            'fields' => ['lifecycle_stage'],
            'filters' => [
                ['type' => 'membership_stage', 'value' => 'member'],
            ],
            'group_by' => ['lifecycle_stage'],
            'sort' => [
                ['field' => 'lifecycle_stage', 'direction' => 'asc'],
            ],
            'calculations' => [
                ['type' => 'count', 'alias' => 'total'],
            ],
            'joins' => [],
        ];
    }

    public function test_designer_validates_blocks_unsafe_definition_and_can_preview_and_publish(): void
    {
        $designer = $this->designer();

        Member::create([
            'membership_id' => 'CR-M-001',
            'branch_id' => $this->branch->id,
            'registration_channel' => 'web',
            'first_name' => 'Ada',
            'last_name' => 'Member',
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
            'consent_data_processing' => true,
        ]);

        $created = $this->actingAsMfaVerified($designer)
            ->postJson('/api/custom-reports', [
                'name' => 'Members by stage',
                'branch_id' => $this->branch->id,
                'definition' => $this->validDefinition(),
            ])
            ->assertCreated()
            ->json('data');

        $id = $created['id'];

        $this->actingAsMfaVerified($designer)
            ->putJson("/api/custom-reports/{$id}/draft", [
                'definition' => array_merge($this->validDefinition(), [
                    'fields' => ['membership_id', 'email', 'lifecycle_stage'],
                    'joins' => [['source' => 'visitors']],
                    'calculations' => [
                        ['type' => 'sum', 'field' => 'first_name', 'alias' => 'bad_sum'],
                    ],
                ]),
            ])
            ->assertOk();

        $invalid = $this->actingAsMfaVerified($designer)
            ->postJson("/api/custom-reports/{$id}/validate")
            ->assertOk()
            ->json('data');

        $this->assertFalse($invalid['valid']);
        $messages = collect($invalid['errors'])->pluck('message')->implode(' ');
        $this->assertStringContainsString('joins', strtolower($messages));
        $this->assertStringContainsString('email', strtolower($messages));
        $this->assertStringContainsString('numeric', strtolower($messages));

        $this->actingAsMfaVerified($designer)
            ->postJson("/api/custom-reports/{$id}/publish")
            ->assertStatus(422);

        $this->actingAsMfaVerified($designer)
            ->putJson("/api/custom-reports/{$id}/draft", [
                'definition' => $this->validDefinition(),
            ])
            ->assertOk();

        $preview = $this->actingAsMfaVerified($designer)
            ->postJson("/api/custom-reports/{$id}/preview")
            ->assertOk()
            ->json('data');

        $this->assertSame('ready', $preview['result']['state']);
        $this->assertGreaterThanOrEqual(1, $preview['result']['row_count']);

        $published = $this->actingAsMfaVerified($designer)
            ->postJson("/api/custom-reports/{$id}/publish")
            ->assertOk()
            ->json('data');

        $this->assertSame('published', $published['status']);
        $this->assertSame(1, $published['current_version']);
    }

    public function test_runner_permissions_are_reapplied_and_definition_does_not_grant_access(): void
    {
        $designer = $this->designer(['members.sensitive']);
        $runner = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $this->grant($runner, ['reports.custom.read', 'members.read']);

        Member::create([
            'membership_id' => 'CR-M-002',
            'branch_id' => $this->branch->id,
            'registration_channel' => 'web',
            'first_name' => 'Ben',
            'last_name' => 'Member',
            'email' => 'ben@example.com',
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
            'consent_data_processing' => true,
        ]);

        $definition = array_merge($this->validDefinition(), [
            'fields' => ['membership_id', 'email', 'lifecycle_stage'],
            'group_by' => [],
            'calculations' => [],
            'sort' => [
                ['field' => 'membership_id', 'direction' => 'asc'],
            ],
        ]);

        $created = $this->actingAsMfaVerified($designer)
            ->postJson('/api/custom-reports', [
                'name' => 'Member emails',
                'branch_id' => $this->branch->id,
                'definition' => $definition,
            ])
            ->assertCreated()
            ->json('data');

        $id = $created['id'];

        $this->actingAsMfaVerified($designer)
            ->postJson("/api/custom-reports/{$id}/publish")
            ->assertOk();

        $denied = $this->actingAsMfaVerified($runner)
            ->getJson("/api/custom-reports/{$id}/run")
            ->assertStatus(403)
            ->json();

        $this->assertSame('permission_mismatch', $denied['code']);
        $this->assertFalse($denied['details']['valid']);

        $privilegedRunner = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $this->grant($privilegedRunner, ['reports.custom.read', 'members.read', 'members.sensitive']);

        $allowed = $this->actingAsMfaVerified($privilegedRunner)
            ->getJson("/api/custom-reports/{$id}/run")
            ->assertOk()
            ->json('data');

        $this->assertSame('ready', $allowed['state']);
        $this->assertArrayHasKey('email', $allowed['rows'][0] ?? []);
    }
}
