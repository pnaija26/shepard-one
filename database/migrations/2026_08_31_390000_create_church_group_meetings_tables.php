<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('church_group_meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('church_group_id')->constrained('church_groups')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('title', 160);
            $table->string('meeting_type', 32)->default('meeting');
            $table->timestamp('scheduled_at');
            $table->timestamp('completed_at')->nullable();
            $table->string('status', 32)->default('scheduled');
            $table->string('location', 160)->nullable();
            $table->text('notes')->nullable();
            $table->text('sensitive_notes')->nullable();
            $table->json('prayer_needs')->nullable();
            $table->json('actions')->nullable();
            $table->json('report_fields')->nullable();
            $table->timestamp('report_submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['church_group_id', 'status']);
            $table->index(['church_group_id', 'scheduled_at']);
        });

        Schema::create('church_group_meeting_attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('church_group_meeting_id')->constrained('church_group_meetings')->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->foreignId('visitor_id')->nullable()->constrained('visitors')->nullOnDelete();
            $table->string('person_name', 160)->nullable();
            $table->string('status', 32);
            $table->string('notes', 500)->nullable();
            $table->string('corrected_from_status', 32)->nullable();
            $table->text('correction_reason')->nullable();
            $table->foreignId('corrected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('corrected_at')->nullable();
            $table->timestamps();

            $table->index(['church_group_meeting_id', 'member_id'], 'cgm_attendance_meeting_member_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('church_group_meeting_attendance');
        Schema::dropIfExists('church_group_meetings');
    }
};
