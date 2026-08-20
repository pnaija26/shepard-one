<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Story 1.5: preserve a person's historical branch relationships (retention).
     *
     * Append-only log of "person X was associated with branch Y from A to B".
     * When a movement is applied we close out the previous association and open
     * a new one — so the full timeline of where an identity has been over time
     * is always reconstructable, independent of the workflow state of any single
     * movement request. This is what makes "member history is preserved without
     * creating duplicate people" verifiable after the fact.
     */
    public function up(): void
    {
        Schema::create('branch_association_history', function (Blueprint $table) {
            $table->id();

            // The centrally identified person (same identity across all rows).
            $table->foreignId('person_id')->constrained('users')->cascadeOnDelete();

            // The branch they were associated with. nullOnDelete so the history
            // row survives even if the branch is later removed.
            $table->foreignId('branch_id')->nullable()->constrained('organizations')->nullOnDelete();

            // When this association became active / ceased to be active.
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable(); // NULL = still the current association

            // What caused this association (e.g. 'initial', 'movement_applied:12').
            $table->string('source')->default('initial');

            $table->timestamps();

            $table->index(['person_id', 'started_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branch_association_history');
    }
};
