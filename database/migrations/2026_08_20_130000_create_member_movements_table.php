<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Story 1.5: control cross-branch identity movement.
     *
     * A "person" is a centrally identified individual — today that is a user
     * account (users table); when Epic 2 introduces member profiles this FK
     * will point at the central member record instead. The point of this table
     * is that a person moves between branches as ONE identity: no duplicate
     * people are ever created, and every decision (initiate / approve / reject)
     * is recorded here with who decided it, when, and why.
     */
    public function up(): void
    {
        Schema::create('member_movements', function (Blueprint $table) {
            $table->id();

            // The centrally identified person being moved. Never duplicated —
            // the same row (same identity) is re-associated with a new branch.
            $table->foreignId('person_id')->constrained('users')->cascadeOnDelete();

            // Branch association at the time of initiation (NULL = unassigned).
            // nullOnDelete: if a branch is later removed, the audit row survives
            // rather than being destroyed with it.
            $table->foreignId('source_branch_id')->nullable()->constrained('organizations')->nullOnDelete();

            // The branch the person should be associated with once effective.
            $table->foreignId('destination_branch_id')->nullable()->constrained('organizations')->nullOnDelete();

            // When the association change takes effect (not necessarily when approved).
            $table->date('effective_date');

            // Why the movement is requested / decided — required for audit.
            $table->text('reason');

            // pending -> approved | rejected ; approved -> applied (on effective date)
            $table->string('status')->default('pending');

            // Who initiated the request.
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();

            // Decision audit: who approved/rejected, when, and their stated reason.
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();

            // Set when the association actually changed (effective date reached).
            $table->timestamp('applied_at')->nullable();

            $table->timestamps();

            $table->index(['person_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('member_movements');
    }
};
