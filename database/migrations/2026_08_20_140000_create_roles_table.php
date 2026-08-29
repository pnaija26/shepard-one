<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 1.6: Manage Scoped Roles and Permissions — roles table (AC1).
 *
 * A role is a named bundle of scoped permissions (role_permissions rows).
 * `is_super_admin` marks the break-glass tier protected by AC4; `is_system`
 * protects built-in roles from accidental deletion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_super_admin')->default(false);
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
