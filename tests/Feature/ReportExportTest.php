<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Member;
use App\Models\Organization;
use App\Models\ReportExport;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Story 13.4: export authorized report results.
 */
class ReportExportTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'RE-HQ']);
        $this->branch = Organization::create([
            'name' => 'Branch A',
            'type' => 'branch',
            'identifier' => 'RE-A',
            'parent_id' => $hq->id,
        ]);
    }

    /**
     * @param  list<string>  $actions
     */
    private function grant(User $user, array $actions): void
    {
        $role = Role::create(['name' => 're_' . $user->id . '_' . substr(md5(implode(',', $actions)), 0, 6)]);
        foreach ($actions as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);
    }

    private function exporter(array $extra = []): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $this->grant($user, array_merge([
            'reports.export',
            'reports.custom.read',
            'reports.custom.manage',
            'reports.custom.publish',
            'members.read',
        ], $extra));

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function publishMemberReport(User $designer): array
    {
        Member::create([
            'membership_id' => 'RE-M-001',
            'branch_id' => $this->branch->id,
            'registration_channel' => 'web',
            'first_name' => '=SUM(A1)',
            'last_name' => 'Export',
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
            'consent_data_processing' => true,
        ]);

        $definition = [
            'data_source' => 'members',
            'fields' => ['membership_id', 'first_name', 'last_name'],
            'filters' => [
                ['type' => 'membership_stage', 'value' => 'member'],
            ],
            'group_by' => [],
            'sort' => [
                ['field' => 'membership_id', 'direction' => 'asc'],
            ],
            'calculations' => [],
            'joins' => [],
        ];

        $created = $this->actingAsMfaVerified($designer)
            ->postJson('/api/custom-reports', [
                'name' => 'Member export test',
                'branch_id' => $this->branch->id,
                'definition' => $definition,
            ])
            ->assertCreated()
            ->json('data');

        $id = $created['id'];

        $publish = $this->actingAsMfaVerified($designer)
            ->postJson("/api/custom-reports/{$id}/publish")
            ->assertOk();

        return ['id' => $id, 'definition' => $definition];
    }

    public function test_exporter_receives_csv_with_metadata_and_spreadsheet_injection_protection(): void
    {
        $user = $this->exporter();
        $report = $this->publishMemberReport($user);

        $requested = $this->actingAsMfaVerified($user)
            ->postJson('/api/report-exports', [
                'report_type' => 'custom',
                'custom_report_id' => $report['id'],
                'format' => 'csv',
                'classification' => 'restricted',
                'filters' => ['branch_id' => $this->branch->id],
            ])
            ->assertOk()
            ->json('data');

        $this->assertFalse($requested['async']);
        $this->assertSame('completed', $requested['status']);
        $this->assertSame('restricted', $requested['classification']);
        $this->assertNotEmpty($requested['download']['token']);

        $download = $this->actingAsMfaVerified($user)
            ->get('/api/report-exports/' . $requested['reference'] . '/download?token=' . $requested['download']['token'])
            ->assertOk()
            ->assertHeader('content-disposition');

        $filename = $download->headers->get('content-disposition');
        $this->assertStringNotContainsString('..', (string) $filename);
        $this->assertStringNotContainsString('/', (string) $filename);

        $csv = $download->streamedContent();
        $this->assertStringContainsString('Classification', $csv);
        $this->assertStringContainsString('restricted', $csv);
        $this->assertStringContainsString('branch_id', $csv);
        $this->assertStringContainsString("'=SUM(A1)", $csv);

        $this->assertTrue(
            AuditEvent::query()->where('action', 'report_export.requested')->exists()
        );
        $this->assertTrue(
            AuditEvent::query()->where('action', 'report_export.completed')->exists()
        );
        $this->assertTrue(
            AuditEvent::query()->where('action', 'report_export.downloaded')->exists()
        );
    }

    public function test_large_export_runs_asynchronously_and_provides_secure_time_limited_download(): void
    {
        config(['report_exports.interactive_row_threshold' => 0]);

        $user = $this->exporter();
        $report = $this->publishMemberReport($user);

        $requested = $this->actingAsMfaVerified($user)
            ->postJson('/api/report-exports', [
                'report_type' => 'custom',
                'custom_report_id' => $report['id'],
                'format' => 'excel',
                'filters' => ['__test_force_async' => true],
            ])
            ->assertStatus(202)
            ->json('data');

        $this->assertTrue($requested['async']);
        $this->assertContains($requested['status'], ['pending', 'completed']);

        $export = ReportExport::query()->where('reference', $requested['reference'])->first();
        $this->assertNotNull($export);
        $this->assertSame('completed', $export->status);
        $this->assertNotNull($export->download_expires_at);

        $status = $this->actingAsMfaVerified($user)
            ->getJson('/api/report-exports/' . $requested['reference'] . '/status')
            ->assertOk()
            ->json('data');

        $this->assertSame('completed', $status['status']);

        $this->actingAsMfaVerified($user)
            ->get('/api/report-exports/' . $requested['reference'] . '/download?token=' . $requested['download']['token'])
            ->assertOk();

        $other = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $this->grant($other, ['reports.export', 'reports.custom.read', 'members.read']);

        $this->actingAsMfaVerified($other)
            ->get('/api/report-exports/' . $requested['reference'] . '/download?token=' . $requested['download']['token'])
            ->assertStatus(403);
    }
}
