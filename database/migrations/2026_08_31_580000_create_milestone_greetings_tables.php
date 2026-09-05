<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->string('type', 32); // wedding|membership|baptism|ordination|service
            $table->date('occurred_on');
            $table->boolean('active')->default(true);
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['member_id', 'type'], 'member_milestones_member_type_uq');
            $table->index(['type', 'active', 'occurred_on'], 'member_milestones_type_date_idx');
        });

        Schema::create('milestone_greeting_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('milestone_type', 32);
            $table->foreignId('message_template_id')->constrained('message_templates')->restrictOnDelete();
            $table->json('channels');
            $table->boolean('enabled')->default(true);
            $table->boolean('team_alerts_enabled')->default(true);
            $table->string('team_alert_permission', 80)->nullable(); // e.g. communications.read
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['branch_id', 'milestone_type'], 'milestone_greeting_config_branch_type_uq');
            $table->index(['enabled', 'milestone_type'], 'milestone_greeting_config_enabled_idx');
        });

        Schema::create('milestone_greeting_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->string('milestone_type', 32);
            $table->string('period_key', 16); // e.g. 2026
            $table->string('outcome', 32); // sent|skipped|failed
            $table->string('skip_reason', 64)->nullable();
            $table->unsignedBigInteger('milestone_greeting_config_id')->nullable();
            $table->foreign('milestone_greeting_config_id', 'ms_greet_eval_config_fk')
                ->references('id')->on('milestone_greeting_configs')->nullOnDelete();
            $table->unsignedBigInteger('communication_id')->nullable();
            $table->foreign('communication_id', 'ms_greet_eval_comm_fk')
                ->references('id')->on('communications')->nullOnDelete();
            $table->unsignedBigInteger('message_template_version_id')->nullable();
            $table->foreign('message_template_version_id', 'ms_greet_eval_tpl_ver_fk')
                ->references('id')->on('message_template_versions')->nullOnDelete();
            $table->json('result')->nullable(); // sanitized
            $table->foreignId('branch_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('evaluated_at');
            $table->timestamps();

            $table->unique(['member_id', 'milestone_type', 'period_key'], 'milestone_greeting_once_uq');
            $table->index(['outcome', 'evaluated_at'], 'milestone_greeting_outcome_idx');
            $table->index(['milestone_type', 'period_key'], 'milestone_greeting_type_period_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('milestone_greeting_evaluations');
        Schema::dropIfExists('milestone_greeting_configs');
        Schema::dropIfExists('member_milestones');
    }
};
