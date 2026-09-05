<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Member;
use App\Models\MemberDirectoryConsentEvent;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Story 2.7: Control My Church Directory Visibility.
 */
class MemberDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'IDX-HQ']);
        $this->branch = Organization::create(['name' => 'Branch A', 'type' => 'branch', 'identifier' => 'IDX-A', 'parent_id' => $hq->id]);
    }

    private function memberUser(array $memberAttrs = []): User
    {
        $user = User::factory()->create(['roles' => ['member'], 'has_mfa_enrolled' => false]);

        Member::create(array_merge([
            'user_id' => $user->id,
            'membership_id' => 'S1-M-' . str_pad((string) $user->id, 6, '0', STR_PAD_LEFT),
            'branch_id' => $this->branch->id,
            'registration_channel' => 'web',
            'first_name' => 'Grace',
            'last_name' => 'Adeyemi',
            'email' => 'grace@example.com',
            'phone' => '08011112222',
            'photo_path' => '/photos/grace.jpg',
            'membership_status' => 'active',
            'lifecycle_status' => 'active',
            'consent_data_processing' => true,
            'consent_directory' => false,
        ], $memberAttrs));

        return $user;
    }

    private function directoryViewer(): User
    {
        $user = User::factory()->create(['roles' => ['member'], 'has_mfa_enrolled' => false]);
        $role = Role::create(['name' => 'directory_reader_' . $user->id]);
        RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => 'directory.read']);
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);

        return $user;
    }

    private function directoryExporter(): User
    {
        $user = $this->directoryViewer();
        $role = Role::create(['name' => 'directory_exporter_' . $user->id]);
        RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => 'directory.export']);
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);

        return $user;
    }

    // ------------------------------------------------------------------
    // AC1 — review eligible fields; forbidden fields not publishable
    // ------------------------------------------------------------------

    public function test_member_can_review_directory_privacy_settings(): void
    {
        $user = $this->memberUser([
            'consent_directory' => true,
            'directory_visibility' => ['phone' => 'congregation', 'email' => 'hidden'],
        ]);
        Sanctum::actingAs($user);

        $this->getJson('/api/me/directory-settings')
            ->assertOk()
            ->assertJsonPath('data.consent_directory', true)
            ->assertJsonFragment(['field' => 'phone', 'visibility' => 'congregation', 'publishable' => true])
            ->assertJsonFragment(['field' => 'email', 'visibility' => 'hidden', 'publishable' => true]);

        $fields = collect($this->getJson('/api/me/directory-settings')->json('data.fields'));
        $forbidden = collect(config('directory.forbidden_fields'));
        $fields->each(function (array $row) use ($forbidden): void {
            if ($forbidden->contains($row['field'])) {
                $this->assertFalse($row['publishable']);
            }
        });
    }

    // ------------------------------------------------------------------
    // AC2 — changes propagate and are auditable
    // ------------------------------------------------------------------

    public function test_visibility_change_is_timestamped_auditable_and_propagates(): void
    {
        config(['directory.propagation_seconds' => 300]);

        $user = $this->memberUser([
            'consent_directory' => true,
            'directory_visibility' => ['phone' => 'hidden', 'email' => 'hidden'],
        ]);
        Sanctum::actingAs($user);

        $this->putJson('/api/me/directory-settings', [
            'consent_directory' => true,
            'visibility' => ['phone' => 'congregation'],
        ])
            ->assertOk()
            ->assertJsonFragment(['field' => 'phone', 'pending_visibility' => 'congregation'])
            ->assertJsonStructure(['data' => ['pending_effective_at']]);

        $member = Member::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('hidden', $member->directory_visibility['phone']);
        $this->assertSame('congregation', $member->directory_visibility_pending['phone']);

        $this->assertDatabaseHas('member_directory_consent_events', [
            'member_id' => $member->id,
            'actor_id' => $user->id,
            'consent_directory' => true,
        ]);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'member.directory_visibility.updated',
            'module' => 'members',
        ]);

        $this->travel(301)->seconds();
        $member->refresh();

        $viewer = $this->directoryViewer();
        Sanctum::actingAs($viewer);

        $this->getJson('/api/directory?search=Grace')
            ->assertOk()
            ->assertJsonPath('data.0.fields.phone', '08011112222');
    }

    public function test_withdrawing_directory_consent_applies_immediately(): void
    {
        $user = $this->memberUser([
            'consent_directory' => true,
            'directory_visibility' => ['phone' => 'congregation', 'email' => 'congregation'],
        ]);

        $viewer = $this->directoryViewer();
        Sanctum::actingAs($viewer);
        $this->getJson('/api/directory?search=Grace')->assertOk()->assertJsonCount(1, 'data');

        Sanctum::actingAs($user);
        $this->putJson('/api/me/directory-settings', ['consent_directory' => false])->assertOk();

        Sanctum::actingAs($viewer);
        $this->getJson('/api/directory?search=Grace')->assertOk()->assertJsonCount(0, 'data');
    }

    // ------------------------------------------------------------------
    // AC3 — directory search/export enforce privacy decisions
    // ------------------------------------------------------------------

    public function test_directory_search_hides_non_consented_members_and_private_fields(): void
    {
        $listed = $this->memberUser([
            'first_name' => 'Listed',
            'last_name' => 'Member',
            'consent_directory' => true,
            'directory_visibility' => [
                'phone' => 'congregation',
                'email' => 'hidden',
                'photo_path' => 'congregation',
            ],
        ]);

        $this->memberUser([
            'user_id' => null,
            'first_name' => 'Private',
            'last_name' => 'Person',
            'email' => 'private@example.com',
            'consent_directory' => false,
        ]);

        $viewer = $this->directoryViewer();
        Sanctum::actingAs($viewer);

        $response = $this->getJson('/api/directory?search=Member')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.full_name', 'Listed Member')
            ->assertJsonPath('data.0.fields.phone', '08011112222');

        $this->assertArrayNotHasKey('email', $response->json('data.0.fields'));

        $this->getJson('/api/directory?search=Private')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_directory_export_uses_same_privacy_filtering_as_search(): void
    {
        $this->memberUser([
            'first_name' => 'Export',
            'last_name' => 'Target',
            'consent_directory' => true,
            'directory_visibility' => [
                'phone' => 'congregation',
                'email' => 'staff',
            ],
        ]);

        $viewer = $this->directoryViewer();
        Sanctum::actingAs($viewer);

        $this->get('/api/directory/export?search=Export')->assertStatus(403);

        $exporter = $this->directoryExporter();
        Sanctum::actingAs($exporter);

        $exportResponse = $this->get('/api/directory/export?search=Export');
        $exportResponse->assertOk()->assertHeader('content-disposition');
        $csv = $exportResponse->streamedContent();

        $this->assertStringContainsString('Export Target', $csv);
        $this->assertStringContainsString('08011112222', $csv);
        $this->assertStringNotContainsString('grace@example.com', $csv);

        $staffRole = Role::create(['name' => 'directory_staff_' . $exporter->id]);
        RolePermission::create(['role_id' => $staffRole->id, 'scope_type' => 'global', 'action' => 'directory.staff']);
        RoleAssignment::create(['user_id' => $exporter->id, 'role_id' => $staffRole->id, 'granted_by' => $exporter->id]);
        app(AuthorizationService::class)->invalidate($exporter);

        $staffCsv = $this->get('/api/directory/export?search=Export')->streamedContent();
        $this->assertStringContainsString('grace@example.com', $staffCsv);
    }
}
