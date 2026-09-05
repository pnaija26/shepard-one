<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('care_case_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('care_case_id')->constrained('care_cases')->cascadeOnDelete();
            $table->string('activity_type', 32);
            $table->string('outcome', 64)->nullable();
            $table->text('notes')->nullable();
            $table->text('restricted_note')->nullable(); // encrypted
            $table->date('next_follow_up_on')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('responsible_officer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['care_case_id', 'recorded_at']);
        });

        Schema::create('care_case_escalations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('care_case_id')->constrained('care_cases')->cascadeOnDelete();
            $table->string('trigger_type', 64);
            $table->foreignId('from_officer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_officer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('escalated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('created_at');

            $table->index(['care_case_id', 'trigger_type']);
        });

        Schema::table('care_cases', function (Blueprint $table) {
            $table->date('next_follow_up_on')->nullable()->after('assigned_at');
            $table->timestamp('closed_at')->nullable()->after('next_follow_up_on');
            $table->string('closure_reason', 64)->nullable()->after('closed_at');
            $table->text('closure_outcome')->nullable()->after('closure_reason'); // encrypted
            $table->text('future_care_plan')->nullable()->after('closure_outcome'); // encrypted
            $table->timestamp('reopened_at')->nullable()->after('future_care_plan');
            $table->string('reopen_reason', 255)->nullable()->after('reopened_at');
            $table->timestamp('escalated_at')->nullable()->after('reopen_reason');
        });
    }

    public function down(): void
    {
        Schema::table('care_cases', function (Blueprint $table) {
            $table->dropColumn([
                'next_follow_up_on',
                'closed_at',
                'closure_reason',
                'closure_outcome',
                'future_care_plan',
                'reopened_at',
                'reopen_reason',
                'escalated_at',
            ]);
        });

        Schema::dropIfExists('care_case_escalations');
        Schema::dropIfExists('care_case_activities');
    }
};
