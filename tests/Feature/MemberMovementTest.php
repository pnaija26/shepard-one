<?php

namespace Tests\Feature;

use App\Models\BranchAssociationHistory;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Story 1.5: Control Cross-Branch Identity Movement (docs/epics.md).
 *
 * AC1 — initiate: a centrally identified person moves between branches via an
 *      approved process; the central identifier and historical branch
 *      relationship are preserved; a PENDING record is created, never a
 *      duplicate identity.
 * AC2 — approve: destination or HQ approver decides; the effective branch
 *      association changes ON the approved (effective) date; history retained.
 * AC3 — unauthorized / invalid / duplicate / rejected requests leave the active
 *      branch association unchanged and the decision + reason are audited.
 */
class MemberMovementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Canonical tree:
     *   HQ (headquarters)
     *   ├── Branch A ── Campus A1
     *   └── Branch B ── Campus B1
     */
    private function buildTree(): array
    {
        $hq = Organization::create(['name' => 'Headquarters', 'type' => 'headquarters', 'identifier' => 'IDX-HQ']);

        $branchA = Organization::create([
            'name' => 'Branch A', 'type' => 'branch', 'identifier' => 'IDX-BR-A', 'parent_id' => $hq->id,
        ]);
        $campusA1 = Organization::create([
            'name' => 'Campus A1', 'type' => 'campus', 'identifier' => 'IDX-CM-A1', 'parent_id' => $branchA->id,
        ]);

        $branchB = Organization::create([
            'name' => 'Branch B', 'type' => 'branch', 'identifier' => 'IDX-BR-B', 'parent_id' => $hq->id,
        ]);
        $campusB1 = Organization::create([
            'name' => 'Campus B1', 'type' => 'campus', 'identifier' => 'IDX-CM-B1', 'parent_id' => $branchB->id,
        ]);

        return compact('hq', 'branchA', 'campusA1', 'branchB', 'campusB1');
    }

    private function hqAdmin(): User
    {
        return User::factory()->create(['roles' => ['admin'], 'branch_id' => null]);
    }

    private function branchAdmin(Organization $branch): User
    {
        return User::factory()->create(['roles' => ['admin'], 'branch_id' => $branch->id]);
    }

    // ------------------------------------------------------------------
    // AC1 — initiate: pending record, identity + history preserved
    // ------------------------------------------------------------------

    public function test_initiation_creates_pending_record_and_preserves_identity()
    {
        $t = $this->buildTree();
        $hq = $this->hqAdmin();
        $person = User::factory()->create(['branch_id' => $t['branchA']->id]);

        $usersBefore = User::count();

        $response = $this->actingAs($hq)->postJson('/api/org/movements', [
            'person_id' => $person->id,
            'destination_branch_id' => $t['branchB']->id,
            'effective_date' => Carbon::today()->addWeek()->toDateString(),
            'reason' => 'Relocating to the north side.',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('person_id', $person->id)
            ->assertJsonPath('source_branch_id', $t['branchA']->id)
            ->assertJsonPath('destination_branch_id', $t['branchB']->id)
            ->assertJsonPath('reason', 'Relocating to the north side.');

        // One identity, not a duplicate: no new user rows were created.
        $this->assertSame($usersBefore, User::count());

        // The active association is untouched until approval.
        $this->assertEquals($t['branchA']->id, $person->fresh()->branch_id);
    }

    public function test_branch_admin_can_initiate_for_person_in_their_own_branch()
    {
        $t = $this->buildTree();
        $adminA = $this->branchAdmin($t['branchA']);
        $person = User::factory()->create(['branch_id' => $t['branchA']->id]);

        $response = $this->actingAs($adminA)->postJson('/api/org/movements', [
            'person_id' => $person->id,
            'destination_branch_id' => $t['branchB']->id,
            'effective_date' => Carbon::today()->addWeek()->toDateString(),
            'reason' => 'Transfer request.',
        ]);

        // Destination may lie outside the initiator's scope — that is the point
        // of cross-branch movement; source must be inside it.
        $response->assertStatus(201)->assertJsonPath('status', 'pending');
    }

    public function test_branch_admin_cannot_initiate_for_person_outside_scope()
    {
        $t = $this->buildTree();
        $adminA = $this->branchAdmin($t['branchA']);
        $personB = User::factory()->create(['branch_id' => $t['branchB']->id]);

        $response = $this->actingAs($adminA)->postJson('/api/org/movements', [
            'person_id' => $personB->id,
            'destination_branch_id' => $t['branchA']->id,
            'effective_date' => Carbon::today()->addWeek()->toDateString(),
            'reason' => 'Attempted cross-scope initiation.',
        ]);

        $response->assertStatus(403);
        $this->assertSame(0, \App\Models\MemberMovement::count());
    }

    public function test_unassigned_person_can_only_be_moved_by_hq()
    {
        $t = $this->buildTree();
        $unassigned = User::factory()->create(['branch_id' => null]);

        // Branch-scoped actor has no claim over an unassigned person.
        $adminA = $this->branchAdmin($t['branchA']);
        $this->actingAs($adminA)->postJson('/api/org/movements', [
            'person_id' => $unassigned->id,
            'destination_branch_id' => $t['branchB']->id,
            'effective_date' => Carbon::today()->addWeek()->toDateString(),
            'reason' => 'x',
        ])->assertStatus(403);

        // HQ may.
        $hq = $this->hqAdmin();
        $this->actingAs($hq)->postJson('/api/org/movements', [
            'person_id' => $unassigned->id,
            'destination_branch_id' => $t['branchB']->id,
            'effective_date' => Carbon::today()->addWeek()->toDateString(),
            'reason' => 'Assigning to Branch B.',
        ])->assertStatus(201);
    }

    public function test_non_privileged_user_cannot_initiate()
    {
        $t = $this->buildTree();
        $member = User::factory()->create(['branch_id' => $t['branchA']->id]); // no admin role
        $person = User::factory()->create(['branch_id' => $t['branchA']->id]);

        $response = $this->actingAs($member)->postJson('/api/org/movements', [
            'person_id' => $person->id,
            'destination_branch_id' => $t['branchB']->id,
            'effective_date' => Carbon::today()->addWeek()->toDateString(),
            'reason' => 'x',
        ]);

        $response->assertStatus(403);
        $this->assertSame(0, \App\Models\MemberMovement::count());
    }

    public function test_same_branch_destination_is_rejected()
    {
        $t = $this->buildTree();
        $hq = $this->hqAdmin();
        $person = User::factory()->create(['branch_id' => $t['branchA']->id]);

        $response = $this->actingAs($hq)->postJson('/api/org/movements', [
            'person_id' => $person->id,
            'destination_branch_id' => $t['branchA']->id, // already there
            'effective_date' => Carbon::today()->addWeek()->toDateString(),
            'reason' => 'no-op attempt',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, \App\Models\MemberMovement::count());
    }

    public function test_invalid_destination_is_rejected()
    {
        $t = $this->buildTree();
        $hq = $this->hqAdmin();
        $person = User::factory()->create(['branch_id' => $t['branchA']->id]);

        // A campus is not a branch — association lives at branch level.
        $response = $this->actingAs($hq)->postJson('/api/org/movements', [
            'person_id' => $person->id,
            'destination_branch_id' => $t['campusB1']->id,
            'effective_date' => Carbon::today()->addWeek()->toDateString(),
            'reason' => 'x',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, \App\Models\MemberMovement::count());
    }

    // ------------------------------------------------------------------
    // AC2 — approve: association changes ON the effective date; history kept
    // ------------------------------------------------------------------

    public function test_approval_with_effective_date_today_applies_immediately()
    {
        $t = $this->buildTree();
        $hq = $this->hqAdmin();
        $person = User::factory()->create(['branch_id' => $t['branchA']->id]);

        // Record the pre-existing association so we can assert retention.
        BranchAssociationHistory::create([
            'person_id' => $person->id,
            'branch_id' => $t['branchA']->id,
            'started_at' => now()->subYear(),
            'ended_at' => null,
            'source' => 'seed',
        ]);

        $movement = $this->actingAs($hq)->postJson('/api/org/movements', [
            'person_id' => $person->id,
            'destination_branch_id' => $t['branchB']->id,
            'effective_date' => Carbon::today()->toDateString(), // already arrived
            'reason' => 'Relocation.',
        ])->assertStatus(201)->json('id');

        $this->actingAs($hq)
            ->postJson("/api/org/movements/{$movement}/approve", ['reason' => 'Approved by HQ.'])
            ->assertStatus(200);

        // Effective date has arrived -> applied immediately, same identity.
        $person->refresh();
        $this->assertEquals($t['branchB']->id, $person->branch_id);

        $movementRow = \App\Models\MemberMovement::find($movement);
        $this->assertSame('applied', $movementRow->status);
        $this->assertNotNull($movementRow->decided_by);
        $this->assertNotNull($movementRow->decision_reason);
        $this->assertNotNull($movementRow->applied_at);

        // Retention: the old association is closed out, the new one open —
        // both rows survive; nothing was deleted or rewritten.
        $timeline = BranchAssociationHistory::where('person_id', $person->id)->orderBy('started_at')->get();
        $this->assertCount(2, $timeline);
        $this->assertEquals($t['branchA']->id, $timeline[0]->branch_id);
        $this->assertNotNull($timeline[0]->ended_at); // closed out
        $this->assertEquals($t['branchB']->id, $timeline[1]->branch_id);
        $this->assertNull($timeline[1]->ended_at);    // current
    }

    public function test_approval_with_future_effective_date_waits_for_scheduler()
    {
        $t = $this->buildTree();
        $hq = $this->hqAdmin();
        $person = User::factory()->create(['branch_id' => $t['branchA']->id]);

        $movement = $this->actingAs($hq)->postJson('/api/org/movements', [
            'person_id' => $person->id,
            'destination_branch_id' => $t['branchB']->id,
            'effective_date' => Carbon::today()->addWeek()->toDateString(), // future
            'reason' => 'Scheduled relocation.',
        ])->assertStatus(201)->json('id');

        $this->actingAs($hq)
            ->postJson("/api/org/movements/{$movement}/approve", ['reason' => 'ok'])
            ->assertStatus(200);

        // Approved but NOT yet effective: association must not change early.
        $row = \App\Models\MemberMovement::find($movement);
        $this->assertSame('approved', $row->status);
        $this->assertEquals($t['branchA']->id, $person->fresh()->branch_id);

        // The scheduler applies it once the effective date arrives.
        Carbon::setTestNow(Carbon::today()->addWeek());
        Artisan::call('movements:apply-due');
        Carbon::setTestNow();

        $this->assertSame('applied', \App\Models\MemberMovement::find($movement)->status);
        $this->assertEquals($t['branchB']->id, $person->fresh()->branch_id);
    }

    public function test_destination_branch_approver_can_approve()
    {
        $t = $this->buildTree();
        $hq = $this->hqAdmin();
        $approverB = $this->branchAdmin($t['branchB']); // destination approver
        $person = User::factory()->create(['branch_id' => $t['branchA']->id]);

        $movement = $this->actingAs($hq)->postJson('/api/org/movements', [
            'person_id' => $person->id,
            'destination_branch_id' => $t['branchB']->id,
            'effective_date' => Carbon::today()->toDateString(),
            'reason' => 'Intake.',
        ])->assertStatus(201)->json('id');

        // The destination branch (not HQ) decides.
        $this->actingAs($approverB)
            ->postJson("/api/org/movements/{$movement}/approve", ['reason' => 'Accepted by Branch B.'])
            ->assertStatus(200);

        $this->assertEquals($t['branchB']->id, $person->fresh()->branch_id);
    }

    public function test_unrelated_branch_cannot_approve()
    {
        $t = $this->buildTree();
        $hq = $this->hqAdmin();
        // A third branch admin: neither source nor destination.
        $branchC = Organization::create([
            'name' => 'Branch C', 'type' => 'branch', 'identifier' => 'IDX-BR-C', 'parent_id' => $t['hq']->id,
        ]);
        $adminC = $this->branchAdmin($branchC);
        $person = User::factory()->create(['branch_id' => $t['branchA']->id]);

        $movement = $this->actingAs($hq)->postJson('/api/org/movements', [
            'person_id' => $person->id,
            'destination_branch_id' => $t['branchB']->id,
            'effective_date' => Carbon::today()->toDateString(),
            'reason' => 'x',
        ])->assertStatus(201)->json('id');

        $this->actingAs($adminC)
            ->postJson("/api/org/movements/{$movement}/approve", ['reason' => 'not my branch'])
            ->assertStatus(403);

        // Still pending; association untouched.
        $this->assertSame('pending', \App\Models\MemberMovement::find($movement)->status);
        $this->assertEquals($t['branchA']->id, $person->fresh()->branch_id);
    }

    public function test_movement_detail_includes_decision_audit_and_history()
    {
        $t = $this->buildTree();
        $hq = $this->hqAdmin();
        $person = User::factory()->create(['branch_id' => $t['branchA']->id]);

        BranchAssociationHistory::create([
            'person_id' => $person->id,
            'branch_id' => $t['branchA']->id,
            'started_at' => now()->subYear(),
            'ended_at' => null,
            'source' => 'seed',
        ]);

        $movement = $this->actingAs($hq)->postJson('/api/org/movements', [
            'person_id' => $person->id,
            'destination_branch_id' => $t['branchB']->id,
            'effective_date' => Carbon::today()->toDateString(),
            'reason' => 'Relocation.',
        ])->assertStatus(201)->json('id');

        $this->actingAs($hq)
            ->postJson("/api/org/movements/{$movement}/approve", ['reason' => 'Approved.'])
            ->assertStatus(200);

        $response = $this->actingAs($hq)->getJson("/api/org/movements/{$movement}");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'applied')
            ->assertJsonPath('data.decided_by', $hq->id)
            ->assertJsonPath('data.decision_reason', 'Approved.');

        // History: both associations present (retention view).
        $history = collect($response->json('history'))->pluck('branch_id')->all();
        sort($history);
        $expected = [$t['branchA']->id, $t['branchB']->id];
        sort($expected);
        $this->assertEquals($expected, $history);
    }

    // ------------------------------------------------------------------
    // AC3 — unauthorized / invalid / duplicate / rejected: association
    //       unchanged; decision + reason audited
    // ------------------------------------------------------------------

    public function test_rejection_leaves_association_unchanged_and_audits_decision()
    {
        $t = $this->buildTree();
        $hq = $this->hqAdmin();
        $approverB = $this->branchAdmin($t['branchB']);
        $person = User::factory()->create(['branch_id' => $t['branchA']->id]);

        $movement = $this->actingAs($hq)->postJson('/api/org/movements', [
            'person_id' => $person->id,
            'destination_branch_id' => $t['branchB']->id,
            'effective_date' => Carbon::today()->addWeek()->toDateString(),
            'reason' => 'Requested transfer.',
        ])->assertStatus(201)->json('id');

        $this->actingAs($approverB)
            ->postJson("/api/org/movements/{$movement}/reject", ['reason' => 'Capacity full this term.'])
            ->assertStatus(200);

        // Active association unchanged...
        $this->assertEquals($t['branchA']->id, $person->fresh()->branch_id);

        // ...and the decision + reason are audited on the row.
        $row = \App\Models\MemberMovement::find($movement);
        $this->assertSame('rejected', $row->status);
        $this->assertEquals($approverB->id, $row->decided_by);
        $this->assertNotNull($row->decided_at);
        $this->assertSame('Capacity full this term.', $row->decision_reason);
    }

    public function test_duplicate_open_movement_is_rejected_with_conflict()
    {
        $t = $this->buildTree();
        $hq = $this->hqAdmin();
        $person = User::factory()->create(['branch_id' => $t['branchA']->id]);

        $payload = [
            'person_id' => $person->id,
            'destination_branch_id' => $t['branchB']->id,
            'effective_date' => Carbon::today()->addWeek()->toDateString(),
            'reason' => 'first request',
        ];

        $this->actingAs($hq)->postJson('/api/org/movements', $payload)->assertStatus(201);

        // Second request while one is open -> 409, association unchanged.
        $response = $this->actingAs($hq)->postJson('/api/org/movements', [
            ...$payload,
            'reason' => 'duplicate attempt',
        ]);

        $response->assertStatus(409);
        $this->assertSame(1, \App\Models\MemberMovement::count());
        $this->assertEquals($t['branchA']->id, $person->fresh()->branch_id);
    }

    public function test_decided_movement_cannot_be_decided_again()
    {
        $t = $this->buildTree();
        $hq = $this->hqAdmin();
        $approverB = $this->branchAdmin($t['branchB']);
        $person = User::factory()->create(['branch_id' => $t['branchA']->id]);

        $movement = $this->actingAs($hq)->postJson('/api/org/movements', [
            'person_id' => $person->id,
            'destination_branch_id' => $t['branchB']->id,
            'effective_date' => Carbon::today()->addWeek()->toDateString(),
            'reason' => 'x',
        ])->assertStatus(201)->json('id');

        $this->actingAs($approverB)
            ->postJson("/api/org/movements/{$movement}/reject", ['reason' => 'no'])
            ->assertStatus(200);

        // Re-deciding an already decided movement fails secure.
        $this->actingAs($hq)
            ->postJson("/api/org/movements/{$movement}/approve", ['reason' => 'late approval'])
            ->assertStatus(409);

        $row = \App\Models\MemberMovement::find($movement);
        $this->assertSame('rejected', $row->status); // original decision stands
        $this->assertEquals($t['branchA']->id, $person->fresh()->branch_id);
    }

    public function test_branch_user_list_is_scoped_to_their_subtree()
    {
        $t = $this->buildTree();
        $hq = $this->hqAdmin();
        $adminA = $this->branchAdmin($t['branchA']);

        // Movement A -> B (touches Branch A) and one entirely outside it.
        $personA = User::factory()->create(['branch_id' => $t['branchA']->id]);

        $m1 = $this->actingAs($hq)->postJson('/api/org/movements', [
            'person_id' => $personA->id,
            'destination_branch_id' => $t['branchB']->id,
            'effective_date' => Carbon::today()->addWeek()->toDateString(),
            'reason' => 'A -> B',
        ])->assertStatus(201)->json('id');

        // A movement wholly between two branches outside A: create a Branch C.
        $branchC = Organization::create([
            'name' => 'Branch C', 'type' => 'branch', 'identifier' => 'IDX-BR-C', 'parent_id' => $t['hq']->id,
        ]);
        $personC = User::factory()->create(['branch_id' => $branchC->id]);
        $this->actingAs($hq)->postJson('/api/org/movements', [
            'person_id' => $personC->id,
            'destination_branch_id' => $t['branchB']->id,
            'effective_date' => Carbon::today()->addWeek()->toDateString(),
            'reason' => 'C -> B (outside A)',
        ])->assertStatus(201);

        // Branch A admin sees only movements touching their subtree.
        $response = $this->actingAs($adminA)->getJson('/api/org/movements');
        $ids = collect($response->json('data'))->pluck('id')->all();

        $response->assertStatus(200)
            ->assertJsonPath('meta.scope', 'branch')
            ->assertJsonPath('meta.branch_id', $t['branchA']->id);

        $this->assertSame([$m1], $ids);

        // HQ sees everything (consolidated).
        $hqIds = collect($this->actingAs($hq)->getJson('/api/org/movements')->json('data'))->pluck('id')->all();
        $this->assertCount(2, $hqIds);
    }
}
