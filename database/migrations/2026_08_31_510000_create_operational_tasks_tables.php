<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 32)->unique();
            $table->foreignId('branch_id')->constrained('organizations')->restrictOnDelete();
            $table->string('department', 64);
            $table->string('title', 255);
            $table->text('description');
            $table->string('priority', 32)->default('normal');
            $table->string('status', 32)->default('open');
            $table->foreignId('assignee_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date');
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->json('attachments')->nullable();
            $table->json('completion_evidence')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('marked_overdue_at')->nullable();
            $table->timestamp('last_overdue_reminder_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status', 'due_date'], 'op_tasks_branch_status_due_idx');
            $table->index(['assignee_id', 'status'], 'op_tasks_assignee_status_idx');
            $table->index(['source_type', 'source_id'], 'op_tasks_source_idx');
        });

        Schema::create('operational_task_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operational_task_id')->constrained('operational_tasks')->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->text('notes')->nullable();
            $table->json('completion_evidence')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['operational_task_id', 'recorded_at'], 'op_task_trans_task_recorded_idx');
        });

        Schema::create('operational_task_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operational_task_id')->constrained('operational_tasks')->cascadeOnDelete();
            $table->string('reminder_type', 32);
            $table->timestamp('sent_at');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['operational_task_id', 'reminder_type', 'sent_at'], 'op_task_remind_type_sent_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_task_reminders');
        Schema::dropIfExists('operational_task_transitions');
        Schema::dropIfExists('operational_tasks');
    }
};
