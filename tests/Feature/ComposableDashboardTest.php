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
 * Story 13.1: compose and run role-specific dashboards.
 */
class ComposableDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'CD-HQ']);
        $this->branch = Organization::create([
            'name' => 'Branch A',
            'type' => 'branch',
            'identifier' => 'CD-A',
            'parent_id' => $hq->id,
        ]);
    }

    /**
     * @param  list<string>  $actions
     */
    private function grant(User $user, array $actions): void
    {
        $role = Role::create(['name' => 'cd_' . $user->id . '_' . substr(md5(implode(',', $actions)), 0, 6)]);
        foreach ($actions as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);
    }

    private function composer(): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $this->grant($user, [
            'dashboards.composer.read',
            'dashboards.composer.manage',
            'dashboards.composer.publish',
            'members.read',
            'attendance.read',
            'payments.giving.reports',
        ]);

        return $user;
    }

    public function test_admin_can_compose_preview_and_publish_dashboard_with_validation_blocks(): void
    {
        $admin = $this->composer();
        $role = Role::create(['name' => 'branch_pastor']);

        $created = $this->actingAsMfaVerified($admin)
            ->postJson('/api/composable-dashboards', [
                'name' => 'Branch pastor view',
                'branch_id' => $this->branch->id,
                'role_ids' => [$role->id],
                'widgets' => [
                    [
                        'key' => 'members_kpi',
                        'type' => 'kpi',
                        'metric' => 'members',
                        'title' => 'Active members',
                        'visualization' => 'kpi',
                        'position' => 0,
                        'span' => 1,
                    ],
                ],
            ])
            ->assertCreated()
            ->json('data');

        $id = $created['id'];

        $this->actingAsMfaVerified($admin)
            ->putJson("/api/composable-dashboards/{$id}/draft", [
                'widgets' => [
                    [
                        'key' => 'bad_map',
                        'type' => 'map',
                        'metric' => 'members',
                        'title' => 'Misleading map',
                        'visualization' => 'map',
                        'position' => 0,
                        'span' => 2,
                    ],
                ],
                'role_ids' => [$role->id],
            ])
            ->assertOk();

        $invalid = $this->actingAsMfaVerified($admin)
            ->postJson("/api/composable-dashboards/{$id}/validate")
            ->assertOk()
            ->json('data');

        $this->assertFalse($invalid['valid']);
        $this->assertNotEmpty($invalid['errors']);

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/composable-dashboards/{$id}/publish")
            ->assertStatus(422);

        $this->actingAsMfaVerified($admin)
            ->putJson("/api/composable-dashboards/{$id}/draft", [
                'widgets' => [
                    [
                        'key' => 'members_kpi',
                        'type' => 'kpi',
                        'metric' => 'members',
                        'title' => 'Active members',
                        'visualization' => 'kpi',
                        'position' => 0,
                        'span' => 1,
                    ],
                    [
                        'key' => 'attendance_trend',
                        'type' => 'line',
                        'metric' => 'attendance',
                        'title' => 'Attendance trend',
                        'visualization' => 'line',
                        'position' => 1,
                        'span' => 2,
                    ],
                ],
                'role_ids' => [$role->id],
            ])
            ->assertOk();

        $preview = $this->actingAsMfaVerified($admin)
            ->postJson("/api/composable-dashboards/{$id}/preview")
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $preview['widgets']);
        $this->assertContains($preview['widgets'][0]['state'], ['ready', 'empty']);
        $this->assertNotEmpty($preview['widgets'][0]['definition']);

        $published = $this->actingAsMfaVerified($admin)
            ->postJson("/api/composable-dashboards/{$id}/publish")
            ->assertOk()
            ->json('data');

        $this->assertSame('published', $published['status']);
        $this->assertSame(1, $published['current_version']);
    }

    public function test_assigned_user_loads_permitted_widgets_and_isolates_failures(): void
    {
        $composer = $this->composer();
        $viewerRole = Role::create(['name' => 'viewer_role']);
        $viewer = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $this->grant($viewer, ['dashboards.view', 'members.read', 'attendance.read']);

        RoleAssignment::create([
            'user_id' => $viewer->id,
            'role_id' => $viewerRole->id,
            'granted_by' => $composer->id,
        ]);

        Member::create([
            'membership_id' => 'CD-M-001',
            'branch_id' => $this->branch->id,
            'registration_channel' => 'web',
            'first_name' => 'Ada',
            'last_name' => 'Member',
            'lifecycle_status' => 'active',
            'consent_data_processing' => true,
        ]);

        $created = $this->actingAsMfaVerified($composer)
            ->postJson('/api/composable-dashboards', [
                'name' => 'Role dashboard',
                'branch_id' => $this->branch->id,
                'role_ids' => [$viewerRole->id],
                'widgets' => [
                    [
                        'key' => 'members_kpi',
                        'type' => 'kpi',
                        'metric' => 'members',
                        'title' => 'Active members',
                        'visualization' => 'kpi',
                        'position' => 0,
                        'span' => 1,
                    ],
                    [
                        'key' => 'giving_kpi',
                        'type' => 'kpi',
                        'metric' => 'giving',
                        'title' => 'Giving total',
                        'visualization' => 'kpi',
                        'position' => 1,
                        'span' => 1,
                    ],
                    [
                        'key' => 'broken_widget',
                        'type' => 'kpi',
                        'metric' => 'attendance',
                        'title' => 'Broken attendance',
                        'visualization' => 'kpi',
                        'position' => 2,
                        'span' => 1,
                        'scope' => ['__test_force_failure' => true],
                    ],
                ],
            ])
            ->assertCreated()
            ->json('data');

        $id = $created['id'];

        $this->actingAsMfaVerified($composer)
            ->postJson("/api/composable-dashboards/{$id}/publish")
            ->assertOk();

        $runtime = $this->actingAsMfaVerified($viewer)
            ->getJson('/api/me/composable-dashboard')
            ->assertOk()
            ->json('data');

        $this->assertTrue($runtime['assigned']);
        $this->assertCount(2, $runtime['widgets']);

        $members = collect($runtime['widgets'])->firstWhere('key', 'members_kpi');
        $failed = collect($runtime['widgets'])->firstWhere('key', 'broken_widget');

        $this->assertNotNull($members);
        $this->assertSame('ready', $members['state']);
        $this->assertNotEmpty($members['definition']);
        $this->assertArrayHasKey('freshness', $members);

        $this->assertNotNull($failed);
        $this->assertSame('failed', $failed['state']);
        $this->assertArrayNotHasKey('giving_kpi', collect($runtime['widgets'])->keyBy('key'));
    }
}
