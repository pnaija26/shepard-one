<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Member;
use App\Models\Organization;
use App\Models\ReportSchedule;
use App\Models\ReportScheduleDelivery;
use App\Models\ReportScheduleRun;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Story 13.5: schedule and distribute authorized reports.
 */
class ReportScheduleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'RS-HQ']);
        $this->branch = Organization::create([
            'name' => 'Branch A',
            'type' => 'branch',
            'identifier' => 'RS-A',
            'parent_id' => $hq->id,
        ]);
    }

    /**
     * @param  list<string>  $actions
     */
    private function grant(User $user, array $actions): void
    {
        $role = Role::create(['name' => 'rs_' . $user->id . '_' . substr(md5(implode(',', $actions)), 0, 6)]);
        foreach ($actions as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);
    }

    private function scheduler(array $extra = []): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $this->grant($user, array_merge([
            'reports.schedule.read',
            'reports.schedule.manage',
            'reports.export',
            'reports.custom.read',
            'reports.custom.manage',
            'reports.custom.publish',
            'members.read',
        ], $extra));

        return $user;
    }

    /**
     * @return array{id: int}
     */
    private function publishMemberReport(User $designer): array
    {
        Member::create([
            'membership_id' => 'RS-M-001',
            'branch_id' => $this->branch->id,
            'registration_channel' => 'web',
            'first_name' => 'Ada',
            'last_name' => 'Member',
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
            'consent_data_processing' => true,
        ]);

        $definition = [
            'data_source' => 'members',
            'fields' => ['membership_id', 'first_name', 'last_name'],
            'filters' => [['type' => 'membership_stage', 'value' => 'member']],
            'group_by' => [],
            'sort' => [['field' => 'membership_id', 'direction' => 'asc']],
            'calculations' => [],
            'joins' => [],
        ];

        $created = $this->actingAsMfaVerified($designer)
            ->postJson('/api/custom-reports', [
                'name' => 'Scheduled members',
                'branch_id' => $this->branch->id,
                'definition' => $definition,
            ])
            ->assertCreated()
            ->json('data');

        $id = $created['id'];

        $this->actingAsMfaVerified($designer)
            ->postJson("/api/custom-reports/{$id}/publish")
            ->assertOk();

        return ['id' => $id];
    }

    public function test_owner_can_save_schedule_and_overly_broad_distribution_is_rejected(): void
    {
        $owner = $this->scheduler();
        $report = $this->publishMemberReport($owner);

        $recipients = [];
        for ($i = 0; $i < 4; $i++) {
            $recipient = $this->privilegedUser(['branch_id' => $this->branch->id, 'email' => "r{$i}@example.com"]);
            $this->grant($recipient, ['reports.export', 'reports.custom.read', 'members.read']);
            $recipients[] = $recipient->id;
        }

        $this->actingAsMfaVerified($owner)
            ->postJson('/api/report-schedules', [
                'name' => 'Too broad',
                'report_type' => 'custom',
                'custom_report_id' => $report['id'],
                'format' => 'csv',
                'delivery_channel' => 'email',
                'timezone' => 'UTC',
                'recurrence' => 'daily',
                'classification' => 'confidential',
                'recipient_user_ids' => $recipients,
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'classification_recipient_limit');

        $validRecipient = $recipients[0];

        $created = $this->actingAsMfaVerified($owner)
            ->postJson('/api/report-schedules', [
                'name' => 'Weekly members',
                'report_type' => 'custom',
                'custom_report_id' => $report['id'],
                'format' => 'csv',
                'delivery_channel' => 'in_app',
                'timezone' => 'UTC',
                'recurrence' => 'daily',
                'classification' => 'internal',
                'recipient_user_ids' => [$validRecipient],
                'filters' => ['branch_id' => $this->branch->id],
            ])
            ->assertCreated()
            ->json('data');

        $this->assertNotEmpty($created['next_run_at']);
        $this->assertSame('active', $created['status']);
        $this->assertTrue(
            AuditEvent::query()->where('action', 'report_schedule.created')->exists()
        );
    }

    public function test_due_run_checks_permissions_idempotently_and_audits_delivery(): void
    {
        $owner = $this->scheduler();
        $report = $this->publishMemberReport($owner);

        $eligible = $this->privilegedUser(['branch_id' => $this->branch->id, 'email' => 'eligible@example.com']);
        $this->grant($eligible, ['reports.export', 'reports.custom.read', 'members.read']);

        $ineligible = $this->privilegedUser(['branch_id' => $this->branch->id, 'email' => 'ineligible@example.com']);
        $this->grant($ineligible, ['reports.custom.read', 'members.read']);

        $schedule = $this->actingAsMfaVerified($owner)
            ->postJson('/api/report-schedules', [
                'name' => 'Daily members',
                'report_type' => 'custom',
                'custom_report_id' => $report['id'],
                'format' => 'csv',
                'delivery_channel' => 'email',
                'timezone' => 'UTC',
                'recurrence' => 'daily',
                'classification' => 'internal',
                'recipient_user_ids' => [$eligible->id],
            ])
            ->assertCreated()
            ->json('data');

        $model = ReportSchedule::query()->findOrFail($schedule['id']);
        $model->update(['recipient_user_ids' => [$eligible->id, $ineligible->id]]);
        $dueAt = now()->subMinutes(5);
        $model->update(['next_run_at' => $dueAt]);

        Artisan::call('reports:process-due');

        $run = ReportScheduleRun::query()->where('report_schedule_id', $model->id)->first();
        $this->assertNotNull($run);
        $this->assertSame(ReportScheduleRun::STATUS_COMPLETED, $run->status);
        $this->assertNotNull($run->report_export_id);

        $deliveries = ReportScheduleDelivery::query()->where('report_schedule_run_id', $run->id)->get();
        $this->assertCount(2, $deliveries);
        $this->assertSame(1, $deliveries->where('status', ReportScheduleDelivery::STATUS_DELIVERED)->count());
        $this->assertSame(1, $deliveries->where('status', ReportScheduleDelivery::STATUS_BLOCKED)->count());

        $this->assertTrue(
            AuditEvent::query()->where('action', 'report_schedule.run_completed')->exists()
        );

        $model->refresh();
        $model->update(['next_run_at' => $dueAt]);

        Artisan::call('reports:process-due');

        $this->assertSame(1, ReportScheduleRun::query()->where('report_schedule_id', $model->id)->count());
        $this->assertSame(1, ReportScheduleDelivery::query()->where('status', ReportScheduleDelivery::STATUS_DELIVERED)->count());
    }
}
