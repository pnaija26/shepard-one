<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\AuditImmutabilityException;
use App\Services\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 1.8: Review Security and Business Audit Events.
 */
class AuditReviewTest extends TestCase
{
    use RefreshDatabase;

    private function hqActor(): User
    {
        return $this->privilegedUser(['branch_id' => null]);
    }

    private function branchActor(Organization $branch): User
    {
        return $this->privilegedUser(['branch_id' => $branch->id]);
    }

    private function grantAudit(User $user, bool $export = true): void
    {
        $role = Role::create(['name' => 'auditor_' . $user->id]);
        RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => 'audit.read']);
        if ($export) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => 'audit.export']);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);
    }

    private function seedEvent(array $attrs = []): AuditEvent
    {
        return AuditEvent::create(array_merge([
            'action' => 'config.updated',
            'category' => AuditEvent::CATEGORY_BUSINESS,
            'module' => 'config',
            'created_at' => now(),
        ], $attrs));
    }

    // ------------------------------------------------------------------
    // AC1 — append-only records with redaction
    // ------------------------------------------------------------------

    public function test_auditable_event_captures_actor_context_and_redacts_secrets()
    {
        $actor = $this->hqActor();
        $service = app(AuditService::class);

        $event = $service->record(
            actor: $actor,
            action: 'auth.login',
            category: AuditEvent::CATEGORY_SECURITY,
            module: 'auth',
            branchId: null,
            subjectType: User::class,
            subjectId: $actor->id,
            before: ['status' => 'signed_out'],
            after: ['status' => 'signed_in', 'password' => 'plain-text', 'token' => 'abc123'],
            metadata: ['channel' => 'api'],
        );

        $this->assertDatabaseHas('audit_events', [
            'id' => $event->id,
            'actor_id' => $actor->id,
            'action' => 'auth.login',
            'module' => 'auth',
            'subject_type' => User::class,
            'subject_id' => $actor->id,
        ]);

        $event->refresh();
        $this->assertSame('[REDACTED]', $event->after_values['password']);
        $this->assertSame('[REDACTED]', $event->after_values['token']);
        $this->assertSame('signed_in', $event->after_values['status']);
        $this->assertNotNull($event->created_at);
    }

    public function test_login_failure_is_recorded_in_audit_events()
    {
        $this->postJson('/api/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(422);

        $this->assertDatabaseHas('audit_events', [
            'action' => AuditEvent::ACTION_AUTH_LOGIN_FAILED,
        ]);
    }

    // ------------------------------------------------------------------
    // AC2 — scoped search + meta-audit
    // ------------------------------------------------------------------

    public function test_authorized_auditor_can_filter_audit_records()
    {
        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'IDX-HQ']);
        $branchA = Organization::create(['name' => 'Branch A', 'type' => 'branch', 'identifier' => 'IDX-A', 'parent_id' => $hq->id]);
        $branchB = Organization::create(['name' => 'Branch B', 'type' => 'branch', 'identifier' => 'IDX-B', 'parent_id' => $hq->id]);

        $auditor = $this->hqActor();
        $this->grantAudit($auditor);

        $actorA = User::factory()->create(['branch_id' => $branchA->id]);
        $actorB = User::factory()->create(['branch_id' => $branchB->id]);

        $match = $this->seedEvent([
            'actor_id' => $actorA->id,
            'branch_id' => $branchA->id,
            'action' => 'role.updated',
        ]);
        $this->seedEvent([
            'actor_id' => $actorB->id,
            'branch_id' => $branchB->id,
            'action' => 'role.updated',
        ]);

        $this->actingAsMfaVerified($auditor)
            ->getJson('/api/audit?branch_id=' . $branchA->id . '&action=role.updated')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id);

        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $auditor->id,
            'action' => AuditEvent::ACTION_AUDIT_VIEWED,
        ]);
    }

    public function test_exporting_audit_data_is_itself_audited()
    {
        $auditor = $this->hqActor();
        $this->grantAudit($auditor);
        $this->seedEvent(['actor_id' => $auditor->id, 'action' => 'config.updated']);

        $this->actingAsMfaVerified($auditor)
            ->getJson('/api/audit/export')
            ->assertOk()
            ->assertJsonStructure(['data']);

        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $auditor->id,
            'action' => AuditEvent::ACTION_AUDIT_EXPORTED,
        ]);
    }

    // ------------------------------------------------------------------
    // AC3 — unauthorized access denied and recorded
    // ------------------------------------------------------------------

    public function test_unauthorized_user_cannot_view_audit_details()
    {
        $this->seedEvent(['action' => 'role.updated']);

        $outsider = User::factory()->create();

        $this->actingAsMfaVerified($outsider)
            ->getJson('/api/audit')
            ->assertForbidden()
            ->assertJson(['message' => 'Forbidden.']);

        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $outsider->id,
            'action' => AuditEvent::ACTION_AUDIT_ACCESS_DENIED,
        ]);
    }

    public function test_branch_auditor_cannot_view_events_outside_scope()
    {
        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'IDX-HQ']);
        $branchA = Organization::create(['name' => 'Branch A', 'type' => 'branch', 'identifier' => 'IDX-A', 'parent_id' => $hq->id]);
        $branchB = Organization::create(['name' => 'Branch B', 'type' => 'branch', 'identifier' => 'IDX-B', 'parent_id' => $hq->id]);

        $auditor = $this->branchActor($branchA);
        $role = Role::create(['name' => 'branch_auditor']);
        RolePermission::create([
            'role_id' => $role->id,
            'scope_type' => 'branch',
            'scope_id' => $branchA->id,
            'action' => 'audit.read',
        ]);
        RoleAssignment::create(['user_id' => $auditor->id, 'role_id' => $role->id, 'granted_by' => $auditor->id]);

        $otherEvent = $this->seedEvent(['branch_id' => $branchB->id, 'action' => 'movements.approved']);

        $this->actingAsMfaVerified($auditor)
            ->getJson('/api/audit/' . $otherEvent->id)
            ->assertForbidden();
    }

    // ------------------------------------------------------------------
    // AC4 — immutability within retention window
    // ------------------------------------------------------------------

    public function test_audit_records_cannot_be_modified_or_deleted()
    {
        $event = $this->seedEvent(['action' => 'config.updated']);

        $this->expectException(AuditImmutabilityException::class);
        $event->update(['action' => 'tampered']);
    }

    public function test_audit_records_outside_supported_paths_remain_immutable()
    {
        $auditor = $this->hqActor();
        $this->grantAudit($auditor);
        $event = $this->seedEvent(['action' => 'config.updated']);

        $this->actingAsMfaVerified($auditor)
            ->getJson('/api/audit')
            ->assertOk();

        try {
            $event->delete();
            $this->fail('Expected AuditImmutabilityException');
        } catch (AuditImmutabilityException) {
            $this->assertDatabaseHas('audit_events', ['id' => $event->id]);
        }
    }
}
