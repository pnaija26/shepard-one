<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflows', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->string('slug', 160)->nullable();
            $table->text('description')->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('status', 32)->default('draft');
            $table->unsignedInteger('current_version')->default(0);
            $table->string('migration_policy', 32)->default('keep_locked');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'status'], 'workflows_branch_status_idx');
        });

        Schema::create('workflow_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained('workflows')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 32)->default('draft');
            $table->json('definition');
            $table->string('migration_policy', 32)->default('keep_locked');
            $table->json('last_validation')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['workflow_id', 'version'], 'workflow_versions_unique');
        });

        Schema::create('workflow_version_tests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_version_id')->constrained('workflow_versions')->cascadeOnDelete();
            $table->json('sample_payload');
            $table->json('result');
            $table->boolean('passed')->default(false);
            $table->foreignId('ran_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ran_at');
            $table->timestamps();

            $table->index(['workflow_version_id', 'ran_at'], 'wf_version_tests_ran_idx');
        });

        // Minimal instance table so publish migration policy is inspectable (Story 9.3 expands execution).
        Schema::create('workflow_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained('workflows')->cascadeOnDelete();
            $table->foreignId('workflow_version_id')->constrained('workflow_versions')->restrictOnDelete();
            $table->unsignedInteger('workflow_version');
            $table->foreignId('branch_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('status', 32)->default('open');
            $table->string('current_state', 64)->nullable();
            $table->json('context')->nullable();
            $table->timestamp('migrated_at')->nullable();
            $table->unsignedInteger('migrated_from_version')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['workflow_id', 'status'], 'wf_instances_workflow_status_idx');
            $table->index(['workflow_version_id'], 'wf_instances_version_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_instances');
        Schema::dropIfExists('workflow_version_tests');
        Schema::dropIfExists('workflow_versions');
        Schema::dropIfExists('workflows');
    }
};
