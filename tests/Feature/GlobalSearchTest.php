<?php

namespace Tests\Feature;

use App\Models\ChurchSearchEntry;
use App\Models\ChurchSearchSyncFailure;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Story 14.3: search church records within effective permissions.
 */
class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branchA;

    private Organization $branchB;

    protected function setUp(): void
    {
        parent::setUp();

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'GS-HQ']);
        $this->branchA = Organization::create(['name' => 'Branch A', 'type' => 'branch', 'identifier' => 'GS-A', 'parent_id' => $hq->id]);
        $this->branchB = Organization::create(['name' => 'Branch B', 'type' => 'branch', 'identifier' => 'GS-B', 'parent_id' => $hq->id]);
    }

    /**
     * @param  list<string>  $actions
     */
    private function grant(User $user, array $actions): void
    {
        $role = Role::create(['name' => 'gs_' . $user->id . '_' . substr(md5(implode(',', $actions)), 0, 6)]);
        foreach ($actions as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);
    }

    private function searcher(): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branchA->id]);
        $this->grant($user, ['search.global', 'members.read']);

        return $user;
    }

    private function operator(): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branchA->id]);
        $this->grant($user, ['search.global', 'search.sync', 'members.read']);

        return $user;
    }

    private function member(Organization $branch, string $firstName, string $suffix): Member
    {
        return Member::create([
            'membership_id' => 'GS-' . $suffix,
            'branch_id' => $branch->id,
            'registration_channel' => 'web',
            'first_name' => $firstName,
            'last_name' => 'Searchable',
            'consent_data_processing' => true,
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
        ]);
    }

    public function test_search_returns_grouped_permitted_results_and_excludes_other_branches(): void
    {
        $searcher = $this->searcher();
        $ada = $this->member($this->branchA, 'Ada', 'A1');
        $this->member($this->branchB, 'Bob', 'B1');

        Artisan::call('search:rebuild-index');

        $response = $this->actingAsMfaVerified($searcher)
            ->getJson('/api/global-search?q=Ada')
            ->assertOk()
            ->json('data');

        $this->assertTrue($response['within_target']);
        $this->assertGreaterThan(0, $response['total_results']);
        $this->assertStringContainsString('Ada', $response['groups'][0]['items'][0]['title']);
        $this->assertSame('member', $response['groups'][0]['record_type']);
        $this->assertSame($ada->id, $response['groups'][0]['items'][0]['record_id']);

        $this->actingAsMfaVerified($searcher)
            ->getJson('/api/global-search?q=Bob')
            ->assertOk()
            ->assertJsonPath('data.total_results', 0);

        $this->actingAsMfaVerified($searcher)
            ->getJson("/api/global-search/resolve/member/{$ada->id}")
            ->assertOk()
            ->assertJsonPath('data.authorized', true);
    }

    public function test_stale_index_cannot_grant_access_and_sync_failures_are_retryable(): void
    {
        $searcher = $this->searcher();
        $operator = $this->operator();
        $ada = $this->member($this->branchA, 'Stale', 'S1');

        Artisan::call('search:rebuild-index');
        $this->assertDatabaseHas('church_search_entries', [
            'record_type' => 'member',
            'record_id' => $ada->id,
            'status' => ChurchSearchEntry::STATUS_ACTIVE,
        ]);

        $ada->update(['archived_at' => now()]);

        $this->actingAsMfaVerified($searcher)
            ->getJson('/api/global-search?q=Stale')
            ->assertOk()
            ->assertJsonPath('data.total_results', 0);

        $this->actingAsMfaVerified($searcher)
            ->getJson("/api/global-search/resolve/member/{$ada->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('audit_events', [
            'action' => 'global_search.access_denied',
        ]);

        ChurchSearchSyncFailure::create([
            'record_type' => 'member',
            'record_id' => $ada->id,
            'operation' => ChurchSearchSyncFailure::OPERATION_DELETE,
            'error_message' => 'Simulated stale index cleanup failure',
            'status' => ChurchSearchSyncFailure::STATUS_PENDING,
            'next_retry_at' => now()->subMinute(),
        ]);

        $this->actingAsMfaVerified($operator)
            ->getJson('/api/global-search/sync-failures')
            ->assertOk()
            ->assertJsonPath('data.0.record_type', 'member');

        $this->actingAsMfaVerified($operator)
            ->postJson('/api/global-search/process-retries')
            ->assertOk()
            ->assertJsonPath('data.resolved', 1);

        $this->assertDatabaseHas('church_search_entries', [
            'record_type' => 'member',
            'record_id' => $ada->id,
            'status' => ChurchSearchEntry::STATUS_DELETED,
        ]);
    }
}
