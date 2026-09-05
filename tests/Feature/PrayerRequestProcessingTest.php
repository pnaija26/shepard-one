<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\ChurchGroup;
use App\Models\ChurchGroupMembership;
use App\Models\Member;
use App\Models\MemberNotification;
use App\Models\Organization;
use App\Models\PrayerRequest;
use App\Models\PrayerRequestActivity;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 8.4: Process Prayer Requests Safely.
 */
class PrayerRequestProcessingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;

    private Organization $otherBranch;

    protected function setUp(): void
    {
        parent::setUp();

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'PRY4-HQ']);
        $this->branch = Organization::create(['name' => 'Branch A', 'type' => 'branch', 'identifier' => 'PRY4-A', 'parent_id' => $hq->id]);
        $this->otherBranch = Organization::create(['name' => 'Branch B', 'type' => 'branch', 'identifier' => 'PRY4-B', 'parent_id' => $hq->id]);
    }

    private function grant(User $user, array $actions): void
    {
        $role = Role::create(['name' => 'pry4_' . $user->id . '_' . substr(md5(implode(',', $actions)), 0, 6)]);
        foreach ($actions as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);
    }

    private function linkedMember(User $user, string $suffix, ?Organization $branch = null): Member
    {
        return Member::create([
            'membership_id' => 'PRY4-' . $suffix,
            'branch_id' => ($branch ?? $this->branch)->id,
            'user_id' => $user->id,
            'registration_channel' => 'web',
            'first_name' => 'Pray',
            'last_name' => 'Member' . $suffix,
            'consent_data_processing' => true,
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
        ]);
    }

    /**
     * @param  array<int, string>  $actions
     * @return array{0: User, 1: Member}
     */
    private function memberUser(array $actions, ?Organization $branch = null): array
    {
        $user = $this->privilegedUser(['branch_id' => ($branch ?? $this->branch)->id]);
        $this->grant($user, $actions);
        $member = $this->linkedMember($user, (string) $user->id, $branch);

        return [$user, $member];
    }

    private function submitTeamRequest(User $owner): int
    {
        return $this->actingAsMfaVerified($owner)
            ->postJson('/api/me/prayer-requests', [
                'category' => 'healing',
                'priority' => 'high',
                'request_body' => 'Please pray for recovery after surgery.',
                'confidentiality' => 'prayer_team',
                'consent_prayer_processing' => true,
                'consent_sharing' => true,
            ])
            ->assertCreated()
            ->json('data.id');
    }

    public function test_team_can_assign_acknowledge_update_escalate_answer_and_close(): void
    {
        [$owner] = $this->memberUser(['prayer.requests.submit.self', 'prayer.requests.read.self']);
        [$leader] = $this->memberUser([
            'prayer.requests.read.prayer_team',
            'prayer.requests.process',
            'prayer.requests.escalate',
        ]);
        [$assignee] = $this->memberUser([
            'prayer.requests.read.prayer_team',
            'prayer.requests.process',
        ]);
        [$pastor] = $this->memberUser([
            'prayer.requests.read.pastor',
            'prayer.requests.process',
            'prayer.requests.escalate',
        ]);

        $id = $this->submitTeamRequest($owner);

        $assigned = $this->actingAsMfaVerified($leader)
            ->postJson("/api/prayer-requests/{$id}/assign", [
                'assignee_id' => $assignee->id,
                'notes' => 'Assigned to evening team.',
            ])
            ->assertOk()
            ->assertJsonPath('data.assigned_officer_id', $assignee->id)
            ->assertJsonPath('data.status', 'acknowledged')
            ->json('data');

        $this->assertNotNull($assigned['assigned_at']);
        $this->assertDatabaseHas('prayer_request_activities', [
            'prayer_request_id' => $id,
            'activity_type' => 'assignment',
            'to_officer_id' => $assignee->id,
        ]);

        $notification = MemberNotification::query()
            ->where('user_id', $assignee->id)
            ->where('type', 'prayer.request.assigned')
            ->first();
        $this->assertNotNull($notification);
        $this->assertStringNotContainsString('recovery after surgery', $notification->message);
        $this->assertArrayNotHasKey('request_body', $notification->metadata ?? []);

        $this->actingAsMfaVerified($assignee)
            ->postJson("/api/prayer-requests/{$id}/acknowledge", [
                'notes' => 'Received and praying.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'acknowledged');

        $this->actingAsMfaVerified($assignee)
            ->postJson("/api/prayer-requests/{$id}/updates", [
                'notes' => 'Continued prayer.',
                'restricted_notes' => 'Sensitive pastoral detail.',
                'status' => 'in_prayer',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_prayer')
            ->assertJsonPath('data.process_notes', 'Sensitive pastoral detail.');

        $this->actingAsMfaVerified($leader)
            ->postJson("/api/prayer-requests/{$id}/escalate", [
                'to_officer_id' => $pastor->id,
                'notes' => 'Needs pastoral attention.',
            ])
            ->assertOk()
            ->assertJsonPath('data.assigned_officer_id', $pastor->id)
            ->assertJsonPath('data.priority', 'high');

        $this->assertNotNull(
            PrayerRequest::query()->find($id)?->escalated_at
        );

        $this->actingAsMfaVerified($pastor)
            ->postJson("/api/prayer-requests/{$id}/answer", [
                'notes' => 'Praise report shared privately.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'answered');

        $closed = $this->actingAsMfaVerified($pastor)
            ->postJson("/api/prayer-requests/{$id}/close", [
                'notes' => 'Closing after answered prayer.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->json('data');

        $this->assertNotNull($closed['closed_at']);
        $this->assertGreaterThanOrEqual(5, PrayerRequestActivity::query()->where('prayer_request_id', $id)->count());

        $ownerMemberId = Member::query()->where('user_id', $owner->id)->value('id');
        $requesterNotice = MemberNotification::query()
            ->where('type', 'prayer.request.closed')
            ->where('member_id', $ownerMemberId)
            ->first();
        $this->assertNotNull($requesterNotice);
        $this->assertStringNotContainsString('recovery after surgery', $requesterNotice->message);
    }

    public function test_assignment_denied_for_private_pastor_only_out_of_branch_and_no_consent(): void
    {
        [$owner] = $this->memberUser(['prayer.requests.submit.self', 'prayer.requests.read.self']);
        [$team] = $this->memberUser([
            'prayer.requests.read.prayer_team',
            'prayer.requests.process',
        ]);
        [$outsider] = $this->memberUser([
            'prayer.requests.read.prayer_team',
            'prayer.requests.process',
        ], $this->otherBranch);

        $privateId = $this->actingAsMfaVerified($owner)
            ->postJson('/api/me/prayer-requests', [
                'category' => 'family',
                'priority' => 'normal',
                'request_body' => 'Private family prayer only.',
                'confidentiality' => 'private',
                'consent_prayer_processing' => true,
                'consent_sharing' => true,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($team)
            ->postJson("/api/prayer-requests/{$privateId}/assign", ['assignee_id' => $team->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'process_denied');

        $this->assertTrue(
            AuditEvent::query()->where('action', 'prayer_request.access_denied')->exists()
        );

        $pastorOnlyId = $this->actingAsMfaVerified($owner)
            ->postJson('/api/me/prayer-requests', [
                'category' => 'guidance',
                'priority' => 'normal',
                'request_body' => 'Pastor-only sensitive request.',
                'confidentiality' => 'pastor_only',
                'consent_prayer_processing' => true,
                'consent_sharing' => true,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($team)
            ->postJson("/api/prayer-requests/{$pastorOnlyId}/assign", ['assignee_id' => $team->id])
            ->assertForbidden();

        $teamId = $this->submitTeamRequest($owner);

        $this->actingAsMfaVerified($outsider)
            ->postJson("/api/prayer-requests/{$teamId}/assign", ['assignee_id' => $outsider->id])
            ->assertForbidden();

        $noShareId = $this->actingAsMfaVerified($owner)
            ->postJson('/api/me/prayer-requests', [
                'category' => 'healing',
                'priority' => 'normal',
                'request_body' => 'Processing yes but sharing withdrawn later.',
                'confidentiality' => 'prayer_team',
                'consent_prayer_processing' => true,
                'consent_sharing' => true,
            ])
            ->assertCreated()
            ->json('data.id');

        PrayerRequest::query()->whereKey($noShareId)->update(['consent_sharing' => false]);

        $this->actingAsMfaVerified($team)
            ->postJson("/api/prayer-requests/{$noShareId}/assign", ['assignee_id' => $team->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'process_denied');

        // No content leak via denied assign responses
        $denied = $this->actingAsMfaVerified($team)
            ->postJson("/api/prayer-requests/{$privateId}/assign", ['assignee_id' => $team->id])
            ->assertStatus(422)
            ->json();
        $this->assertStringNotContainsString('Private family prayer', json_encode($denied));
    }

    public function test_group_publication_denied_for_private_and_without_consent(): void
    {
        [$owner, $ownerMember] = $this->memberUser(['prayer.requests.submit.self', 'prayer.requests.read.self']);
        [$team] = $this->memberUser([
            'prayer.requests.read.prayer_team',
            'prayer.requests.process',
        ]);

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
        ChurchGroupMembership::create([
            'church_group_id' => $group->id,
            'member_id' => $ownerMember->id,
            'role' => ChurchGroupMembership::ROLE_MEMBER,
            'status' => ChurchGroupMembership::STATUS_ACTIVE,
            'effective_from' => now()->toDateString(),
        ]);

        $privateId = $this->actingAsMfaVerified($owner)
            ->postJson('/api/me/prayer-requests', [
                'category' => 'family',
                'priority' => 'normal',
                'request_body' => 'Keep this private forever.',
                'confidentiality' => 'private',
                'consent_prayer_processing' => true,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($team)
            ->postJson("/api/prayer-requests/{$privateId}/publish-to-group")
            ->assertStatus(422);

        $groupId = $this->actingAsMfaVerified($owner)
            ->postJson('/api/me/prayer-requests', [
                'category' => 'thanksgiving',
                'priority' => 'normal',
                'request_body' => 'Group celebration prayer.',
                'confidentiality' => 'group',
                'church_group_id' => $group->id,
                'consent_prayer_processing' => true,
                'consent_sharing' => true,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($team)
            ->postJson("/api/prayer-requests/{$groupId}/publish-to-group", [
                'notes' => 'Shared with cell.',
            ])
            ->assertOk()
            ->assertJsonPath('data.published_to_group', true);

        PrayerRequest::query()->whereKey($groupId)->update([
            'published_to_group' => false,
            'published_to_group_at' => null,
            'consent_sharing' => false,
            'status' => 'submitted',
        ]);

        $this->actingAsMfaVerified($team)
            ->postJson("/api/prayer-requests/{$groupId}/publish-to-group")
            ->assertStatus(422)
            ->assertJsonPath('code', 'process_denied');
    }
}
