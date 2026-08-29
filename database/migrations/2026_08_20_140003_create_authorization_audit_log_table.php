<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 1.6: authorization audit trail (AC4 "records the attempted change",
 * AC3 invalidation events, and any blocked/denied privileged operation).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authorization_audit_log', function (Blueprint $table) {
            $table->id();

            // The actor who performed / attempted the change.
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            // What happened: role.created, role.updated, role.deleted,
            // permission.granted, permission.revoked, assignment.made,
            // assignment.removed, assignment.expired, cache.invalidated,
            // last_super_admin.blocked, break_glass.approved ...
            $table->string('event');

            // The affected subject (role id / user id) and its kind.
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            // Free-form detail payload (permission rows, reasons, etc.).
            $table->json('detail')->nullable();

            $table->timestamps();

            $table->index(['event', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authorization_audit_log');
    }
};
