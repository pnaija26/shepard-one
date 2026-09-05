<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\ChurchGroup;
use App\Models\ChurchGroupMembership;
use App\Models\Member;
use App\Models\Organization;
use App\Models\PrayerRequest;
use App\Models\PrayerRequestConfidentialityEvent;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Story 8.3: Submit a Prayer Request with Confidentiality.
 */
class PrayerRequestTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'PRY-HQ']);
        $this->branch = Organization::create(['name' => 'Branch A', 'type' => 'branch', 'identifier' => 'PRY-A', 'parent_id' => $hq->id]);
    }

    private function grant(User $user, array $actions): void
    {
        $role = Role::create(['name' => 'pry_' . $user->id . '_' . substr(md5(implode(',', $actions)), 0, 6)]);
        foreach ($actions as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);
    }

    private function linkedMember(User $user, string $suffix): Member
    {
        return Member::create([
            'membership_id' => 'PRY-' . $suffix,
            'branch_id' => $this->branch->id,
            'user_id' => $user->id,
            'registration_channel' => 'web',
            'first_name' => 'Pray',
            'last_name' => 'Member' . $suffix,
            'consent_data_processing' => true,
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
        ]);
    }

    private function memberUser(array $actions = ['prayer.requests.submit.self', 'prayer.requests.read.self']): array
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $this->grant($user, $actions);
        $member = $this->linkedMember($user, (string) $user->id);

        return [$user, $member];
    }

    // ------------------------------------------------------------------
    // AC1 — submit with confidentiality; audience-only discovery
    // ------------------------------------------------------------------

    public function test_member_submits_prayer_request_with_encrypted_body(): void
    {
        [$user] = $this->memberUser();

        $request = $this->actingAsMfaVerified($user)
            ->postJson('/api/me/prayer-requests', [
                'category' => 'healing',
                'priority' => 'high',
                'request_body' => 'Please pray for recovery after surgery.',
                'confidentiality' => 'prayer_team',
                'consent_prayer_processing' => true,
                'consent_sharing' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.category', 'healing')
            ->assertJsonPath('data.confidentiality', 'prayer_team')
            ->assertJsonPath('data.request_body', 'Please pray for recovery after surgery.')
            ->json('data');

        $raw = DB::table('prayer_requests')->where('id', $request['id'])->first();
        $this->assertNotSame('Please pray for recovery after surgery.', $raw->request_body);
        $this->assertStringContainsString('eyJpdiI6', (string) $raw->request_body);
    }

    public function test_only_eligible_audiences_can_discover_content(): void
    {
        [$owner] = $this->memberUser();
        [$teamUser] = $this->memberUser(['prayer.requests.read.prayer_team']);
        [$pastor] = $this->memberUser(['prayer.requests.read.pastor']);
        [$outsider] = $this->memberUser(['prayer.requests.submit.self', 'prayer.requests.read.self']);

        $privateId = $this->actingAsMfaVerified($owner)
            ->postJson('/api/me/prayer-requests', [
                'category' => 'family',
                'priority' => 'normal',
                'request_body' => 'Private family need for prayer only.',
                'confidentiality' => 'private',
                'consent_prayer_processing' => true,
            ])
            ->assertCreated()
            ->json('data.id');

        $pastorOnlyId = $this->actingAsMfaVerified($owner)
            ->postJson('/api/me/prayer-requests', [
                'category' => 'guidance',
                'priority' => 'normal',
                'request_body' => 'Sensitive guidance request for pastor.',
                'confidentiality' => 'pastor_only',
                'consent_prayer_processing' => true,
            ])
            ->assertCreated()
            ->json('data.id');

        $teamId = $this->actingAsMfaVerified($owner)
            ->postJson('/api/me/prayer-requests', [
                'category' => 'healing',
                'priority' => 'normal',
                'request_body' => 'Healing request for the prayer team.',
                'confidentiality' => 'prayer_team',
                'consent_prayer_processing' => true,
                'consent_sharing' => true,
            ])
            ->assertCreated()
            ->json('data.id');

        $teamList = $this->actingAsMfaVerified($teamUser)
            ->getJson('/api/prayer-requests')
            ->assertOk()
            ->json('data');
        $teamIds = collect($teamList)->pluck('id')->all();
        $this->assertContains($teamId, $teamIds);
        $this->assertNotContains($privateId, $teamIds);
        $this->assertNotContains($pastorOnlyId, $teamIds);

        $pastorList = $this->actingAsMfaVerified($pastor)
            ->getJson('/api/prayer-requests')
            ->assertOk()
            ->json('data');
        $pastorIds = collect($pastorList)->pluck('id')->all();
        $this->assertContains($pastorOnlyId, $pastorIds);
        $this->assertContains($teamId, $pastorIds);
        $this->assertNotContains($privateId, $pastorIds);

        $this->actingAsMfaVerified($outsider)
            ->getJson("/api/prayer-requests/{$privateId}")
            ->assertForbidden();

        $this->assertDatabaseHas('audit_events', [
            'action' => 'prayer_request.access_denied',
            'subject_id' => $privateId,
            'actor_id' => $outsider->id,
            'category' => AuditEvent::CATEGORY_SECURITY,
        ]);
    }

    public function test_assisted_submission_requires_submit_permission(): void
    {
        [$assistant] = $this->memberUser(['prayer.requests.submit', 'prayer.requests.read.pastor']);
        $beneficiary = Member::create([
            'membership_id' => 'PRY-BEN',
            'branch_id' => $this->branch->id,
            'registration_channel' => 'web',
            'first_name' => 'Beneficiary',
            'last_name' => 'Person',
            'consent_data_processing' => true,
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
        ]);

        $this->actingAsMfaVerified($assistant)
            ->postJson('/api/prayer-requests', [
                'assisted' => true,
                'requester_member_id' => $beneficiary->id,
                'branch_id' => $this->branch->id,
                'category' => 'provision',
                'priority' => 'normal',
                'request_body' => 'Assisted prayer request for this member.',
                'confidentiality' => 'prayer_team',
                'consent_prayer_processing' => true,
                'consent_sharing' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.assisted_submission', true);
    }

    public function test_group_scope_requires_active_group_membership(): void
    {
        [$user, $member] = $this->memberUser();
        $group = ChurchGroup::create([
            'branch_id' => $this->branch->id,
            'name' => 'Cell A',
            'group_type' => 'cell',
            'status' => ChurchGroup::STATUS_ACTIVE,
            'leaders' => [],
            'meeting_pattern' => [
                'frequency' => 'weekly',
                'day' => 'wednesday',
                'start_time' => '19:00',
                'end_time' => '20:30',
            ],
        ]);

        $this->actingAsMfaVerified($user)
            ->postJson('/api/me/prayer-requests', [
                'category' => 'thanksgiving',
                'priority' => 'low',
                'request_body' => 'Group thanksgiving request.',
                'confidentiality' => 'group',
                'church_group_id' => $group->id,
                'consent_prayer_processing' => true,
                'consent_sharing' => true,
            ])
            ->assertStatus(422);

        ChurchGroupMembership::create([
            'church_group_id' => $group->id,
            'member_id' => $member->id,
            'role' => ChurchGroupMembership::ROLE_MEMBER,
            'status' => ChurchGroupMembership::STATUS_ACTIVE,
            'effective_from' => now()->toDateString(),
        ]);

        $this->actingAsMfaVerified($user)
            ->postJson('/api/me/prayer-requests', [
                'category' => 'thanksgiving',
                'priority' => 'low',
                'request_body' => 'Group thanksgiving request.',
                'confidentiality' => 'group',
                'church_group_id' => $group->id,
                'consent_prayer_processing' => true,
                'consent_sharing' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.church_group_id', $group->id);
    }

    // ------------------------------------------------------------------
    // AC2 — narrow confidentiality / withdraw public exposure
    // ------------------------------------------------------------------

    public function test_member_can_narrow_confidentiality_immediately_for_discovery(): void
    {
        [$owner] = $this->memberUser();
        [$publicReader] = $this->memberUser([
            'prayer.requests.submit.self',
            'prayer.requests.read.self',
            'prayer.requests.read.public',
        ]);

        $id = $this->actingAsMfaVerified($owner)
            ->postJson('/api/me/prayer-requests', [
                'category' => 'thanksgiving',
                'priority' => 'normal',
                'request_body' => 'Public testimony of answered prayer.',
                'confidentiality' => 'public_testimony',
                'consent_prayer_processing' => true,
                'consent_sharing' => true,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($publicReader)
            ->getJson('/api/prayer-requests')
            ->assertOk();
        $this->assertContains($id, collect(
            $this->actingAsMfaVerified($publicReader)->getJson('/api/prayer-requests')->json('data')
        )->pluck('id')->all());

        $updated = $this->actingAsMfaVerified($owner)
            ->postJson("/api/prayer-requests/{$id}/confidentiality", [
                'confidentiality' => 'prayer_team',
                'reason' => 'Prefer prayer team only going forward.',
            ])
            ->assertOk()
            ->assertJsonPath('data.confidentiality', 'prayer_team')
            ->assertJsonPath('data.previous_confidentiality', 'public_testimony')
            ->assertJsonPath('data.propagation_pending', true)
            ->json('data');

        $this->assertNotNull($updated['propagation_completed_at']);

        $afterIds = collect(
            $this->actingAsMfaVerified($publicReader)->getJson('/api/prayer-requests')->assertOk()->json('data')
        )->pluck('id')->all();
        $this->assertNotContains($id, $afterIds);

        $this->assertDatabaseHas('prayer_request_confidentiality_events', [
            'prayer_request_id' => $id,
            'change_type' => PrayerRequestConfidentialityEvent::TYPE_NARROWED,
            'from_confidentiality' => 'public_testimony',
            'to_confidentiality' => 'prayer_team',
        ]);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'prayer_request.confidentiality_narrowed',
            'subject_id' => $id,
        ]);
    }

    public function test_cannot_broaden_confidentiality_after_submission(): void
    {
        [$owner] = $this->memberUser();

        $id = $this->actingAsMfaVerified($owner)
            ->postJson('/api/me/prayer-requests', [
                'category' => 'protection',
                'priority' => 'normal',
                'request_body' => 'Private protection prayer.',
                'confidentiality' => 'private',
                'consent_prayer_processing' => true,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($owner)
            ->postJson("/api/prayer-requests/{$id}/confidentiality", [
                'confidentiality' => 'public_testimony',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'cannot_broaden');
    }

    public function test_withdraw_ends_public_exposure_but_keeps_audit_history(): void
    {
        [$owner] = $this->memberUser();
        [$publicReader] = $this->memberUser([
            'prayer.requests.read.public',
            'prayer.requests.submit.self',
            'prayer.requests.read.self',
        ]);

        $id = $this->actingAsMfaVerified($owner)
            ->postJson('/api/me/prayer-requests', [
                'category' => 'thanksgiving',
                'priority' => 'normal',
                'request_body' => 'Testimony to withdraw later.',
                'confidentiality' => 'public_testimony',
                'consent_prayer_processing' => true,
                'consent_sharing' => true,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($owner)
            ->postJson("/api/prayer-requests/{$id}/withdraw", [
                'reason' => 'No longer wish this to be public.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', PrayerRequest::STATUS_WITHDRAWN)
            ->assertJsonPath('data.confidentiality', 'private');

        $ids = collect(
            $this->actingAsMfaVerified($publicReader)->getJson('/api/prayer-requests')->assertOk()->json('data')
        )->pluck('id')->all();
        $this->assertNotContains($id, $ids);

        $this->assertDatabaseHas('prayer_request_confidentiality_events', [
            'prayer_request_id' => $id,
            'change_type' => PrayerRequestConfidentialityEvent::TYPE_WITHDRAWN,
        ]);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'prayer_request.withdrawn',
            'subject_id' => $id,
            'category' => AuditEvent::CATEGORY_SECURITY,
        ]);
    }
}
