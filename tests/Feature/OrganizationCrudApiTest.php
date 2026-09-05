<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationCrudApiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return $this->privilegedUser();
    }

    public function test_index_returns_all_organizations()
    {
        $user = $this->admin();
        Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'IDX-HQ-1']);
        Organization::create(['name' => 'Branch A', 'type' => 'branch', 'identifier' => 'IDX-BR-1']);

        $response = $this->actingAsMfaVerified($user)->getJson('/api/org/organizations');

        $response->assertStatus(200)
            ->assertJsonCount(2);
    }

    public function test_store_creates_organization()
    {
        $user = $this->admin();

        $response = $this->actingAsMfaVerified($user)->postJson('/api/org/organizations', [
            'name' => 'New Branch',
            'type' => 'branch',
            'identifier' => 'IDX-NEW-1',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('name', 'New Branch');
        $this->assertDatabaseHas('organizations', ['identifier' => 'IDX-NEW-1']);
    }

    public function test_store_persists_location_and_primary_contact(): void
    {
        $user = $this->admin();

        $response = $this->actingAsMfaVerified($user)->postJson('/api/org/organizations', [
            'name' => 'Eastside Branch',
            'type' => 'branch',
            'identifier' => 'IDX-EAST-1',
            'location' => [
                'address_line1' => '12 Church Road',
                'city' => 'Lagos',
                'state' => 'LA',
                'country' => 'Nigeria',
            ],
            'primary_contact' => [
                'name' => 'Pastor Ada',
                'email' => 'ada@eastside.example',
                'phone' => '08012345678',
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('location.city', 'Lagos')
            ->assertJsonPath('primary_contact.name', 'Pastor Ada');

        $this->assertDatabaseHas('organizations', [
            'identifier' => 'IDX-EAST-1',
        ]);
    }

    public function test_update_changes_fields()
    {
        $user = $this->admin();
        $org = Organization::create(['name' => 'Old Name', 'type' => 'branch', 'identifier' => 'IDX-UPD-1']);

        $response = $this->actingAsMfaVerified($user)->putJson('/api/org/organizations/' . $org->id, [
            'name' => 'New Name',
            'description' => 'Updated description',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('name', 'New Name');
        $this->assertEquals('New Name', $org->fresh()->name);
    }

    public function test_update_can_reparent_to_valid_parent()
    {
        $user = $this->admin();
        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'IDX-HQ-2']);
        $branch = Organization::create(['name' => 'Branch B', 'type' => 'branch', 'identifier' => 'IDX-BR-2']);

        $response = $this->actingAsMfaVerified($user)->putJson('/api/org/organizations/' . $branch->id, [
            'parent_id' => $hq->id,
        ]);

        $response->assertStatus(200);
        $this->assertEquals($hq->id, $branch->fresh()->parent_id);
    }

    public function test_update_rejects_own_descendant_as_parent()
    {
        $user = $this->admin();
        $branch = Organization::create(['name' => 'Branch C', 'type' => 'branch', 'identifier' => 'IDX-BR-3']);
        $dept = Organization::create([
            'name' => 'Dept C',
            'type' => 'department',
            'identifier' => 'IDX-DPT-3',
            'parent_id' => $branch->id,
        ]);

        // Setting the branch's parent to its own child would create a cycle.
        $response = $this->actingAsMfaVerified($user)->putJson('/api/org/organizations/' . $branch->id, [
            'parent_id' => $dept->id,
        ]);

        $response->assertStatus(422);
        $this->assertNull($branch->fresh()->parent_id);
    }

    public function test_update_rejects_self_as_parent()
    {
        $user = $this->admin();
        $org = Organization::create(['name' => 'Solo', 'type' => 'branch', 'identifier' => 'IDX-SOLO-1']);

        $response = $this->actingAsMfaVerified($user)->putJson('/api/org/organizations/' . $org->id, [
            'parent_id' => $org->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_destroy_deletes_leaf_organization()
    {
        $user = $this->admin();
        $org = Organization::create(['name' => 'Leaf', 'type' => 'branch', 'identifier' => 'IDX-LEAF-1']);

        $response = $this->actingAsMfaVerified($user)->deleteJson('/api/org/organizations/' . $org->id);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Organization deleted successfully');
        $this->assertDatabaseMissing('organizations', ['id' => $org->id]);
    }

    public function test_destroy_refuses_when_children_exist()
    {
        $user = $this->admin();
        $branch = Organization::create(['name' => 'Parent Branch', 'type' => 'branch', 'identifier' => 'IDX-PAR-1']);
        Organization::create([
            'name' => 'Child Dept',
            'type' => 'department',
            'identifier' => 'IDX-CHD-1',
            'parent_id' => $branch->id,
        ]);

        $response = $this->actingAsMfaVerified($user)->deleteJson('/api/org/organizations/' . $branch->id);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('parent_id');
        $this->assertDatabaseHas('organizations', ['id' => $branch->id]);
    }

    public function test_non_privileged_user_cannot_create()
    {
        $user = User::factory()->create(['roles' => ['member']]);

        $response = $this->actingAsMfaVerified($user)->postJson('/api/org/organizations', [
            'name' => 'Nope',
            'type' => 'branch',
            'identifier' => 'IDX-NOPE-1',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('organizations', ['identifier' => 'IDX-NOPE-1']);
    }
}
