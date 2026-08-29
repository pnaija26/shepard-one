<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 1.6: scoped permission rows attached to a role (AC1).
 *
 * A row grants one supported `action`, optionally narrowed by module,
 * function and record type, within an organizational scope:
 *   - scope_type 'global'            -> applies everywhere (scope_id NULL);
 *   - scope_type organization/branch/ministry/department/team/group/campus/location
 *                                      -> applies to that organization AND its
 *                                        descendants (same subtree semantics as
 *                                        Story 1.4's BranchScope).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();

            // Scope dimension: global, or an organization-unit type.
            $table->string('scope_type')->default('global');
            $table->unsignedBigInteger('scope_id')->nullable();

            // Granularity dimensions (AC1): module / function / record type.
            $table->string('module')->nullable();
            $table->string('function_name')->nullable()->index();
            $table->string('record_type')->nullable();

            // The supported action this row grants ('*' = any action).
            $table->string('action');

            $table->timestamps();

            $table->unique(['role_id', 'scope_type', 'scope_id', 'module', 'function_name', 'record_type', 'action'], 'role_permissions_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
    }
};
