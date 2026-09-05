<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\Visitor;
use App\Models\VisitorDuplicateReview;
use App\Models\VisitorVisit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 3.1: Capture Visitors and Their Decisions.
 */
class VisitorCaptureTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'IDX-HQ']);
        $this->branch = Organization::create(['name' => 'Branch A', 'type' => 'branch', 'identifier' => 'IDX-A', 'parent_id' => $hq->id]);
    }

    private function officer(bool $sensitive = false): User
    {
        $user = $this->privilegedUser(['branch_id' => null]);
        $role = Role::create(['name' => 'visitor_officer_' . $user->id]);
        foreach (['visitors.read', 'visitors.write', 'visitors.export'] as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        if ($sensitive) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => 'visitors.sensitive']);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);

        return $user;
    }

    private function capturePayload(array $overrides = []): array
    {
        return array_merge([
            'branch_id' => $this->branch->id,
            'first_name' => 'Samuel',
            'last_name' => 'Okon',
            'email' => 'samuel.okon@example.com',
            'phone' => '08022223333',
            'visit_date' => '2026-08-31',
            'service_or_event' => 'Sunday Service',
            'source' => 'service',
            'decisions' => ['salvation'],
            'salvation_response' => 'Accepted Christ today',
            'prayer_needs' => 'Needs follow-up on accommodation',
            'membership_interest' => true,
            'consent_data_processing' => true,
            'consent_follow_up' => true,
        ], $overrides);
    }

    // ------------------------------------------------------------------
    // AC1 — capture with duplicate review before new identity
    // ------------------------------------------------------------------

    public function test_officer_can_capture_first_time_visitor(): void
    {
        $officer = $this->officer(true);

        $this->actingAsMfaVerified($officer)
            ->postJson('/api/visitors', $this->capturePayload())
            ->assertCreated()
            ->assertJsonPath('data.full_name', 'Samuel Okon')
            ->assertJsonPath('data.visits.0.decisions.0', 'salvation')
            ->assertJsonPath('data.visits.0.prayer_needs', 'Needs follow-up on accommodation');

        $this->assertDatabaseHas('visitors', ['email' => 'samuel.okon@example.com']);
        $this->assertDatabaseHas('visitor_visits', ['service_or_event' => 'Sunday Service']);
        $this->assertDatabaseHas('audit_events', ['action' => 'visitor.captured']);
    }

    public function test_duplicate_visitor_capture_requires_review(): void
    {
        $officer = $this->officer();
        Visitor::create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Samuel',
            'last_name' => 'Okon',
            'email' => 'samuel.okon@example.com',
            'original_source' => 'service',
            'first_visit_at' => now()->subWeek(),
            'created_by' => $officer->id,
        ]);

        $this->actingAsMfaVerified($officer)
            ->postJson('/api/visitors', $this->capturePayload())
            ->assertStatus(422)
            ->assertJsonPath('duplicate_review_required', true)
            ->assertJsonFragment(['type' => 'visitor']);

        $this->assertDatabaseHas('visitor_duplicate_reviews', [
            'match_reason' => 'email',
            'status' => VisitorDuplicateReview::STATUS_PENDING,
        ]);
        $this->assertDatabaseCount('visitor_visits', 0);
    }

    public function test_duplicate_member_match_is_presented_on_capture(): void
    {
        $officer = $this->officer();
        Member::create([
            'membership_id' => 'S1-M-VIS-01',
            'branch_id' => $this->branch->id,
            'registration_channel' => 'reception',
            'first_name' => 'Samuel',
            'last_name' => 'Okon',
            'email' => 'samuel.okon@example.com',
            'consent_data_processing' => true,
        ]);

        $this->actingAsMfaVerified($officer)
            ->postJson('/api/visitors', $this->capturePayload())
            ->assertStatus(422)
            ->assertJsonFragment(['type' => 'member']);
    }

    // ------------------------------------------------------------------
    // AC2 — returning visitor appends visit history
    // ------------------------------------------------------------------

    public function test_returning_visitor_visit_is_appended_without_losing_source(): void
    {
        $officer = $this->officer(true);
        $visitor = Visitor::create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Rita',
            'last_name' => 'Bello',
            'email' => 'rita@example.com',
            'original_source' => 'event',
            'inviter_name' => 'Pastor Ada',
            'first_visit_at' => '2026-08-01',
            'created_by' => $officer->id,
        ]);
        VisitorVisit::create([
            'visitor_id' => $visitor->id,
            'branch_id' => $this->branch->id,
            'visit_date' => '2026-08-01',
            'source' => 'event',
            'attendance_status' => 'first_timer',
            'consent_data_processing' => true,
            'recorded_by' => $officer->id,
            'created_at' => now()->subWeek(),
        ]);

        $this->actingAsMfaVerified($officer)
            ->postJson("/api/visitors/{$visitor->id}/visits", [
                'visit_date' => '2026-08-31',
                'source' => 'service',
                'service_or_event' => 'Sunday Service',
                'decisions' => ['membership_interest'],
                'membership_interest' => true,
                'consent_data_processing' => true,
            ])
            ->assertCreated()
            ->assertJsonCount(2, 'data.visits')
            ->assertJsonPath('data.original_source', 'event')
            ->assertJsonPath('data.inviter_name', 'Pastor Ada');

        $this->assertDatabaseCount('visitor_visits', 2);
        $this->assertDatabaseHas('audit_events', ['action' => 'visitor.visit.recorded']);
    }

    // ------------------------------------------------------------------
    // AC3 — restricted fields hidden from ordinary views and exports
    // ------------------------------------------------------------------

    public function test_restricted_fields_hidden_without_sensitive_permission(): void
    {
        $officer = $this->officer(false);
        $sensitiveOfficer = $this->officer(true);

        $visitor = Visitor::create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Confidential',
            'last_name' => 'Visitor',
            'original_source' => 'service',
            'first_visit_at' => now(),
            'created_by' => $sensitiveOfficer->id,
        ]);
        VisitorVisit::create([
            'visitor_id' => $visitor->id,
            'branch_id' => $this->branch->id,
            'visit_date' => '2026-08-31',
            'source' => 'service',
            'prayer_needs' => 'Private prayer request',
            'salvation_response' => 'Private salvation note',
            'consent_data_processing' => true,
            'recorded_by' => $sensitiveOfficer->id,
        ]);

        $this->actingAsMfaVerified($officer)
            ->getJson("/api/visitors/{$visitor->id}")
            ->assertOk()
            ->assertJsonPath('data.visits.0.has_restricted_content', true)
            ->assertJsonMissing(['prayer_needs' => 'Private prayer request']);

        $this->actingAsMfaVerified($sensitiveOfficer)
            ->getJson("/api/visitors/{$visitor->id}")
            ->assertOk()
            ->assertJsonPath('data.visits.0.prayer_needs', 'Private prayer request');

        $csv = $this->actingAsMfaVerified($officer)
            ->get('/api/visitors/export')
            ->streamedContent();

        $this->assertStringNotContainsString('Private prayer request', $csv);
        $this->assertStringContainsString('Confidential Visitor', $csv);
    }
}
