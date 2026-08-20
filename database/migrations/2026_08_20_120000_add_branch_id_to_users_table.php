<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Story 1.4: users are assigned to a branch (organizations row of type
     * 'branch'). A NULL branch_id means church-wide (HQ) scope — the user may
     * see consolidated data across all branches. Scoping is always enforced
     * server-side from this assignment; client-supplied parameters never widen it.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Cascade (not nullOnDelete): if a branch disappears, its assigned
            // users must NOT silently become unscoped/church-wide — that would be
            // a privilege escalation. They are removed with the branch instead.
            $table->foreignId('branch_id')
                ->nullable()
                ->after('roles')
                ->constrained('organizations')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
