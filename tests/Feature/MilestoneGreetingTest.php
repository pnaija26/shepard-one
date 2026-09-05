<?php

namespace Tests\Feature;

use App\Models\CommunicationDelivery;
use App\Models\Member;
use App\Models\MemberNotification;
use App\Models\MessageTemplate;
use App\Models\MilestoneGreetingEvaluation;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 10.4: Automate Birthday and Anniversary Greetings.
 */
class MilestoneGreetingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'MS-HQ']);
        $this->branch = Organization::create(['name' => 'Branch A', 'type' => 'branch', 'identifier' => 'MS-A', 'parent_id' => $hq->id]);
        config(['communications.quiet_hours.enabled' => false]);
    }

    private function grant(User $user, array $actions): void
    {
        $role = Role::create(['name' => 'ms_' . $user->id . '_' . substr(md5(implode(',', $actions)), 0, 6)]);
        foreach ($actions as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);
    }

    private function officer(): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $this->grant($user, [
            'milestone_greetings.read',
            'milestone_greetings.manage',
            'milestone_greetings.process',
            'message_templates.read',
            'message_templates.manage',
            'message_templates.publish',
            'communications.read',
            'communications.send',
            'communications.process',
        ]);

        Member::create([
            'membership_id' => 'MS-OFF-' . $user->id,
            'branch_id' => $this->branch->id,
            'user_id' => $user->id,
            'registration_channel' => 'web',
            'first_name' => 'Officer',
            'last_name' => 'User' . $user->id,
            'email' => $user->email,
            'consent_data_processing' => true,
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
        ]);

        return $user;
    }

    private function member(array $overrides = []): Member
    {
        $user = User::factory()->create([
            'branch_id' => $this->branch->id,
            'email' => $overrides['email'] ?? ('ms' . uniqid() . '@church.test'),
        ]);

        return Member::create(array_merge([
            'membership_id' => 'MS-M-' . $user->id,
            'branch_id' => $this->branch->id,
            'user_id' => $user->id,
            'registration_channel' => 'web',
            'first_name' => 'Pat',
            'last_name' => 'Member' . $user->id,
            'preferred_name' => 'Pat',
            'email' => $user->email,
            'phone' => '+1555' . str_pad((string) $user->id, 7, '0', STR_PAD_LEFT),
            'date_of_birth' => Carbon::parse('1990-08-31'),
            'consent_data_processing' => true,
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
            'communication_preferences' => [
                'email' => true,
                'in_app' => true,
                'sms' => true,
            ],
        ], $overrides));
    }

    private function publishBirthdayTemplate(User $admin): int
    {
        $id = $this->actingAsMfaVerified($admin)
            ->postJson('/api/message-templates', [
                'name' => 'Birthday auto',
                'scenario' => 'birthday',
                'channel' => 'email',
                'subject' => 'Happy birthday {{first_name}}!',
                'body' => 'Dear {{preferred_name}}, blessings from {{branch_name}}.',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($admin)
            ->postJson("/api/message-templates/{$id}/publish")
            ->assertOk();

        return $id;
    }

    public function test_process_window_sends_once_and_builds_approved_list(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-31 10:00:00'));
        $officer = $this->officer();
        $birthdayMember = $this->member(['date_of_birth' => '1990-08-31']);
        $otherDay = $this->member(['date_of_birth' => '1990-07-01', 'email' => 'otherday@church.test']);

        $templateId = $this->publishBirthdayTemplate($officer);

        $this->actingAsMfaVerified($officer)
            ->postJson('/api/milestone-greetings/configs', [
                'branch_id' => $this->branch->id,
                'milestone_type' => 'birthday',
                'message_template_id' => $templateId,
                'channels' => ['email', 'in_app'],
                'enabled' => true,
                'team_alerts_enabled' => true,
                'team_alert_permission' => 'communications.read',
            ])
            ->assertOk()
            ->assertJsonPath('data.milestone_type', 'birthday');

        $first = $this->actingAsMfaVerified($officer)
            ->postJson('/api/milestone-greetings/process', ['on' => '2026-08-31'])
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $first['sent']);
        $this->assertSame(1, count($first['list']));
        $this->assertSame($birthdayMember->id, $first['list'][0]['member_id']);
        $this->assertSame('08-31', $first['list'][0]['occurrence_date']);
        $this->assertArrayNotHasKey('email', $first['list'][0]);
        $this->assertArrayNotHasKey('date_of_birth', $first['list'][0]);

        $this->assertDatabaseHas('milestone_greeting_evaluations', [
            'member_id' => $birthdayMember->id,
            'milestone_type' => 'birthday',
            'period_key' => '2026',
            'outcome' => 'sent',
        ]);

        $this->assertGreaterThan(0, CommunicationDelivery::query()
            ->where('member_id', $birthdayMember->id)
            ->where('status', 'sent')
            ->whereNotNull('message_template_version_id')
            ->count());

        $this->assertGreaterThan(0, MemberNotification::query()
            ->where('type', 'birthday.team_alert')
            ->count());

        // Second run same period — no duplicate send
        $second = $this->actingAsMfaVerified($officer)
            ->postJson('/api/milestone-greetings/process', ['on' => '2026-08-31'])
            ->assertOk()
            ->json('data');

        $this->assertSame(0, $second['sent']);
        $this->assertSame(1, MilestoneGreetingEvaluation::query()->where('member_id', $birthdayMember->id)->count());
        $this->assertSame(0, MilestoneGreetingEvaluation::query()->where('member_id', $otherDay->id)->count());

        $list = $this->actingAsMfaVerified($officer)
            ->getJson('/api/milestone-greetings/today?on=2026-08-31&type=birthday')
            ->assertOk()
            ->json('data');
        $this->assertCount(1, $list);
        $this->assertSame('08-31', $list[0]['occurrence_date']);

        Carbon::setTestNow();
    }

    public function test_skips_excluded_status_missing_consent_invalid_date_and_missing_destination(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-31 10:00:00'));
        $officer = $this->officer();
        $templateId = $this->publishBirthdayTemplate($officer);

        $this->actingAsMfaVerified($officer)
            ->postJson('/api/milestone-greetings/configs', [
                'branch_id' => $this->branch->id,
                'milestone_type' => 'birthday',
                'message_template_id' => $templateId,
                'channels' => ['email'],
                'enabled' => true,
                'team_alerts_enabled' => false,
            ])
            ->assertOk();

        $deceased = $this->member([
            'date_of_birth' => '1990-08-31',
            'lifecycle_status' => 'deceased',
            'email' => 'deceased@church.test',
        ]);
        $noConsent = $this->member([
            'date_of_birth' => '1990-08-31',
            'consent_data_processing' => false,
            'email' => 'noconsent@church.test',
        ]);
        $noEmail = $this->member([
            'date_of_birth' => '1990-08-31',
            'email' => 'temp@church.test',
            'communication_preferences' => ['email' => true, 'in_app' => false],
        ]);
        Member::query()->where('id', $noEmail->id)->update(['email' => null, 'user_id' => null]);

        // Wedding anniversary without a usable date is set via milestone upsert then cleared
        $weddingMember = $this->member(['email' => 'wedding@church.test', 'date_of_birth' => '1985-01-01']);
        $this->actingAsMfaVerified($officer)
            ->postJson("/api/milestone-greetings/members/{$weddingMember->id}/milestones", [
                'type' => 'wedding',
                'occurred_on' => '2010-08-31',
            ])
            ->assertOk();

        // Anniversary template + config
        $annId = $this->actingAsMfaVerified($officer)
            ->postJson('/api/message-templates', [
                'name' => 'Anniversary auto',
                'scenario' => 'anniversary',
                'channel' => 'email',
                'subject' => 'Happy anniversary {{first_name}}',
                'body' => '{{years}} years with {{branch_name}}',
            ])
            ->json('data.id');
        $this->actingAsMfaVerified($officer)->postJson("/api/message-templates/{$annId}/publish")->assertOk();
        $this->actingAsMfaVerified($officer)
            ->postJson('/api/milestone-greetings/configs', [
                'branch_id' => $this->branch->id,
                'milestone_type' => 'wedding',
                'message_template_id' => $annId,
                'channels' => ['email'],
                'enabled' => true,
                'team_alerts_enabled' => false,
            ])
            ->assertOk();

        $result = $this->actingAsMfaVerified($officer)
            ->postJson('/api/milestone-greetings/process', ['on' => '2026-08-31'])
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $result['sent']); // wedding only
        $this->assertGreaterThanOrEqual(3, $result['skipped']);

        $reasons = MilestoneGreetingEvaluation::query()
            ->whereIn('member_id', [$deceased->id, $noConsent->id, $noEmail->id])
            ->pluck('skip_reason')
            ->all();
        $this->assertContains('excluded_status', $reasons);
        $this->assertContains('missing_consent', $reasons);
        $this->assertContains('missing_destination', $reasons);

        $evals = $this->actingAsMfaVerified($officer)
            ->getJson('/api/milestone-greetings/evaluations?outcome=skipped')
            ->assertOk()
            ->json('data');
        $this->assertNotEmpty($evals);
        foreach ($evals as $row) {
            $this->assertArrayNotHasKey('email', $row['result'] ?? []);
        }

        Carbon::setTestNow();
    }
}
