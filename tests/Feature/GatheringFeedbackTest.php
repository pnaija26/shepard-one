<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\ChurchEvent;
use App\Models\ChurchService;
use App\Models\GatheringFeedback;
use App\Models\GatheringFeedbackActivity;
use App\Models\Member;
use App\Models\MemberNotification;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Story 4.5: Route Service and Event Feedback.
 */
class GatheringFeedbackTest extends TestCase
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
        $role = Role::create(['name' => 'feedback_coord_' . $user->id]);
        foreach (['services.manage', 'feedback.read', 'feedback.manage'] as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);

        return $user;
    }

    private function memberUser(): User
    {
        $user = User::factory()->create(['roles' => ['member'], 'has_mfa_enrolled' => false]);
        $role = Role::create(['name' => 'feedback_member_' . $user->id]);
        RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => 'feedback.submit']);
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);

        Member::create([
            'user_id' => $user->id,
            'membership_id' => 'S1-M-FB-' . $user->id,
            'branch_id' => $this->branch->id,
            'registration_channel' => 'web',
            'first_name' => 'Tolu',
            'last_name' => 'Ade',
            'consent_data_processing' => true,
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
        ]);

        return $user;
    }

    private function completedService(): ChurchService
    {
        Carbon::setTestNow('2026-09-08');
        $coordinator = $this->coordinator();

        $created = $this->actingAsMfaVerified($coordinator)
            ->postJson('/api/services', [
                'branch_id' => $this->branch->id,
                'service_type' => 'sunday_service',
                'title' => 'Sunday Celebration',
                'service_date' => '2026-09-07',
                'start_time' => '09:00',
                'end_time' => '11:30',
                'venue' => 'Main Auditorium',
                'ministers' => [['name' => 'Pastor James', 'role' => 'lead']],
            ])
            ->assertCreated();

        $serviceId = $created->json('data.id');

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/services/{$serviceId}/publish")
            ->assertOk();

        return ChurchService::query()->findOrFail($serviceId);
    }

    // ------------------------------------------------------------------
    // AC1 — categorized feedback routed to responsible team
    // ------------------------------------------------------------------

    public function test_member_submits_identified_feedback_for_completed_service(): void
    {
        $service = $this->completedService();
        $member = $this->memberUser();
        Sanctum::actingAs($member);

        $this->postJson('/api/me/feedback', [
            'gathering_key' => 'church_service',
            'gathering_id' => $service->id,
            'category' => 'sound',
            'body' => 'The sound mix was clear in every section of the auditorium.',
            'rating' => 5,
            'identity_mode' => 'identified',
            'consent_feedback_notifications' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.category', 'sound')
            ->assertJsonPath('data.assigned_team', 'media')
            ->assertJsonPath('data.identity_mode', 'identified')
            ->assertJsonPath('data.status', GatheringFeedback::STATUS_SUBMITTED);

        $this->assertDatabaseHas('gathering_feedback', [
            'gathering_id' => $service->id,
            'category' => 'sound',
            'assigned_team' => 'media',
        ]);

        $this->assertDatabaseHas('audit_events', ['action' => 'feedback.submitted']);
    }

    public function test_anonymous_feedback_follows_published_policy(): void
    {
        $service = $this->completedService();
        $member = $this->memberUser();
        Sanctum::actingAs($member);

        $this->postJson('/api/me/feedback', [
            'gathering_key' => 'church_service',
            'gathering_id' => $service->id,
            'category' => 'general_experience',
            'body' => 'Overall the service felt welcoming and well organized today.',
            'identity_mode' => 'anonymous',
        ])
            ->assertCreated()
            ->assertJsonPath('data.identity_mode', 'anonymous')
            ->assertJsonPath('data.submitter_display_name', 'Anonymous participant');
    }

    // ------------------------------------------------------------------
    // AC2 — moderation and invalid gathering handling
    // ------------------------------------------------------------------

    public function test_abusive_content_is_held_for_moderation_with_safe_response(): void
    {
        $service = $this->completedService();
        $member = $this->memberUser();
        Sanctum::actingAs($member);

        $this->postJson('/api/me/feedback', [
            'gathering_key' => 'church_service',
            'gathering_id' => $service->id,
            'category' => 'security',
            'body' => 'This message is obvious spam and should be reviewed.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', GatheringFeedback::STATUS_MODERATION_HOLD)
            ->assertJsonPath('message', 'Your feedback has been received and is pending review.');
    }

    public function test_invalid_gathering_and_attachments_are_rejected_safely(): void
    {
        $member = $this->memberUser();
        Sanctum::actingAs($member);

        $this->postJson('/api/me/feedback', [
            'gathering_key' => 'church_service',
            'gathering_id' => 9999,
            'category' => 'facilities',
            'body' => 'The restrooms were clean and well stocked after service.',
        ])
            ->assertStatus(404)
            ->assertJsonPath('reason', 'invalid_gathering');

        $service = $this->completedService();
        Sanctum::actingAs($member);

        $this->postJson('/api/me/feedback', [
            'gathering_key' => 'church_service',
            'gathering_id' => $service->id,
            'category' => 'facilities',
            'body' => 'The restrooms were clean and well stocked after service.',
            'attachments' => ['file.pdf'],
        ])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'attachment_rejected');
    }

    // ------------------------------------------------------------------
    // AC3 — team workflow, history, and consent-based notification
    // ------------------------------------------------------------------

    public function test_team_member_records_workflow_and_notifies_with_consent(): void
    {
        $service = $this->completedService();
        $member = $this->memberUser();
        Sanctum::actingAs($member);

        $created = $this->postJson('/api/me/feedback', [
            'gathering_key' => 'church_service',
            'gathering_id' => $service->id,
            'category' => 'ushering',
            'body' => 'Ushers were helpful finding seats for first-time guests.',
            'consent_feedback_notifications' => true,
        ])->assertCreated();

        $feedbackId = $created->json('data.id');
        $coordinator = $this->coordinator();

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/feedback/{$feedbackId}/activities", [
                'activity_type' => 'acknowledged',
                'notes' => 'Received by ushering lead.',
            ])
            ->assertOk()
            ->assertJsonPath('data.feedback.status', GatheringFeedback::STATUS_ACKNOWLEDGED);

        $this->actingAsMfaVerified($coordinator)
            ->postJson("/api/feedback/{$feedbackId}/activities", [
                'activity_type' => 'closed',
                'notes' => 'Shared with ushering team for next service.',
            ])
            ->assertOk()
            ->assertJsonPath('data.feedback.status', GatheringFeedback::STATUS_CLOSED);

        $this->assertDatabaseHas('gathering_feedback_activities', [
            'gathering_feedback_id' => $feedbackId,
            'activity_type' => 'closed',
        ]);

        $this->assertDatabaseHas('member_notifications', [
            'user_id' => $member->id,
            'type' => 'feedback.closed',
        ]);

        $this->assertDatabaseHas('audit_events', ['action' => 'feedback.activity_recorded']);
    }

    public function test_anonymous_submitter_is_not_notified_on_close(): void
    {
        $service = $this->completedService();
        $member = $this->memberUser();
        Sanctum::actingAs($member);

        $created = $this->postJson('/api/me/feedback', [
            'gathering_key' => 'church_service',
            'gathering_id' => $service->id,
            'category' => 'parking',
            'body' => 'Parking attendants directed traffic smoothly after service.',
            'identity_mode' => 'anonymous',
            'consent_feedback_notifications' => true,
        ])->assertCreated();

        $feedbackId = $created->json('data.id');

        $this->actingAsMfaVerified($this->coordinator())
            ->postJson("/api/feedback/{$feedbackId}/activities", [
                'activity_type' => 'closed',
                'notes' => 'Closed without participant follow-up.',
            ])
            ->assertOk();

        $this->assertDatabaseMissing('member_notifications', [
            'type' => 'feedback.closed',
        ]);
    }
}
