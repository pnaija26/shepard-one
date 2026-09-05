<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Member;
use App\Models\MembershipCardScanEvent;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\MembershipCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Story 2.6: Use a Digital Membership Card.
 */
class MemberMembershipCardTest extends TestCase
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
        $user = User::factory()->create([
            'roles' => ['member'],
            'has_mfa_enrolled' => false,
        ]);

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
        ], $memberAttrs));

        return $user;
    }

    private function scanner(): User
    {
        $user = $this->privilegedUser(['branch_id' => null]);
        $role = Role::create(['name' => 'card_scanner_' . $user->id]);
        RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => 'membership_card.scan']);
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);

        return $user;
    }

    // ------------------------------------------------------------------
    // AC1 — card display with QR, no sensitive payload
    // ------------------------------------------------------------------

    public function test_member_can_view_digital_card_with_qr_reference(): void
    {
        $user = $this->memberUser();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/me/membership-card')
            ->assertOk()
            ->assertJsonPath('data.full_name', 'Grace Adeyemi')
            ->assertJsonPath('data.membership_id', 'S1-M-' . str_pad((string) $user->id, 6, '0', STR_PAD_LEFT))
            ->assertJsonPath('data.branch.name', 'Branch A')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.photo_path', '/photos/grace.jpg')
            ->assertJsonStructure(['data' => ['qr' => ['payload', 'expires_at']]]);

        $payload = $response->json('data.qr.payload');
        $this->assertIsString($payload);
        $this->assertStringNotContainsString('grace@example.com', $payload);
        $this->assertStringNotContainsString('08011112222', $payload);
        $this->assertStringNotContainsString('restricted', strtolower($payload));
    }

    // ------------------------------------------------------------------
    // AC2 — scanner validation with purpose-limited data
    // ------------------------------------------------------------------

    public function test_authorized_scanner_verifies_card_for_purpose(): void
    {
        $memberUser = $this->memberUser();
        $scanner = $this->scanner();
        $member = Member::where('user_id', $memberUser->id)->firstOrFail();

        $token = app(MembershipCardService::class)->issueToken($member)['token'];

        $this->actingAsMfaVerified($scanner)
            ->postJson('/api/membership-card/verify', [
                'token' => $token,
                'purpose' => 'attendance',
            ])
            ->assertOk()
            ->assertJsonPath('data.verified', true)
            ->assertJsonPath('data.member.full_name', 'Grace Adeyemi')
            ->assertJsonPath('data.member.membership_id', $member->membership_id)
            ->assertJsonMissing(['data.member.photo_path']);

        $this->assertDatabaseHas('membership_card_scan_events', [
            'member_id' => $member->id,
            'scanned_by' => $scanner->id,
            'purpose' => 'attendance',
            'outcome' => MembershipCardScanEvent::OUTCOME_VERIFIED,
        ]);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'membership_card.verified',
            'module' => 'members',
        ]);
    }

    public function test_replay_tampered_and_expired_tokens_fail_securely(): void
    {
        $memberUser = $this->memberUser();
        $scanner = $this->scanner();
        $member = Member::where('user_id', $memberUser->id)->firstOrFail();
        $service = app(MembershipCardService::class);

        $token = $service->issueToken($member)['token'];

        $this->actingAsMfaVerified($scanner)
            ->postJson('/api/membership-card/verify', [
                'token' => $token,
                'purpose' => 'identity_check',
            ])
            ->assertOk();

        $this->actingAsMfaVerified($scanner)
            ->postJson('/api/membership-card/verify', [
                'token' => $token,
                'purpose' => 'identity_check',
            ])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'replay')
            ->assertJsonPath('verified', false);

        $tampered = substr($token, 0, -4) . 'XXXX';

        $this->actingAsMfaVerified($scanner)
            ->postJson('/api/membership-card/verify', [
                'token' => $tampered,
                'purpose' => 'identity_check',
            ])
            ->assertStatus(422)
            ->assertJsonPath('verified', false);

        config(['membership_card.token_ttl' => -10]);
        $expired = $service->issueToken($member)['token'];

        $this->actingAsMfaVerified($scanner)
            ->postJson('/api/membership-card/verify', [
                'token' => $expired,
                'purpose' => 'identity_check',
            ])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'expired');
    }

    // ------------------------------------------------------------------
    // AC3 — current eligibility enforced on validation
    // ------------------------------------------------------------------

    public function test_suspended_member_card_validation_uses_current_eligibility(): void
    {
        $memberUser = $this->memberUser();
        $scanner = $this->scanner();
        $member = Member::where('user_id', $memberUser->id)->firstOrFail();
        $service = app(MembershipCardService::class);

        $token = $service->issueToken($member)['token'];

        $member->update(['lifecycle_status' => 'suspended']);

        $this->actingAsMfaVerified($scanner)
            ->postJson('/api/membership-card/verify', [
                'token' => $token,
                'purpose' => 'attendance',
            ])
            ->assertStatus(422)
            ->assertJsonPath('verified', false)
            ->assertJsonFragment(['lifecycle_status']);

        Sanctum::actingAs($memberUser);

        $this->getJson('/api/me/membership-card')
            ->assertStatus(422)
            ->assertJsonPath('eligible', false);
    }

    public function test_refreshed_card_after_profile_change_invalidates_stale_token(): void
    {
        $memberUser = $this->memberUser();
        $scanner = $this->scanner();
        $member = Member::where('user_id', $memberUser->id)->firstOrFail();
        $service = app(MembershipCardService::class);

        $token = $service->issueToken($member)['token'];

        $this->travel(2)->seconds();
        $member->update(['photo_path' => '/photos/grace-new.jpg']);

        $this->actingAsMfaVerified($scanner)
            ->postJson('/api/membership-card/verify', [
                'token' => $token,
                'purpose' => 'identity_check',
            ])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'stale');

        Sanctum::actingAs($memberUser);

        $this->getJson('/api/me/membership-card')
            ->assertOk()
            ->assertJsonPath('data.photo_path', '/photos/grace-new.jpg');
    }
}
