<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\WelfareAssistanceConfirmation;
use App\Models\WelfareAssistanceDelivery;
use App\Models\WelfareRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 7.4: Record Assistance Delivery and Confirmation.
 */
class WelfareDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'IDX-HQ']);
        $this->branch = Organization::create(['name' => 'Branch A', 'type' => 'branch', 'identifier' => 'IDX-A', 'parent_id' => $hq->id]);
    }

    private function grant(User $user, array $actions): void
    {
        $role = Role::create(['name' => 'welfare_dlv_' . $user->id . '_' . substr(md5(implode(',', $actions)), 0, 6)]);
        foreach ($actions as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);
    }

    private function assessor(): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $this->grant($user, [
            'welfare.requests.read', 'welfare.requests.submit', 'welfare.restricted.read', 'welfare.assess',
        ]);

        return $user;
    }

    private function branchApprover(): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $this->grant($user, [
            'welfare.requests.read', 'welfare.restricted.read',
            'welfare.approvals.decide', 'welfare.approvals.branch',
        ]);

        return $user;
    }

    private function financeOfficer(): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $this->grant($user, [
            'welfare.requests.read', 'welfare.restricted.read',
            'welfare.deliveries.manage', 'welfare.deliveries.confirm', 'welfare.finance.read',
        ]);

        return $user;
    }

    private function reader(): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $this->grant($user, [
            'welfare.requests.read', 'welfare.restricted.read', 'welfare.assess',
        ]);

        return $user;
    }

    private function member(string $suffix): Member
    {
        return Member::create([
            'membership_id' => 'S74-' . $suffix,
            'branch_id' => $this->branch->id,
            'registration_channel' => 'web',
            'first_name' => 'Deliver',
            'last_name' => 'Member' . $suffix,
            'consent_data_processing' => true,
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
        ]);
    }

    private function approvedCase(float $value = 40000, string $hash = 'hash-dlv'): array
    {
        $assessor = $this->assessor();
        $approver = $this->branchApprover();
        $beneficiary = $this->member(substr($hash, -4));

        $requestId = $this->actingAsMfaVerified($assessor)
            ->postJson('/api/welfare-requests', [
                'branch_id' => $this->branch->id,
                'beneficiary_member_id' => $beneficiary->id,
                'request_type' => 'financial',
                'description' => 'Needs food support.',
                'priority' => 'normal',
                'requested_value' => $value,
                'consent_data_processing' => true,
                'consent_welfare_review' => true,
                'documents' => [[
                    'filename' => 'evidence.pdf',
                    'mime_type' => 'application/pdf',
                    'size_bytes' => 1024,
                    'content_hash' => $hash,
                ]],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($assessor)->postJson("/api/welfare-requests/{$requestId}/submit")->assertOk();
        $this->actingAsMfaVerified($assessor)->postJson("/api/welfare-requests/{$requestId}/assess", [
            'assessment_notes' => 'Recommend cash support.',
            'verified_documents' => ['evidence.pdf'],
            'priority' => 'normal',
            'recommendation' => 'approve',
            'proposed_assistance_type' => 'cash',
            'proposed_value' => $value,
            'complete' => true,
        ])->assertOk();

        $this->actingAsMfaVerified($approver)->postJson("/api/welfare-requests/{$requestId}/decisions", [
            'decision' => 'approved',
            'reason' => 'Branch approves within threshold.',
        ])->assertOk()->assertJsonPath('data.status', WelfareRequest::STATUS_APPROVED);

        return [$requestId, $assessor, $approver, $beneficiary];
    }

    // ------------------------------------------------------------------
    // AC1 — delivery linked, cannot exceed approval, finance restricted
    // ------------------------------------------------------------------

    public function test_finance_officer_records_delivery_within_approved_value(): void
    {
        [$requestId] = $this->approvedCase(40000, 'hash-ok1');
        $finance = $this->financeOfficer();

        $delivery = $this->actingAsMfaVerified($finance)
            ->postJson("/api/welfare-requests/{$requestId}/deliveries", [
                'delivery_type' => 'disbursement',
                'method' => 'bank_transfer',
                'amount' => 40000,
                'delivered_on' => now()->toDateString(),
                'reference' => 'TRX-40000-A',
                'evidence' => [[
                    'filename' => 'receipt.pdf',
                    'mime_type' => 'application/pdf',
                    'size_bytes' => 2048,
                    'content_hash' => 'receipt-hash-1',
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('case.status', WelfareRequest::STATUS_DISBURSED)
            ->json('data');

        $this->assertSame(40000.0, (float) $delivery['amount']);
        $this->assertSame('pending', $delivery['confirmation']['status']);
        $this->assertDatabaseHas('welfare_assistance_deliveries', [
            'welfare_request_id' => $requestId,
            'reference' => 'TRX-40000-A',
        ]);
    }

    public function test_delivery_cannot_exceed_approved_value(): void
    {
        [$requestId] = $this->approvedCase(40000, 'hash-over');
        $finance = $this->financeOfficer();

        $this->actingAsMfaVerified($finance)
            ->postJson("/api/welfare-requests/{$requestId}/deliveries", [
                'delivery_type' => 'disbursement',
                'method' => 'cash',
                'amount' => 45000,
                'delivered_on' => now()->toDateString(),
                'reference' => 'TRX-OVER',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'exceeds_approval');
    }

    public function test_financial_details_are_restricted_without_finance_permission(): void
    {
        [$requestId] = $this->approvedCase(40000, 'hash-fin');
        $finance = $this->financeOfficer();
        $reader = $this->reader();

        $this->actingAsMfaVerified($finance)
            ->postJson("/api/welfare-requests/{$requestId}/deliveries", [
                'delivery_type' => 'disbursement',
                'method' => 'cash',
                'amount' => 20000,
                'delivered_on' => now()->toDateString(),
                'reference' => 'TRX-RESTRICT',
            ])
            ->assertCreated();

        $view = $this->actingAsMfaVerified($reader)
            ->getJson("/api/welfare-requests/{$requestId}")
            ->assertOk()
            ->json('data');

        $this->assertTrue($view['deliveries'][0]['financial_restricted']);
        $this->assertNull($view['deliveries'][0]['amount']);
        $this->assertSame('[Restricted]', $view['deliveries'][0]['reference']);
        $this->assertNull($view['approved_value']);
    }

    // ------------------------------------------------------------------
    // AC2 — confirmation / waiver advances to follow-up
    // ------------------------------------------------------------------

    public function test_beneficiary_confirmation_advances_to_follow_up(): void
    {
        [$requestId] = $this->approvedCase(40000, 'hash-conf');
        $finance = $this->financeOfficer();

        $deliveryId = $this->actingAsMfaVerified($finance)
            ->postJson("/api/welfare-requests/{$requestId}/deliveries", [
                'delivery_type' => 'in_kind',
                'method' => 'in_kind',
                'amount' => 15000,
                'delivered_on' => now()->toDateString(),
                'reference' => 'INKIND-1',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($finance)
            ->postJson("/api/welfare-deliveries/{$deliveryId}/confirm", [
                'status' => 'confirmed',
                'evidence' => [[
                    'filename' => 'signed_ack.pdf',
                    'mime_type' => 'application/pdf',
                    'size_bytes' => 1024,
                    'content_hash' => 'ack-hash',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.confirmation.status', WelfareAssistanceConfirmation::STATUS_CONFIRMED)
            ->assertJsonPath('data.case.status', WelfareRequest::STATUS_FOLLOW_UP);

        $this->assertDatabaseHas('welfare_requests', [
            'id' => $requestId,
            'status' => WelfareRequest::STATUS_FOLLOW_UP,
        ]);
    }

    public function test_confirmation_waiver_requires_reason_and_advances_to_follow_up(): void
    {
        [$requestId] = $this->approvedCase(40000, 'hash-waive');
        $finance = $this->financeOfficer();

        $deliveryId = $this->actingAsMfaVerified($finance)
            ->postJson("/api/welfare-requests/{$requestId}/deliveries", [
                'delivery_type' => 'disbursement',
                'method' => 'mobile_money',
                'amount' => 10000,
                'delivered_on' => now()->toDateString(),
                'reference' => 'MM-1',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsMfaVerified($finance)
            ->postJson("/api/welfare-deliveries/{$deliveryId}/confirm", [
                'status' => 'waived',
            ])
            ->assertStatus(422);

        $this->actingAsMfaVerified($finance)
            ->postJson("/api/welfare-deliveries/{$deliveryId}/confirm", [
                'status' => 'waived',
                'waiver_reason' => 'Beneficiary unreachable after three documented attempts.',
            ])
            ->assertOk()
            ->assertJsonPath('data.confirmation.status', WelfareAssistanceConfirmation::STATUS_WAIVED)
            ->assertJsonPath('data.case.status', WelfareRequest::STATUS_FOLLOW_UP);
    }

    public function test_unauthorized_user_cannot_record_delivery(): void
    {
        [$requestId] = $this->approvedCase(40000, 'hash-sec');
        $outsider = User::factory()->create([
            'branch_id' => $this->branch->id,
            'has_mfa_enrolled' => true,
        ]);

        $this->actingAsMfaVerified($outsider)
            ->postJson("/api/welfare-requests/{$requestId}/deliveries", [
                'delivery_type' => 'disbursement',
                'method' => 'cash',
                'amount' => 1000,
                'delivered_on' => now()->toDateString(),
                'reference' => 'NOPE',
            ])
            ->assertForbidden();
    }
}
