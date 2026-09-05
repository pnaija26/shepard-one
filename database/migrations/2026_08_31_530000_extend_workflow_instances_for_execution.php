<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_instances', function (Blueprint $table) {
            $table->string('reference', 32)->nullable()->after('id');
            $table->string('idempotency_key', 128)->nullable()->after('reference');
            $table->string('trigger_type', 32)->nullable()->after('idempotency_key');
            $table->string('trigger_event', 120)->nullable()->after('trigger_type');
            $table->string('source_type')->nullable()->after('trigger_event');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            $table->foreignId('assignee_id')->nullable()->after('source_id')->constrained('users')->nullOnDelete();
            $table->string('required_permission', 120)->nullable()->after('assignee_id');
            $table->timestamp('due_at')->nullable()->after('current_state');
            $table->timestamp('started_at')->nullable()->after('due_at');
            $table->timestamp('completed_at')->nullable()->after('started_at');
            $table->unsignedInteger('escalation_count')->default(0)->after('completed_at');
            $table->timestamp('last_reminder_at')->nullable()->after('escalation_count');
            $table->timestamp('last_escalated_at')->nullable()->after('last_reminder_at');
            $table->string('failure_code', 64)->nullable()->after('last_escalated_at');
            $table->text('failure_message')->nullable()->after('failure_code');

            $table->unique(['workflow_id', 'idempotency_key'], 'wf_instances_idempotency_uq');
            $table->unique('reference', 'wf_instances_reference_uq');
            $table->index(['assignee_id', 'status'], 'wf_instances_assignee_status_idx');
            $table->index(['due_at', 'status'], 'wf_instances_due_status_idx');
            $table->index(['source_type', 'source_id'], 'wf_instances_source_idx');
        });

        Schema::create('workflow_instance_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_instance_id')->constrained('workflow_instances')->cascadeOnDelete();
            $table->string('event_type', 32);
            $table->string('decision', 32)->nullable();
            $table->string('from_state', 64)->nullable();
            $table->string('to_state', 64)->nullable();
            $table->text('comment')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('from_assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['workflow_instance_id', 'recorded_at'], 'wf_inst_events_recorded_idx');
        });

        Schema::create('workflow_scheduler_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_instance_id')->constrained('workflow_instances')->cascadeOnDelete();
            $table->string('action_type', 32);
            $table->string('window_key', 64);
            $table->json('metadata')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('executed_at');
            $table->timestamps();

            $table->unique(['workflow_instance_id', 'action_type', 'window_key'], 'wf_sched_actions_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_scheduler_actions');
        Schema::dropIfExists('workflow_instance_events');

        Schema::table('workflow_instances', function (Blueprint $table) {
            $table->dropUnique('wf_instances_idempotency_uq');
            $table->dropUnique('wf_instances_reference_uq');
            $table->dropIndex('wf_instances_assignee_status_idx');
            $table->dropIndex('wf_instances_due_status_idx');
            $table->dropIndex('wf_instances_source_idx');
            $table->dropConstrainedForeignId('assignee_id');
            $table->dropColumn([
                'reference',
                'idempotency_key',
                'trigger_type',
                'trigger_event',
                'source_type',
                'source_id',
                'required_permission',
                'due_at',
                'started_at',
                'completed_at',
                'escalation_count',
                'last_reminder_at',
                'last_escalated_at',
                'failure_code',
                'failure_message',
            ]);
        });
    }
};
