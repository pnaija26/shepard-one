<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;

class OrganizationHierarchyTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_can_be_created_with_valid_hierarchy()
    {
        // Create a test user with privileged access
        $user = $this->privilegedUser();
        
        // Create a headquarters first
        $headquarters = Organization::create([
            'name' => 'Main Headquarters',
            'type' => 'headquarters',
            'identifier' => 'HQ-001'
        ]);

        // Create a branch under headquarters (valid hierarchy)
        $response = $this->actingAsMfaVerified($user)->post('/api/org/organizations', [
            'name' => 'Branch A',
            'type' => 'branch',
            'identifier' => 'BRANCH-A-001',
            'parent_id' => $headquarters->id
        ]);

        $response->assertStatus(201);
        
        $branch = Organization::where('identifier', 'BRANCH-A-001')->first();
        $this->assertNotNull($branch);
        $this->assertEquals($headquarters->id, $branch->parent_id);
    }

    public function test_organization_cannot_be_created_with_invalid_hierarchy()
    {
        // Create a test user with privileged access
        $user = $this->privilegedUser();
        
        // Create a headquarters first
        $headquarters = Organization::create([
            'name' => 'Main Headquarters',
            'type' => 'headquarters',
            'identifier' => 'HQ-001'
        ]);

        // Try to create a team under headquarters (invalid hierarchy)
        $response = $this->actingAsMfaVerified($user)->post('/api/org/organizations', [
            'name' => 'Invalid Team',
            'type' => 'team',
            'identifier' => 'TEAM-001',
            'parent_id' => $headquarters->id
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['message', 'errors']);
    }

    public function test_organization_can_be_created_with_valid_hierarchy_branch_to_department()
    {
        // Create a test user with privileged access
        $user = $this->privilegedUser();
        
        // Create a branch
        $branch = Organization::create([
            'name' => 'Branch A',
            'type' => 'branch',
            'identifier' => 'BRANCH-A-001'
        ]);

        // Try to create a department under branch (valid hierarchy)
        // According to our rules, branch can have: campus, location, ministry, department
        $response = $this->actingAsMfaVerified($user)->post('/api/org/organizations', [
            'name' => 'Department Alpha',
            'type' => 'department',
            'identifier' => 'DEPT-ALPHA-001',
            'parent_id' => $branch->id
        ]);

        $response->assertStatus(201);
        
        $dept = Organization::where('identifier', 'DEPT-ALPHA-001')->first();
        $this->assertNotNull($dept);
        $this->assertEquals($branch->id, $dept->parent_id);
    }

    public function test_organization_can_be_updated_with_valid_hierarchy()
    {
        // Create a test user with privileged access
        $user = $this->privilegedUser();
        
        // Create a branch first
        $branch = Organization::create([
            'name' => 'Branch A',
            'type' => 'branch',
            'identifier' => 'BRANCH-A-001'
        ]);

        // Create a department under branch (valid hierarchy)
        $dept = Organization::create([
            'name' => 'Department Alpha',
            'type' => 'department',
            'identifier' => 'DEPT-ALPHA-001',
            'parent_id' => $branch->id
        ]);

        // Update the department to have a different name (should work)
        $response = $this->actingAsMfaVerified($user)->put('/api/org/organizations/' . $dept->id, [
            'name' => 'Department Beta'
        ]);

        $response->assertStatus(200);
        $this->assertEquals('Department Beta', $dept->fresh()->name);
    }
}