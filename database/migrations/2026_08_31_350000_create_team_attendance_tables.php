<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_occurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_team_id')->constrained('service_teams')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('organizations')->restrictOnDelete();
            $table->string('occurrence_type', 32);
            $table->string('title', 160);
            $table->date('occurrence_date');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->foreignId('team_roster_id')->nullable()->constrained('team_rosters')->nullOnDelete();
            $table->foreignId('team_roster_slot_id')->nullable()->constrained('team_roster_slots')->nullOnDelete();
            $table->string('gathering_key', 64)->nullable();
            $table->unsignedBigInteger('gathering_id')->nullable();
            $table->string('status', 32)->default('scheduled');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['service_team_id', 'occurrence_date']);
            $table->index(['branch_id', 'occurrence_date']);
        });

        Schema::create('team_attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_occurrence_id')->constrained('team_occurrences')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('members')->restrictOnDelete();
            $table->foreignId('team_roster_slot_id')->nullable()->constrained('team_roster_slots')->nullOnDelete();
            $table->string('status', 32);
            $table->timestamp('captured_at')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('corrected_at')->nullable();
            $table->string('original_status', 32)->nullable();
            $table->text('correction_reason')->nullable();
            $table->timestamps();

            $table->unique(['team_occurrence_id', 'member_id']);
            $table->index(['member_id', 'status']);
        });

        Schema::create('team_attendance_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_attendance_record_id')->constrained('team_attendance_records')->cascadeOnDelete();
            $table->foreignId('corrected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('before_status', 32);
            $table->string('after_status', 32);
            $table->text('reason');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['team_attendance_record_id', 'created_at'], 'ta_corrections_record_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_attendance_corrections');
        Schema::dropIfExists('team_attendance_records');
        Schema::dropIfExists('team_occurrences');
    }
};
