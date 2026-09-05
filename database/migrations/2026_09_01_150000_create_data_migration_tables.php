<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_migration_sources', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->string('name');
            $table->string('source_type', 32);
            $table->foreignId('branch_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('status', 32)->default('uploaded');
            $table->string('storage_disk', 32)->default('local');
            $table->string('storage_path')->nullable();
            $table->string('file_hash', 128)->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->string('classification', 64)->default('restricted');
            $table->timestamp('retention_ends_at')->nullable();
            $table->json('connection_config')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('data_migration_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_migration_source_id')->constrained('data_migration_sources')->cascadeOnDelete();
            $table->json('columns');
            $table->json('summary');
            $table->json('sensitive_fields')->nullable();
            $table->json('duplicate_keys')->nullable();
            $table->timestamp('profiled_at');
            $table->timestamps();
        });

        Schema::create('data_migration_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_migration_source_id')->constrained('data_migration_sources')->cascadeOnDelete();
            $table->string('name');
            $table->string('target_entity', 64);
            $table->foreignId('branch_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->unsignedInteger('current_version')->default(1);
            $table->string('status', 32)->default('draft');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('data_migration_mapping_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('data_migration_mapping_id');
            $table->foreign('data_migration_mapping_id', 'dmmv_mapping_fk')
                ->references('id')
                ->on('data_migration_mappings')
                ->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->json('field_mappings');
            $table->json('transformations')->nullable();
            $table->json('defaults')->nullable();
            $table->json('duplicate_rules')->nullable();
            $table->json('validation_errors')->nullable();
            $table->string('status', 32)->default('draft');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['data_migration_mapping_id', 'version_number'], 'dmmv_mapping_version_unique');
        });

        Schema::create('data_migration_mapping_tests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('data_migration_mapping_version_id');
            $table->foreign('data_migration_mapping_version_id', 'dmmt_version_fk')
                ->references('id')
                ->on('data_migration_mapping_versions')
                ->cascadeOnDelete();
            $table->unsignedInteger('sample_size');
            $table->json('results');
            $table->boolean('passed')->default(false);
            $table->timestamp('run_at');
            $table->timestamps();
        });

        Schema::create('data_migration_validation_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('data_migration_mapping_version_id');
            $table->foreign('data_migration_mapping_version_id', 'dmvr_version_fk')
                ->references('id')
                ->on('data_migration_mapping_versions')
                ->cascadeOnDelete();
            $table->string('status', 32)->default('running');
            $table->json('summary')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('data_migration_validation_results', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('data_migration_validation_run_id');
            $table->foreign('data_migration_validation_run_id', 'dmvres_run_fk')
                ->references('id')
                ->on('data_migration_validation_runs')
                ->cascadeOnDelete();
            $table->unsignedInteger('source_row_number');
            $table->string('outcome', 32);
            $table->json('reasons')->nullable();
            $table->json('original_data')->nullable();
            $table->json('mapped_data')->nullable();
            $table->timestamps();

            $table->index(['data_migration_validation_run_id', 'outcome'], 'dmvr_run_outcome_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_migration_validation_results');
        Schema::dropIfExists('data_migration_validation_runs');
        Schema::dropIfExists('data_migration_mapping_tests');
        Schema::dropIfExists('data_migration_mapping_versions');
        Schema::dropIfExists('data_migration_mappings');
        Schema::dropIfExists('data_migration_profiles');
        Schema::dropIfExists('data_migration_sources');
    }
};
