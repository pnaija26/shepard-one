<?php

namespace Tests\Feature;

use App\Models\Communication;
use App\Models\CommunicationDelivery;
use App\Models\CommunicationSuppression;
use App\Models\Member;
use App\Models\MemberNotification;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 10.1: Send Permission-Aware Communications.
 */
class CommunicationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'COM-HQ']);
        $this->branch = Organization::create(['name' => 'Branch A', 'type' => 'branch', 'identifier' => 'COM-A', 'parent_id' => $hq->id]);

        config(['communications.quiet_hours.enabled' => false]);
    }

    private function grant(User $user, array $actions): void
    {
        $role = Role::create(['name' => 'com_' . $user->id . '_' . substr(md5(implode(',', $actions)), 0, 6)]);
        foreach ($actions as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);
    }

    private function officer(): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $this->grant($user, [
            'communications.read',
            'communications.send',
            'communications.cancel',
            'communications.process',
        ]);

        return $user;
    }

    private function member(array $overrides = []): Member
    {
        $user = User::factory()->create([
            'branch_id' => $this->branch->id,
            'email' => $overrides['email'] ?? ('member' . uniqid() . '@church.test'),
        ]);

        return Member::create(array_merge([
            'membership_id' => 'COM-M-' . $user->id,
            'branch_id' => $this->branch->id,
            'user_id' => $user->id,
            'registration_channel' => 'web',
            'first_name' => 'Comm',
            'last_name' => 'Member' . $user->id,
            'email' => $user->email,
            'phone' => '+1555' . str_pad((string) $user->id, 7, '0', STR_PAD_LEFT),
            'consent_data_processing' => true,
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
            'communication_preferences' => [
                'email' => true,
                'sms' => true,
                'in_app' => true,
                'push' => true,
                'external' => true,
            ],
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Sunday reminder',
            'subject' => 'Service this Sunday',
            'body' => 'Join us for worship at 10am.',
            'purpose' => 'announcement',
            'channels' => ['email', 'in_app'],
            'audience_type' => 'members',
            'audience_params' => ['member_ids' => []],
            'schedule_type' => 'immediate',
            'branch_id' => $this->branch->id,
        ], $overrides);
    }

    public function test_send_resolves_audience_queues_and_prevents_duplicate_delivery(): void
    {
        $officer = $this->officer();
        $a = $this->member();
        $b = $this->member();

        $created = $this->actingAsMfaVerified($officer)
            ->postJson('/api/communications', $this->payload([
                'audience_params' => ['member_ids' => [$a->id, $b->id]],
            ]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->json('data');

        $this->assertSame(4, $created['queued_count']); // 2 members × 2 channels
        $this->assertSame(4, $created['sent_count']);
        $this->assertSame(1, MemberNotification::query()->where('member_id', $a->id)->count());
        $this->assertSame(1, MemberNotification::query()->where('member_id', $b->id)->count());

        // Re-processing does not duplicate
        $this->actingAsMfaVerified($officer)
            ->postJson('/api/communications/process-due')
            ->assertOk();

        $this->assertSame(4, CommunicationDelivery::query()->where('status', 'sent')->count());
        $this->assertSame(2, MemberNotification::query()->count());

        // List omits body by default
        $list = $this->actingAsMfaVerified($officer)
            ->getJson('/api/communications')
            ->assertOk()
            ->json('data.0');
        $this->assertNull($list['body']);
        $this->assertTrue($list['body_present']);
    }

    public function test_skips_missing_consent_unsubscribe_invalid_destination_and_defers_quiet_hours(): void
    {
        $officer = $this->officer();

        $noConsent = $this->member(['consent_data_processing' => false, 'email' => 'noconsent@church.test']);
        $unsub = $this->member(['email' => 'unsub@church.test']);
        $noEmail = $this->member(['email' => null]);
        Member::query()->where('id', $noEmail->id)->update(['email' => null]);
        $noEmail->refresh();
        $quietTarget = $this->member(['email' => 'quiet@church.test']);

        CommunicationSuppression::create([
            'member_id' => $unsub->id,
            'channel' => 'email',
            'reason' => 'unsubscribe',
            'active' => true,
            'created_by' => $officer->id,
            'suppressed_at' => now(),
        ]);

        // Immediate send for consent/unsub/invalid (quiet hours off)
        $id = $this->actingAsMfaVerified($officer)
            ->postJson('/api/communications', $this->payload([
                'channels' => ['email'],
                'audience_params' => ['member_ids' => [$noConsent->id, $unsub->id, $noEmail->id]],
            ]))
            ->assertCreated()
            ->json('data.id');

        $deliveries = CommunicationDelivery::query()->where('communication_id', $id)->get();
        $reasons = $deliveries->pluck('skip_reason')->all();
        $this->assertContains('missing_consent', $reasons);
        $this->assertContains('unsubscribed', $reasons);
        $this->assertContains('invalid_destination', $reasons);
        $this->assertSame(0, $deliveries->where('status', 'sent')->count());

        // Quiet hours deferral
        config([
            'communications.quiet_hours.enabled' => true,
            'communications.quiet_hours.start' => Carbon::now()->subHour()->format('H:i'),
            'communications.quiet_hours.end' => Carbon::now()->addHour()->format('H:i'),
        ]);

        $quietId = $this->actingAsMfaVerified($officer)
            ->postJson('/api/communications', $this->payload([
                'name' => 'Quiet hours message',
                'channels' => ['email'],
                'audience_params' => ['member_ids' => [$quietTarget->id]],
            ]))
            ->assertCreated()
            ->json('data.id');

        $deferred = CommunicationDelivery::query()->where('communication_id', $quietId)->first();
        $this->assertSame('deferred', $deferred->status);
        $this->assertSame('quiet_hours', $deferred->skip_reason);

        // Status visible without body
        $show = $this->actingAsMfaVerified($officer)
            ->getJson("/api/communications/{$quietId}")
            ->assertOk()
            ->json('data');
        $this->assertNull($show['body']);
        $this->assertNotEmpty($show['deliveries']);
        $this->assertStringNotContainsString('Join us for worship', json_encode($show['deliveries']));
    }

    public function test_blocks_prohibited_content_and_retries_provider_failure_with_batch_limits(): void
    {
        $officer = $this->officer();

        $this->actingAsMfaVerified($officer)
            ->postJson('/api/communications', $this->payload([
                'body' => 'Please send your SSN 123-45-6789 to the office.',
                'audience_params' => ['member_ids' => [$this->member()->id]],
            ]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'prohibited_content');

        $failMember = $this->member(['email' => 'fail@provider.test']);
        $okMembers = [
            $this->member(),
            $this->member(),
            $this->member(),
        ];

        config([
            'communications.batch_size' => 2,
            'communications.rate_limit_per_minute' => 2,
            'communications.provider_quota_per_run' => 2,
            'communications.max_retries' => 2,
        ]);

        $bulk = $this->actingAsMfaVerified($officer)
            ->postJson('/api/communications', $this->payload([
                'name' => 'Bulk notice',
                'channels' => ['email'],
                'audience_params' => [
                    'member_ids' => array_merge([$failMember->id], collect($okMembers)->pluck('id')->all()),
                ],
                'batch_size' => 2,
                'rate_limit_per_minute' => 2,
            ]))
            ->assertCreated()
            ->json('data');

        // First run respects batch of 2
        $this->assertLessThanOrEqual(2, $bulk['sent_count'] + $bulk['failed_count'] + $bulk['skipped_count']);

        // Process remaining
        $this->actingAsMfaVerified($officer)
            ->postJson('/api/communications/process-due')
            ->assertOk();

        $failDelivery = CommunicationDelivery::query()
            ->where('communication_id', $bulk['id'])
            ->where('member_id', $failMember->id)
            ->first();
        $this->assertNotNull($failDelivery);
        $this->assertContains($failDelivery->status, ['retried', 'failed']);
        $this->assertSame('provider_failure', $failDelivery->skip_reason);

        $this->actingAsMfaVerified($officer)
            ->postJson('/api/communications/process-retries')
            ->assertOk();

        $failDelivery->refresh();
        $this->assertContains($failDelivery->status, ['retried', 'failed']);
        $this->assertGreaterThanOrEqual(1, $failDelivery->attempt);

        // Suppression endpoint
        $this->actingAsMfaVerified($officer)
            ->postJson('/api/communications/suppressions', [
                'member_id' => $okMembers[0]->id,
                'channel' => 'email',
                'reason' => 'unsubscribe',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('communication_suppressions', [
            'member_id' => $okMembers[0]->id,
            'channel' => 'email',
            'active' => 1,
        ]);
    }

    public function test_scheduled_communication_waits_until_due(): void
    {
        $officer = $this->officer();
        $member = $this->member();

        $created = $this->actingAsMfaVerified($officer)
            ->postJson('/api/communications', $this->payload([
                'schedule_type' => 'scheduled',
                'scheduled_at' => Carbon::now()->addDay()->toDateTimeString(),
                'channels' => ['email'],
                'audience_params' => ['member_ids' => [$member->id]],
            ]))
            ->assertCreated()
            ->json('data');

        $this->assertSame('queued', $created['status']);
        $this->assertSame(0, CommunicationDelivery::query()->where('communication_id', $created['id'])->where('status', 'sent')->count());

        Communication::query()->where('id', $created['id'])->update([
            'next_run_at' => now()->subMinute(),
            'scheduled_at' => now()->subMinute(),
        ]);

        $this->actingAsMfaVerified($officer)
            ->postJson('/api/communications/process-due')
            ->assertOk()
            ->assertJsonPath('data.sent', 1);

        $this->assertSame(1, CommunicationDelivery::query()->where('communication_id', $created['id'])->where('status', 'sent')->count());
    }
}
