<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 1.6: user <-> role assignments (AC2, AC3).
 *
 * `expires_at` implements time-limited grants: an expired assignment is
 * simply not counted when the effective permission set is computed, so a
 * removed/expired capability denies access on the next request without any
 * deployment (AC3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();

            // Who granted this, for the audit trail.
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();

            // Optional expiry (AC3): NULL = no expiry.
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'role_id'], 'role_assignments_user_role_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_assignments');
    }
};
