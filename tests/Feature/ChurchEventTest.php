<?php

namespace Tests\Feature;

use App\Models\ChurchEvent;
use App\Models\ChurchEventChangeEvent;
use App\Models\ChurchEventCloseSnapshot;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 4.2: Plan and Operate Events.
 */
class ChurchEventTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'IDX-HQ']);
        $this->branch = Organization::create(['name' => 'Branch A', 'type' => 'branch', 'identifier' => 'IDX-A', 'parent_id' => $hq->id]);
    }

    private function coordinator(bool $withBudget = false): User
    {
        $user = $this->privilegedUser(['branch_id' => null]);
        $role = Role::create(['name' => 'event_coord_' . $user->id]);
        foreach (['events.read', 'events.manage'] as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        if ($withBudget) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => 'events.budget.read']);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);

        return $user;
    }

    private function eventPayload(array $overrides = []): array
    {
        return array_merge([
            'branch_id' => $this->branch->id,
            'title' => 'Youth Conference',
            'description' => 'Annual youth gathering',
            'event_date' => '2026-10-15',
            'end_date' => '2026-10-17',
            'start_time' => '09:00',
            'end_time' => '17:00',
            'venue' => 'Conference Center',
            'capacity' => 300,
            'speakers' => [['name' => 'Guest Speaker']],
            'registration' => ['enabled' => true, 'capacity' => 250],
            'ticketing_policy' => ['type' => 'paid', 'price' => 25],
            'volunteers' => [['role' => 'usher', 'count' => 10]],
            'materials' => [['item' => 'Program booklets', 'quantity' => 300]],
            'budget' => ['estimated' => 5000, 'actual' => 0, 'currency' => 'NGN'],
            'reminders' => [['channel' => 'email', 'days_before' => 3]],
        ], $overrides);
    }

    // ------------------------------------------------------------------
    // AC1 — draft with restricted budget visibility
    // ------------------------------------------------------------------

    public function test_coordinator_saves_draft_and_budget_is_restricted_without_permission(): void
    {
        Carbon::setTestNow('2026-09-01');
        $coordinator = $this->coordinator(false);

        $this->actingAsMfaVerified($coordinator)
            ->postJson('/api/events', $this->eventPayload())
            ->assertCreated()
            ->assertJsonPath('data.status', ChurchEvent::STATUS_DRAFT)
            ->assertJsonPath('data.budget', null)
            ->assertJsonPath('data.budget_restricted', true);

        $this->assertDatabaseHas('church_events', [
            'title' => 'Youth Conference',
            'status' => ChurchEvent::STATUS_DRAFT,
        ]);
    }

    public function test_budget_visible_with_authorized_role(): void
    {
        $coordinator = $this->coordinator(true);

        $this->actingAsMfaVerified($coordinator)
            ->postJson('/api/events', $this->eventPayload())
            ->assertCreated()
            ->assertJsonPath('data.budget.estimated', 5000)
            ->assertJsonPath('data.budget_restricted', false);
    }

    // ------------------------------------------------------------------
    // AC2 — publish/postpone/cancel updates status and emits events
    // ------------------------------------------------------------------

    public function test_publish_postpone_and_cancel_emit_change_events(): void
    {
        Carbon::setTestNow('2026-09-01');
        $coordinator = $this->coordinator();

        $this->actingAsMfaVerified($coordinator)
            ->postJson('/api/events', $this->eventPayload())
            ->assertCreated();

        $event = ChurchEvent::firstOrFail();

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/events/{$event->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', ChurchEvent::STATUS_PUBLISHED)
            ->assertJsonPath('data.registration_availability', ChurchEvent::REGISTRATION_OPEN);

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/events/{$event->id}/postpone", ['event_date' => '2026-11-01'])
            ->assertOk()
            ->assertJsonPath('data.status', ChurchEvent::STATUS_POSTPONED)
            ->assertJsonPath('data.registration_availability', ChurchEvent::REGISTRATION_CLOSED);

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/events/{$event->id}/cancel", ['reason' => 'Venue unavailable'])
            ->assertOk()
            ->assertJsonPath('data.status', ChurchEvent::STATUS_CANCELLED);

        $this->assertDatabaseHas('church_event_change_events', ['event_type' => 'published']);
        $this->assertDatabaseHas('church_event_change_events', ['event_type' => 'postponed']);
        $this->assertDatabaseHas('church_event_change_events', ['event_type' => 'cancelled']);
    }

    // ------------------------------------------------------------------
    // AC3 — close completed event with snapshot and protection
    // ------------------------------------------------------------------

    public function test_close_completed_event_creates_snapshot_and_blocks_edits(): void
    {
        Carbon::setTestNow('2026-09-01');
        $coordinator = $this->coordinator(true);

        $this->actingAsMfaVerified($coordinator)
            ->postJson('/api/events', $this->eventPayload())
            ->assertCreated();

        $event = ChurchEvent::firstOrFail();

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/events/{$event->id}/publish")
            ->assertOk();

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/events/{$event->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', ChurchEvent::STATUS_COMPLETED);

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/events/{$event->id}/close")
            ->assertOk()
            ->assertJsonPath('data.status', ChurchEvent::STATUS_CLOSED)
            ->assertJsonPath('data.close_snapshot.registrations_count', 0)
            ->assertJsonPath('data.close_snapshot.budget_summary.estimated', 5000);

        $this->assertDatabaseCount('church_event_close_snapshots', 1);

        $this->actingAsMfaVerified($coordinator)
            ->putJson("/api/events/{$event->id}", $this->eventPayload(['title' => 'Blocked rename']))
            ->assertStatus(422);
    }
}
