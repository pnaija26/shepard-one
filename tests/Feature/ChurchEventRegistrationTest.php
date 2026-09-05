<?php

namespace Tests\Feature;

use App\Models\ChurchEvent;
use App\Models\ChurchEventRegistration;
use App\Models\ChurchEventScanEvent;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\EventRegistrationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Story 4.3: Register and Admit Event Participants.
 */
class ChurchEventRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'IDX-HQ']);
        $this->branch = Organization::create(['name' => 'Branch A', 'type' => 'branch', 'identifier' => 'IDX-A', 'parent_id' => $hq->id]);
    }

    private function coordinator(): User
    {
        $user = $this->privilegedUser(['branch_id' => null]);
        $role = Role::create(['name' => 'event_reg_coord_' . $user->id]);
        foreach (['events.read', 'events.manage', 'events.registrations.read', 'events.registrations.manage'] as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);

        return $user;
    }

    private function memberUser(): User
    {
        $user = User::factory()->create(['roles' => ['member'], 'has_mfa_enrolled' => false]);
        $role = Role::create(['name' => 'event_self_reg_' . $user->id]);
        RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => 'events.registrations.self']);
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);

        Member::create([
            'user_id' => $user->id,
            'membership_id' => 'S1-M-EVT-' . $user->id,
            'branch_id' => $this->branch->id,
            'registration_channel' => 'web',
            'first_name' => 'Ada',
            'last_name' => 'Bello',
            'email' => 'ada@example.com',
            'consent_data_processing' => true,
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
        ]);

        return $user;
    }

    private function scanner(): User
    {
        $user = $this->privilegedUser(['branch_id' => null]);
        $role = Role::create(['name' => 'event_scanner_' . $user->id]);
        RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => 'events.admit.scan']);
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);

        return $user;
    }

    private function publishedEvent(array $overrides = []): ChurchEvent
    {
        $coordinator = $this->coordinator();

        $created = $this->actingAsMfaVerified($coordinator)
            ->postJson('/api/events', array_merge([
                'branch_id' => $this->branch->id,
                'title' => 'Leadership Summit',
                'event_date' => '2026-10-20',
                'start_time' => '09:00',
                'end_time' => '16:00',
                'venue' => 'Hall A',
                'capacity' => 100,
                'registration' => ['enabled' => true, 'capacity' => 2, 'waitlist_enabled' => true],
                'ticketing_policy' => ['type' => 'free'],
            ], $overrides))
            ->assertCreated();

        $eventId = $created->json('data.id');

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/events/{$eventId}/publish")
            ->assertOk();

        return ChurchEvent::query()->findOrFail($eventId)->fresh();
    }

    // ------------------------------------------------------------------
    // AC1 — registration with credential and duplicate enforcement
    // ------------------------------------------------------------------

    public function test_member_registration_creates_confirmation_and_qr_credential(): void
    {
        Carbon::setTestNow('2026-09-01');
        $event = $this->publishedEvent();
        $member = $this->memberUser();
        $profile = Member::where('user_id', $member->id)->firstOrFail();

        Sanctum::actingAs($member);

        $this->postJson("/api/events/{$event->id}/registrations", [
            'person_type' => Member::class,
            'person_id' => $profile->id,
            'registrant_name' => 'Ada Bello',
            'registrant_email' => 'ada@example.com',
            'channel' => 'web',
            'consent_data_processing' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', ChurchEventRegistration::STATUS_CONFIRMED)
            ->assertJsonStructure(['data' => ['confirmation_code', 'credential' => ['token', 'expires_at']]]);

        $this->postJson("/api/events/{$event->id}/registrations", [
            'person_type' => Member::class,
            'person_id' => $profile->id,
            'registrant_name' => 'Ada Bello',
            'consent_data_processing' => true,
        ])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'duplicate');
    }

    public function test_paid_event_requires_payment_before_confirmation(): void
    {
        $event = $this->publishedEvent([
            'ticketing_policy' => ['type' => 'paid', 'price' => 50],
        ]);
        $member = $this->memberUser();
        $profile = Member::where('user_id', $member->id)->firstOrFail();

        Sanctum::actingAs($member);

        $this->postJson("/api/events/{$event->id}/registrations", [
            'person_type' => Member::class,
            'person_id' => $profile->id,
            'registrant_name' => 'Ada Bello',
            'consent_data_processing' => true,
        ])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'payment_required');
    }

    // ------------------------------------------------------------------
    // AC2 — capacity and closed registration handling
    // ------------------------------------------------------------------

    public function test_full_event_waitlists_or_rejects_with_clear_next_step(): void
    {
        $event = $this->publishedEvent();
        $coordinator = $this->coordinator();

        $this->assertDatabaseCount('church_event_registrations', 0);

        foreach (['First', 'Second', 'Third'] as $index => $name) {
            $response = $this->actingAsMfaVerified($coordinator)
                ->postJson("/api/events/{$event->id}/registrations", [
                    'registrant_name' => $name . ' Guest',
                    'registrant_email' => strtolower($name) . '@example.com',
                    'channel' => 'staff',
                    'consent_data_processing' => true,
                ]);

            $response->assertCreated();
        }

        $this->assertDatabaseHas('church_event_registrations', [
            'church_event_id' => $event->id,
            'status' => ChurchEventRegistration::STATUS_WAITLISTED,
        ]);
    }

    public function test_closed_registration_is_rejected_safely(): void
    {
        $event = $this->publishedEvent();
        $coordinator = $this->coordinator();

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/events/{$event->id}/cancel")
            ->assertOk();

        $member = $this->memberUser();
        $profile = Member::where('user_id', $member->id)->firstOrFail();
        Sanctum::actingAs($member);

        $this->postJson("/api/events/{$event->id}/registrations", [
            'person_type' => Member::class,
            'person_id' => $profile->id,
            'registrant_name' => 'Ada Bello',
            'consent_data_processing' => true,
        ])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'closed')
            ->assertJsonPath('next_step', 'Contact the event team for assistance.');
    }

    // ------------------------------------------------------------------
    // AC3 — scan admission with safe rejection paths
    // ------------------------------------------------------------------

    public function test_scanner_admits_valid_credential_once(): void
    {
        $event = $this->publishedEvent();
        $member = $this->memberUser();
        $profile = Member::where('user_id', $member->id)->firstOrFail();
        Sanctum::actingAs($member);

        $response = $this->postJson("/api/events/{$event->id}/registrations", [
            'person_type' => Member::class,
            'person_id' => $profile->id,
            'registrant_name' => 'Ada Bello',
            'consent_data_processing' => true,
        ])->assertCreated();

        $token = $response->json('data.credential.token');
        $scanner = $this->scanner();

        $this->actingAsMfaVerified($scanner)
            ->postJson('/api/event-admissions/scan', ['token' => $token, 'event_id' => $event->id])
            ->assertOk()
            ->assertJsonPath('data.admitted', true)
            ->assertJsonPath('data.event_pass.registrant_name', 'Ada Bello');

        $this->assertDatabaseHas('church_event_scan_events', [
            'church_event_id' => $event->id,
            'outcome' => ChurchEventScanEvent::OUTCOME_ADMITTED,
        ]);

        $this->actingAsMfaVerified($scanner)
            ->postJson('/api/event-admissions/scan', ['token' => $token, 'event_id' => $event->id])
            ->assertStatus(422)
            ->assertJsonPath('data.admitted', false)
            ->assertJsonPath('data.reason', 'duplicate_scan');
    }

    public function test_scanner_rejects_wrong_event_credential_safely(): void
    {
        $eventA = $this->publishedEvent(['title' => 'Event A']);
        $eventB = $this->publishedEvent(['title' => 'Event B']);
        $coordinator = $this->coordinator();

        $created = $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/events/{$eventA->id}/registrations", [
                'registrant_name' => 'Guest One',
                'registrant_email' => 'guest@example.com',
                'consent_data_processing' => true,
            ])
            ->assertCreated();

        $registration = ChurchEventRegistration::firstOrFail();
        $token = app(EventRegistrationService::class)->issueCredential($registration)['token'];

        $this->actingAsMfaVerified($this->scanner())
            ->postJson('/api/event-admissions/scan', ['token' => $token, 'event_id' => $eventB->id])
            ->assertStatus(422)
            ->assertJsonPath('data.admitted', false)
            ->assertJsonPath('data.reason', 'wrong_event');
    }
}
