<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;

class OrganizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_model_can_be_created()
    {
        $organization = Organization::create([
            'name' => 'Test Branch',
            'type' => 'branch',
            'identifier' => 'TEST-BRANCH-001',
            'description' => 'A test branch organization'
        ]);

        $this->assertNotNull($organization->id);
        $this->assertEquals('Test Branch', $organization->name);
        $this->assertEquals('branch', $organization->type);
        $this->assertEquals('TEST-BRANCH-001', $organization->identifier);
    }

    public function test_organization_has_correct_attributes()
    {
        $organization = Organization::create([
            'name' => 'Test Ministry',
            'type' => 'ministry',
            'identifier' => 'TEST-MINISTRY-001',
            'attributes' => [
                'founded_year' => 2020,
                'description' => 'A test ministry'
            ]
        ]);

        $this->assertEquals(['founded_year' => 2020, 'description' => 'A test ministry'], $organization->attributes);
    }

    public function test_organization_can_have_parent_relationship()
    {
        $parent = Organization::create([
            'name' => 'Headquarters',
            'type' => 'headquarters',
            'identifier' => 'HQ-001'
        ]);

        $child = Organization::create([
            'name' => 'Branch A',
            'type' => 'branch',
            'identifier' => 'BRANCH-A-001',
            'parent_id' => $parent->id
        ]);

        $this->assertEquals($parent->id, $child->parent_id);
        $this->assertInstanceOf(Organization::class, $child->parent);
    }
}