<?php

namespace Tests\Feature;

use App\Models\ChurchService;
use App\Models\ChurchServiceChange;
use App\Models\ChurchServiceChangeEvent;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 4.1: Schedule Church Services.
 */
class ChurchServiceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;

    private Organization $otherBranch;

    protected function setUp(): void
    {
        parent::setUp();

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'IDX-HQ']);
        $this->branch = Organization::create(['name' => 'Branch A', 'type' => 'branch', 'identifier' => 'IDX-A', 'parent_id' => $hq->id]);
        $this->otherBranch = Organization::create(['name' => 'Branch B', 'type' => 'branch', 'identifier' => 'IDX-B', 'parent_id' => $hq->id]);
    }

    private function coordinator(?int $branchId = null): User
    {
        $user = $this->privilegedUser(['branch_id' => $branchId]);
        $role = Role::create(['name' => 'service_coord_' . $user->id]);
        foreach (['services.read', 'services.manage'] as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);

        return $user;
    }

    private function servicePayload(array $overrides = []): array
    {
        return array_merge([
            'branch_id' => $this->branch->id,
            'service_type' => 'sunday_service',
            'title' => 'Sunday Celebration',
            'service_date' => '2026-09-07',
            'start_time' => '09:00',
            'end_time' => '11:30',
            'venue' => 'Main Auditorium',
            'ministers' => [['name' => 'Pastor James', 'role' => 'lead']],
            'teams' => [['name' => 'Worship Team', 'team_id' => 1]],
            'capacity' => 500,
            'registration_required' => true,
            'registration_capacity' => 450,
            'attendance_target' => 400,
            'livestream' => ['enabled' => true, 'url' => 'https://live.example.com/sunday', 'platform' => 'youtube'],
        ], $overrides);
    }

    // ------------------------------------------------------------------
    // AC1 — schedule with validation and conflict detection
    // ------------------------------------------------------------------

    public function test_coordinator_can_create_and_publish_service_in_branch_schedule(): void
    {
        $coordinator = $this->coordinator();

        $this->actingAsMfaVerified($coordinator)
            ->postJson('/api/services', $this->servicePayload())
            ->assertCreated()
            ->assertJsonPath('data.status', ChurchService::STATUS_DRAFT);

        $service = ChurchService::firstOrFail();

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/services/{$service->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', ChurchService::STATUS_PUBLISHED);

        $this->actingAsMfaVerified($coordinator)
            ->getJson('/api/services?branch_id=' . $this->branch->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.venue', 'Main Auditorium')
            ->assertJsonPath('data.0.livestream.platform', 'youtube');
    }

    public function test_venue_time_conflict_is_rejected(): void
    {
        $coordinator = $this->coordinator();

        $this->actingAsMfaVerified($coordinator)
            ->postJson('/api/services', $this->servicePayload())
            ->assertCreated();

        $service = ChurchService::firstOrFail();
        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/services/{$service->id}/publish")
            ->assertOk();

        $this->actingAsMfaVerified($coordinator)
            ->postJson('/api/services', $this->servicePayload([
                'title' => 'Overlapping service',
                'start_time' => '10:00',
                'end_time' => '12:00',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['venue']);
    }

    public function test_invalid_capacity_and_leadership_are_flagged(): void
    {
        $coordinator = $this->coordinator();

        $this->actingAsMfaVerified($coordinator)
            ->postJson('/api/services', $this->servicePayload([
                'registration_capacity' => 600,
                'capacity' => 500,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['registration_capacity']);

        $this->actingAsMfaVerified($coordinator)
            ->postJson('/api/services', $this->servicePayload(['ministers' => []]))
            ->assertCreated();

        $service = ChurchService::firstOrFail();
        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/services/{$service->id}/publish")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ministers']);
    }

    // ------------------------------------------------------------------
    // AC2 — published changes retain history and emit change events
    // ------------------------------------------------------------------

    public function test_update_and_cancel_retains_history_and_emits_events(): void
    {
        $coordinator = $this->coordinator();

        $this->actingAsMfaVerified($coordinator)
            ->postJson('/api/services', $this->servicePayload())
            ->assertCreated();

        $service = ChurchService::firstOrFail();
        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/services/{$service->id}/publish")
            ->assertOk();

        $this->actingAsMfaVerified($coordinator)
            ->putJson("/api/services/{$service->id}", $this->servicePayload([
                'start_time' => '08:30',
                'venue' => 'Annex Hall',
            ]))
            ->assertOk()
            ->assertJsonPath('data.start_time', '08:30');

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/services/{$service->id}/cancel", ['reason' => 'Weather advisory'])
            ->assertOk()
            ->assertJsonPath('data.status', ChurchService::STATUS_CANCELLED);

        $this->assertGreaterThanOrEqual(3, ChurchServiceChange::where('church_service_id', $service->id)->count());
        $this->assertDatabaseHas('church_service_change_events', [
            'church_service_id' => $service->id,
            'event_type' => 'updated',
        ]);
        $this->assertDatabaseHas('church_service_change_events', [
            'church_service_id' => $service->id,
            'event_type' => 'cancelled',
        ]);
    }

    // ------------------------------------------------------------------
    // AC3 — branch scope denies unauthorized changes
    // ------------------------------------------------------------------

    public function test_branch_scoped_coordinator_cannot_manage_other_branch_services(): void
    {
        $hqCoordinator = $this->coordinator();
        $branchCoordinator = $this->coordinator($this->otherBranch->id);

        $this->actingAsMfaVerified($hqCoordinator)
            ->postJson('/api/services', $this->servicePayload())
            ->assertCreated();

        $service = ChurchService::firstOrFail();

        $this->actingAsMfaVerified($branchCoordinator)
            ->putJson("/api/services/{$service->id}", $this->servicePayload(['title' => 'Blocked edit']))
            ->assertForbidden();

        $this->assertDatabaseMissing('church_services', ['title' => 'Blocked edit']);
    }
}
