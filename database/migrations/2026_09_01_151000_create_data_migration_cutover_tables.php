<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_migration_cutover_plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('data_migration_mapping_id')->constrained('data_migration_mappings')->cascadeOnDelete();
            $table->string('environment', 32)->default('test');
            $table->timestamp('maintenance_window_start')->nullable();
            $table->timestamp('maintenance_window_end')->nullable();
            $table->boolean('backup_confirmed')->default(false);
            $table->json('rollback_criteria')->nullable();
            $table->json('acceptance_thresholds')->nullable();
            $table->string('status', 32)->default('draft');
            $table->foreignId('uat_signed_off_by')->nullable()->constrained('users');
            $table->timestamp('uat_signed_off_at')->nullable();
            $table->foreignId('go_live_approved_by')->nullable()->constrained('users');
            $table->timestamp('go_live_approved_at')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('data_migration_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_migration_cutover_plan_id')->constrained('data_migration_cutover_plans')->cascadeOnDelete();
            $table->foreignId('data_migration_mapping_version_id')->constrained('data_migration_mapping_versions');
            $table->string('run_type', 32);
            $table->string('idempotency_key')->unique();
            $table->string('status', 32)->default('running');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('summary')->nullable();
            $table->json('reconciliation')->nullable();
            $table->json('performance')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('data_migration_import_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_migration_run_id')->constrained('data_migration_runs')->cascadeOnDelete();
            $table->string('import_key')->unique();
            $table->unsignedInteger('source_row_number');
            $table->string('target_type', 64);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('status', 32);
            $table->json('lineage')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('data_migration_run_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_migration_run_id')->constrained('data_migration_runs')->cascadeOnDelete();
            $table->string('stage', 64);
            $table->string('action', 120);
            $table->foreignId('operator_id')->nullable()->constrained('users');
            $table->json('detail')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_migration_run_events');
        Schema::dropIfExists('data_migration_import_records');
        Schema::dropIfExists('data_migration_runs');
        Schema::dropIfExists('data_migration_cutover_plans');
    }
};
