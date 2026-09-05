<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->string('slug', 160)->nullable();
            $table->text('description')->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('status', 32)->default('draft');
            $table->unsignedInteger('current_version')->default(0);
            $table->boolean('enabled')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'status'], 'auto_rules_branch_status_idx');
            $table->index(['enabled', 'status'], 'auto_rules_enabled_status_idx');
        });

        Schema::create('automation_rule_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_rule_id')->constrained('automation_rules')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 32)->default('draft');
            $table->string('event_type', 120);
            $table->json('conditions')->nullable();
            $table->string('action_type', 64);
            $table->json('action_params')->nullable();
            $table->string('scope_type', 32)->default('branch'); // branch|church_wide
            $table->unsignedInteger('priority')->default(50);
            $table->string('stop_behavior', 32)->default('continue');
            $table->string('failure_policy', 32)->default('retry');
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_to')->nullable();
            $table->boolean('requires_consent')->default(false);
            $table->json('last_validation')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['automation_rule_id', 'version'], 'auto_rule_versions_unique');
            $table->index(['event_type', 'status'], 'auto_rule_versions_event_status_idx');
        });

        Schema::create('automation_rule_simulations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_rule_version_id')->constrained('automation_rule_versions')->cascadeOnDelete();
            $table->json('sample_payload');
            $table->json('result');
            $table->boolean('passed')->default(false);
            $table->foreignId('ran_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ran_at');
            $table->timestamps();

            $table->index(['automation_rule_version_id', 'ran_at'], 'auto_rule_sim_ran_idx');
        });

        Schema::create('automation_rule_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_rule_id')->nullable()->constrained('automation_rules')->nullOnDelete();
            $table->foreignId('automation_rule_version_id')->nullable()->constrained('automation_rule_versions')->nullOnDelete();
            $table->string('event_type', 120);
            $table->string('event_key', 191);
            $table->string('outcome', 32); // matched|executed|skipped|failed|retried|quarantined
            $table->string('skip_reason', 64)->nullable();
            $table->unsignedInteger('attempt')->default(1);
            $table->json('result')->nullable(); // sanitized — no sensitive payload
            $table->string('action_type', 64)->nullable();
            $table->string('action_reference_type')->nullable();
            $table->unsignedBigInteger('action_reference_id')->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('evaluated_at');
            $table->timestamps();

            $table->index(['event_type', 'event_key'], 'auto_rule_eval_event_key_idx');
            $table->index(['outcome', 'evaluated_at'], 'auto_rule_eval_outcome_idx');
            $table->index(['automation_rule_id', 'evaluated_at'], 'auto_rule_eval_rule_idx');
        });

        Schema::create('automation_rule_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_rule_id')->constrained('automation_rules')->cascadeOnDelete();
            $table->foreignId('automation_rule_version_id')->constrained('automation_rule_versions')->cascadeOnDelete();
            $table->string('event_key', 191);
            $table->string('action_type', 64);
            $table->string('status', 32)->default('completed'); // completed|failed|quarantined
            $table->timestamp('executed_at');
            $table->timestamps();

            $table->unique(['automation_rule_id', 'event_key'], 'auto_rule_exec_once_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_rule_executions');
        Schema::dropIfExists('automation_rule_evaluations');
        Schema::dropIfExists('automation_rule_simulations');
        Schema::dropIfExists('automation_rule_versions');
        Schema::dropIfExists('automation_rules');
    }
};
