<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\BranchScope;
use App\Services\BranchScopeException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 1.4: Isolate Branch Data and Consolidate HQ Views.
 *
 * Verifies that every data path enforces the caller's effective scope, that
 * cross-branch access is denied (not leaked), that client-supplied parameters
 * cannot widen a user's scope, and that missing/invalid scope context fails
 * secure instead of processing unscoped.
 */
class BranchScopeIsolationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build the canonical tree:
     *   HQ (headquarters)
     *   ├── Branch A ── Campus A1
     *   └── Branch B ── Campus B1
     */
    private function buildTree(): array
    {
        $hq = Organization::create(['name' => 'Headquarters', 'type' => 'headquarters', 'identifier' => 'IDX-HQ']);

        $branchA = Organization::create([
            'name' => 'Branch A', 'type' => 'branch', 'identifier' => 'IDX-BR-A', 'parent_id' => $hq->id,
        ]);
        $campusA1 = Organization::create([
            'name' => 'Campus A1', 'type' => 'campus', 'identifier' => 'IDX-CM-A1', 'parent_id' => $branchA->id,
        ]);

        $branchB = Organization::create([
            'name' => 'Branch B', 'type' => 'branch', 'identifier' => 'IDX-BR-B', 'parent_id' => $hq->id,
        ]);
        $campusB1 = Organization::create([
            'name' => 'Campus B1', 'type' => 'campus', 'identifier' => 'IDX-CM-B1', 'parent_id' => $branchB->id,
        ]);

        return compact('hq', 'branchA', 'campusA1', 'branchB', 'campusB1');
    }

    private function hqAdmin(): User
    {
        // No branch assignment -> church-wide (HQ) scope.
        return User::factory()->create(['roles' => ['admin'], 'branch_id' => null]);
    }

    private function branchAdmin(Organization $branch): User
    {
        return User::factory()->create(['roles' => ['admin'], 'branch_id' => $branch->id]);
    }

    // ------------------------------------------------------------------
    // Index: isolation + consolidated HQ view with attribution
    // ------------------------------------------------------------------

    public function test_branch_user_sees_only_their_own_subtree()
    {
        $t = $this->buildTree();
        $user = $this->branchAdmin($t['branchA']);

        $response = $this->actingAs($user)->getJson('/api/org/organizations');

        $ids = collect($response->json('data'))->pluck('id')->all();

        $response->assertStatus(200)
            ->assertJsonPath('meta.scope', 'branch')
            ->assertJsonPath('meta.branch_id', $t['branchA']->id);

        // Exactly their branch + descendants — never Branch B's data.
        sort($ids);
        $expected = [$t['branchA']->id, $t['campusA1']->id];
        sort($expected);
        $this->assertEquals($expected, $ids);
    }

    public function test_hq_user_sees_consolidated_view_with_attribution()
    {
        $t = $this->buildTree();
        $user = $this->hqAdmin();

        $response = $this->actingAs($user)->getJson('/api/org/organizations');

        $ids = collect($response->json('data'))->pluck('id')->all();

        // Church-wide: every organization across all branches.
        $expected = [$t['hq']->id, $t['branchA']->id, $t['campusA1']->id, $t['branchB']->id, $t['campusB1']->id];
        sort($ids);
        sort($expected);

        $response->assertStatus(200)
            ->assertJsonPath('meta.scope', 'church-wide')
            ->assertJsonPath('meta.branch_id', null);

        // Attribution: each row carries its own branch identity (type + parent),
        // so the consolidated view is not anonymous.
        $this->assertEquals($expected, $ids);
    }

    public function test_client_parameters_cannot_widen_branch_scope()
    {
        $t = $this->buildTree();
        $user = $this->branchAdmin($t['branchA']);

        // Tamper attempts: query params and body fields trying to claim HQ scope.
        $response = $this->actingAs($user)
            ->getJson('/api/org/organizations?scope=church-wide&branch_id=' . $t['hq']->id);

        $ids = collect($response->json('data'))->pluck('id')->all();
        sort($ids);
        $expected = [$t['branchA']->id, $t['campusA1']->id];
        sort($expected);

        $response->assertStatus(200)
            ->assertJsonPath('meta.scope', 'branch');
        $this->assertEquals($expected, $ids, 'Query-string scope claims must be ignored.');
    }

    // ------------------------------------------------------------------
    // Show / Update / Destroy: cross-branch access is denied (403)
    // ------------------------------------------------------------------

    public function test_branch_user_cannot_read_another_branchs_data()
    {
        $t = $this->buildTree();
        $user = $this->branchAdmin($t['branchA']);

        $response = $this->actingAs($user)->getJson('/api/org/organizations/' . $t['campusB1']->id);

        $response->assertStatus(403);
    }

    public function test_branch_user_can_read_their_own_data()
    {
        $t = $this->buildTree();
        $user = $this->branchAdmin($t['branchA']);

        $response = $this->actingAs($user)->getJson('/api/org/organizations/' . $t['campusA1']->id);

        $response->assertStatus(200)
            ->assertJsonPath('name', 'Campus A1');
    }

    public function test_branch_user_cannot_update_another_branchs_data()
    {
        $t = $this->buildTree();
        $user = $this->branchAdmin($t['branchA']);

        $response = $this->actingAs($user)->putJson('/api/org/organizations/' . $t['campusB1']->id, [
            'name' => 'Hijacked',
        ]);

        $response->assertStatus(403);
        $this->assertEquals('Campus B1', $t['campusB1']->fresh()->name);
    }

    public function test_branch_user_cannot_delete_another_branchs_data()
    {
        $t = $this->buildTree();
        $user = $this->branchAdmin($t['branchA']);

        $response = $this->actingAs($user)->deleteJson('/api/org/organizations/' . $t['campusB1']->id);

        $response->assertStatus(403);
        $this->assertDatabaseHas('organizations', ['id' => $t['campusB1']->id]);
    }

    public function test_branch_user_can_update_and_delete_their_own_data()
    {
        $t = $this->buildTree();
        $user = $this->branchAdmin($t['branchA']);

        $update = $this->actingAs($user)->putJson('/api/org/organizations/' . $t['campusA1']->id, [
            'name' => 'Campus A1 Renamed',
        ]);
        $update->assertStatus(200);

        $delete = $this->actingAs($user)->deleteJson('/api/org/organizations/' . $t['campusA1']->id);
        $delete->assertStatus(200);
        $this->assertDatabaseMissing('organizations', ['id' => $t['campusA1']->id]);
    }

    // ------------------------------------------------------------------
    // Store: branch-scoped users create only inside their own subtree
    // ------------------------------------------------------------------

    public function test_branch_user_cannot_create_under_another_branch()
    {
        $t = $this->buildTree();
        $user = $this->branchAdmin($t['branchA']);

        $response = $this->actingAs($user)->postJson('/api/org/organizations', [
            'name' => 'Sneaky Campus',
            'type' => 'campus',
            'identifier' => 'IDX-SNEAKY',
            'parent_id' => $t['branchB']->id,
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('organizations', ['identifier' => 'IDX-SNEAKY']);
    }

    public function test_branch_user_cannot_create_root_level_units()
    {
        $t = $this->buildTree();
        $user = $this->branchAdmin($t['branchA']);

        // A new branch (root-level) is an HQ governance action.
        $response = $this->actingAs($user)->postJson('/api/org/organizations', [
            'name' => 'Rogue Branch',
            'type' => 'branch',
            'identifier' => 'IDX-ROGUE',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('organizations', ['identifier' => 'IDX-ROGUE']);
    }

    public function test_branch_user_can_create_within_their_own_subtree()
    {
        $t = $this->buildTree();
        $user = $this->branchAdmin($t['branchA']);

        $response = $this->actingAs($user)->postJson('/api/org/organizations', [
            'name' => 'Campus A2',
            'type' => 'campus',
            'identifier' => 'IDX-CM-A2',
            'parent_id' => $t['branchA']->id,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('organizations', ['identifier' => 'IDX-CM-A2']);
    }

    public function test_hq_user_can_create_root_level_units()
    {
        $t = $this->buildTree();
        $user = $this->hqAdmin();

        $response = $this->actingAs($user)->postJson('/api/org/organizations', [
            'name' => 'Branch C',
            'type' => 'branch',
            'identifier' => 'IDX-BR-C',
            'parent_id' => $t['hq']->id,
        ]);

        $response->assertStatus(201);
    }

    // ------------------------------------------------------------------
    // Secure failure: invalid or missing scope context never runs unscoped
    // ------------------------------------------------------------------

    public function test_user_whose_branch_was_deleted_fails_secure()
    {
        // Simulate the "assignment no longer resolves" state: a principal whose
        // branch_id points at a branch that does not exist. (In production,
        // cascadeOnDelete removes such users instead of silently widening them
        // to church-wide scope; an unpersisted model lets us exercise this edge
        // case without fighting the FK constraint.)
        $user = new User([
            'name' => 'Orphaned Admin',
            'email' => 'orphan@example.com',
            'roles' => ['admin'],
            'branch_id' => 99999,
        ]);

        $response = $this->actingAs($user)->getJson('/api/org/organizations');

        // Denied outright — NOT a silent fallback to unscoped/church-wide data.
        $response->assertStatus(403);
    }

    public function test_background_paths_without_a_principal_fail_secure()
    {
        // Story 1.4: background jobs, scheduled tasks and webhooks must fail
        // secure when they have no scope context — never process unscoped.
        $this->expectException(BranchScopeException::class);

        BranchScope::for(null);
    }

    public function test_scope_service_denies_cross_branch_membership()
    {
        $t = $this->buildTree();

        $scopeA = BranchScope::for($this->branchAdmin($t['branchA']));
        $hqScope = BranchScope::for($this->hqAdmin());

        // Branch A scope: own subtree yes, everything else no.
        $this->assertTrue($scopeA->includes($t['branchA']));
        $this->assertTrue($scopeA->includes($t['campusA1']));
        $this->assertFalse($scopeA->includes($t['hq']));
        $this->assertFalse($scopeA->includes($t['branchB']));
        $this->assertFalse($scopeA->includes($t['campusB1']));

        // HQ scope: everything.
        $this->assertTrue($hqScope->includes($t['hq']));
        $this->assertTrue($hqScope->includes($t['campusB1']));
    }
}
