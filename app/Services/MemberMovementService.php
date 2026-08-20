<?php

namespace App\Services;

use App\Models\BranchAssociationHistory;
use App\Models\MemberMovement;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Story 1.5: control cross-branch identity movement.
 *
 * One centrally identified person (a user account today) moves between branches
 * through an approved process — never by creating a duplicate identity:
 *
 *   initiate -> pending record (person, destination, effective date, reason)
 *   approve  -> destination or HQ approver decides; association changes ON the
 *               effective date (immediately if that date has already arrived)
 *   reject   -> active association untouched; decision + reason audited
 *
 * Scope rules (server-side only, via BranchScope — client params never widen):
 *   - initiate: actor must be privileged and their scope must cover the person's
 *     CURRENT branch (you move people out of a branch you manage). The
 *     destination may lie outside the initiator's scope — that is the point of
 *     cross-branch movement. Unassigned persons can only be initiated by HQ.
 *   - approve/reject: actor must be privileged and either church-wide (HQ) or
 *     scoped to the DESTINATION branch ("destination or HQ approver").
 *
 * Duplicate/invalid requests are rejected with 409/422 and leave the active
 * association unchanged; every decision is recorded on the movement row.
 */
final class MemberMovementService
{
    /**
     * Initiate a cross-branch movement for an existing centrally identified person.
     *
     * @throws AuthorizationException  actor lacks scope (403)
     * @throws ValidationException     invalid destination / same branch (422)
     * @throws MovementConflictException person already has an open movement (409)
     */
    public function initiate(
        User $actor,
        int $personId,
        ?int $destinationBranchId,
        string $effectiveDate,
        string $reason,
    ): MemberMovement {
        // "An authorized administrator initiates a transfer" — non-privileged
        // users cannot start movements at all (mirrors OrganizationController).
        if (! $actor->isPrivileged()) {
            throw new AuthorizationException('Unauthorized to initiate member movements.');
        }

        $scope = BranchScope::for($actor);

        if ($scope->isDenied()) {
            throw new AuthorizationException(
                'Your branch assignment is invalid; contact an HQ administrator.'
            );
        }

        $person = User::find($personId);
        if (! $person) {
            throw ValidationException::withMessages([
                'person_id' => ['The person does not exist.'],
            ]);
        }

        // The initiator must have scope over the person's CURRENT branch — you can
        // only move people out of a branch you manage. Unassigned persons (no
        // current branch) are an HQ-only action: a branch-scoped actor has no claim.
        $sourceBranchId = $person->branch_id !== null ? (int) $person->branch_id : null;

        if ($sourceBranchId === null) {
            if (! $scope->isChurchWide()) {
                throw new AuthorizationException(
                    'Only HQ can initiate movements for unassigned persons.'
                );
            }
        } else {
            $scope->assertIncludes($sourceBranchId);
        }

        // Destination must be a real, active branch — association is at branch level.
        $destination = Organization::find($destinationBranchId);
        if (! $destination || $destination->type !== 'branch' || ! $destination->is_active) {
            throw ValidationException::withMessages([
                'destination_branch_id' => ['Destination must be an active branch.'],
            ]);
        }

        // Moving to the branch they are already in is not a movement — reject it so
        // we never create a no-op pending record (and never a duplicate identity).
        if ($sourceBranchId !== null && $sourceBranchId === (int) $destination->id) {
            throw ValidationException::withMessages([
                'destination_branch_id' => ['The person is already associated with that branch.'],
            ]);
        }

        // Duplicate guard: one open movement per person at a time. A second request
        // while one is pending/approved leaves the active association unchanged.
        $open = MemberMovement::where('person_id', $person->id)
            ->whereIn('status', [MemberMovement::STATUS_PENDING, MemberMovement::STATUS_APPROVED])
            ->lockForUpdate()
            ->first();

        if ($open) {
            throw new MovementConflictException(
                'This person already has an open movement request (' . $open->status . '). Resolve it first.'
            );
        }

        return MemberMovement::create([
            'person_id' => $person->id,
            'source_branch_id' => $sourceBranchId,
            'destination_branch_id' => $destination->id,
            'effective_date' => $effectiveDate,
            'reason' => $reason,
            'status' => MemberMovement::STATUS_PENDING,
            'initiated_by' => $actor->id,
        ]);
    }

    /**
     * Approve a pending movement (destination or HQ approver).
     *
     * The association changes on the effective date: if that date has already
     * arrived it is applied immediately; otherwise it waits for the scheduler.
     */
    public function approve(User $actor, MemberMovement $movement, ?string $decisionReason = null): MemberMovement
    {
        $this->assertDecider($actor, $movement);

        if ($movement->status !== MemberMovement::STATUS_PENDING) {
            throw new MovementConflictException(
                'Only pending movements can be approved (current status: ' . $movement->status . ').'
            );
        }

        $movement->forceFill([
            'status' => MemberMovement::STATUS_APPROVED,
            'decided_by' => $actor->id,
            'decided_at' => now(),
            'decision_reason' => $decisionReason,
        ])->save();

        // Effective date already reached -> apply right away.
        if ($movement->effective_date->lte(Carbon::today())) {
            $this->apply($movement);
        }

        return $movement->fresh()->load(['person', 'sourceBranch', 'destinationBranch', 'initiator', 'decider']);
    }

    /**
     * Reject a pending movement (destination or HQ approver). The active branch
     * association is untouched; the decision and reason are audited on the row.
     */
    public function reject(User $actor, MemberMovement $movement, ?string $decisionReason = null): MemberMovement
    {
        $this->assertDecider($actor, $movement);

        if ($movement->status !== MemberMovement::STATUS_PENDING) {
            throw new MovementConflictException(
                'Only pending movements can be rejected (current status: ' . $movement->status . ').'
            );
        }

        $movement->forceFill([
            'status' => MemberMovement::STATUS_REJECTED,
            'decided_by' => $actor->id,
            'decided_at' => now(),
            'decision_reason' => $decisionReason,
        ])->save();

        return $movement->fresh()->load(['person', 'sourceBranch', 'destinationBranch', 'initiator', 'decider']);
    }

    /**
     * Apply all approved movements whose effective date has arrived.
     * Intended for the scheduler (and tests). Returns how many were applied.
     */
    public function applyDueMovements(): int
    {
        $applied = 0;

        MemberMovement::where('status', MemberMovement::STATUS_APPROVED)
            ->whereNull('applied_at')
            ->whereDate('effective_date', '<=', Carbon::today())
            ->orderBy('id')
            // chunkById hands the callback a Collection of models (not a Builder).
            ->chunkById(100, function ($movements) use (&$applied): void {
                foreach ($movements as $movement) {
                    if ($this->apply($movement)) {
                        $applied++;
                    }
                }
            });

        return $applied;
    }

    /**
     * The person's full branch-association timeline (retention view).
     */
    public function historyFor(int $personId): \Illuminate\Database\Eloquent\Collection
    {
        return BranchAssociationHistory::timelineFor($personId)
            ->load('branch')
            ->values();
    }

    /**
     * Atomically change the person's active branch association to the movement's
     * destination, preserving history. Idempotent: a second call is a no-op.
     */
    private function apply(MemberMovement $movement): bool
    {
        return DB::transaction(function () use ($movement) {
            /** @var MemberMovement $locked */
            $locked = MemberMovement::whereKey($movement->id)->lockForUpdate()->first();

            // Idempotency: already applied (or no longer approved) -> nothing to do.
            if (! $locked || $locked->status !== MemberMovement::STATUS_APPROVED || $locked->applied_at !== null) {
                return false;
            }

            $person = User::lockForUpdate()->find($locked->person_id);
            if (! $person) {
                // Person removed -> the movement row is cascade-deleted with them,
                // so this path should not occur in practice. Fail loud rather than
                // silently dropping an approved decision.
                throw new \RuntimeException(
                    'Cannot apply movement ' . $locked->id . ': person no longer exists.'
                );
            }

            $now = now();
            $oldBranchId = $person->branch_id !== null ? (int) $person->branch_id : null;
            $newBranchId = (int) $locked->destination_branch_id;

            // Close out the previous association (or backfill it if this person has
            // never been recorded before), then open the new one. Append-only:
            // history rows are never deleted or rewritten.
            $current = BranchAssociationHistory::where('person_id', $person->id)
                ->whereNull('ended_at')
                ->lockForUpdate()
                ->first();

            if ($current) {
                $current->forceFill(['ended_at' => $now])->save();
            } elseif ($oldBranchId !== null) {
                BranchAssociationHistory::create([
                    'person_id' => $person->id,
                    'branch_id' => $oldBranchId,
                    'started_at' => $now, // unknown true start; recorded at first observation
                    'ended_at' => $now,
                    'source' => 'backfill',
                ]);
            }

            BranchAssociationHistory::create([
                'person_id' => $person->id,
                'branch_id' => $newBranchId,
                'started_at' => $now,
                'ended_at' => null, // current association
                'source' => 'movement_applied:' . $locked->id,
            ]);

            // The identity itself is unchanged — only its branch association moves.
            $person->forceFill(['branch_id' => $newBranchId])->save();

            $locked->forceFill([
                'status' => MemberMovement::STATUS_APPLIED,
                'applied_at' => $now,
            ])->save();

            return true;
        });
    }

    /**
     * Only a destination-branch approver or an HQ (church-wide) approver may
     * decide on a movement. Branch-scoped actors outside the destination branch
     * are denied — their scope never widens to approve someone else's intake.
     */
    private function assertDecider(User $actor, MemberMovement $movement): void
    {
        if (! $actor->isPrivileged()) {
            throw new AuthorizationException('Unauthorized to decide on member movements.');
        }

        $scope = BranchScope::for($actor);

        if ($scope->isDenied()) {
            throw new AuthorizationException(
                'Your branch assignment is invalid; contact an HQ administrator.'
            );
        }

        if ($scope->isChurchWide()) {
            return; // HQ approver.
        }

        $destinationId = $movement->destination_branch_id !== null ? (int) $movement->destination_branch_id : null;

        if ($destinationId === null || ! $scope->includes($destinationId)) {
            throw new AuthorizationException(
                'Only the destination branch or HQ may approve this movement.'
            );
        }
    }
}
