<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('welfare_follow_up_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('welfare_request_id')->constrained('welfare_requests')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('entry_type', 32);
            $table->string('outcome', 32)->nullable();
            $table->string('further_action', 32)->nullable();
            $table->text('notes')->nullable();
            $table->date('follow_up_due_on')->nullable();
            $table->foreignId('from_officer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_officer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('closure_reason', 64)->nullable();
            $table->json('evidence')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['welfare_request_id', 'recorded_at']);
            $table->index(['branch_id', 'follow_up_due_on']);
        });

        Schema::create('welfare_follow_up_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('welfare_request_id')->constrained('welfare_requests')->cascadeOnDelete();
            $table->string('reminder_type', 32);
            $table->timestamp('sent_at');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['welfare_request_id', 'reminder_type', 'sent_at'], 'wfu_reminders_request_type_sent_idx');
        });

        Schema::table('welfare_requests', function (Blueprint $table) {
            $table->date('follow_up_due_on')->nullable()->after('follow_up_at');
            $table->timestamp('closed_at')->nullable()->after('follow_up_due_on');
            $table->string('closure_reason', 64)->nullable()->after('closed_at');
            $table->timestamp('last_follow_up_reminder_at')->nullable()->after('closure_reason');
            $table->timestamp('follow_up_escalated_at')->nullable()->after('last_follow_up_reminder_at');
        });
    }

    public function down(): void
    {
        Schema::table('welfare_requests', function (Blueprint $table) {
            $table->dropColumn([
                'follow_up_due_on',
                'closed_at',
                'closure_reason',
                'last_follow_up_reminder_at',
                'follow_up_escalated_at',
            ]);
        });

        Schema::dropIfExists('welfare_follow_up_reminders');
        Schema::dropIfExists('welfare_follow_up_entries');
    }
};
