<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_session_attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_enrolment_id')->constrained('training_enrolments')->cascadeOnDelete();
            $table->string('session_key', 120);
            $table->string('session_title', 160);
            $table->string('status', 32);
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['training_enrolment_id', 'session_key'], 'tsa_enrolment_session_unique');
        });

        Schema::create('training_session_attendance_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_session_attendance_id');
            $table->foreignId('corrected_by')->constrained('users')->cascadeOnDelete();
            $table->string('before_status', 32);
            $table->string('after_status', 32);
            $table->string('reason', 500);
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('training_session_attendance_id', 'tsa_corrections_attendance_fk')
                ->references('id')->on('training_session_attendance')->cascadeOnDelete();
        });

        Schema::create('training_assessment_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_enrolment_id')->constrained('training_enrolments')->cascadeOnDelete();
            $table->string('assessment_key', 120);
            $table->string('assessment_title', 160);
            $table->string('result_status', 32)->default('pending');
            $table->decimal('score', 5, 2)->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['training_enrolment_id', 'assessment_key'], 'tar_enrolment_assessment_unique');
        });

        Schema::create('training_assessment_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_assessment_result_id');
            $table->foreignId('corrected_by')->constrained('users')->cascadeOnDelete();
            $table->string('before_status', 32);
            $table->string('after_status', 32);
            $table->string('reason', 500);
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('training_assessment_result_id', 'ta_corrections_result_fk')
                ->references('id')->on('training_assessment_results')->cascadeOnDelete();
        });

        Schema::create('training_completion_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_enrolment_id')->unique()->constrained('training_enrolments')->cascadeOnDelete();
            $table->string('status', 32)->default('in_progress');
            $table->decimal('progress_percent', 5, 2)->default(0);
            $table->json('unmet_criteria')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('training_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_enrolment_id')->unique()->constrained('training_enrolments')->cascadeOnDelete();
            $table->foreignId('training_offering_id')->constrained('training_offerings')->cascadeOnDelete();
            $table->foreignId('training_offering_version_id')->constrained('training_offering_versions')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('certificate_reference', 32)->unique();
            $table->string('course_name', 160);
            $table->unsignedInteger('course_version');
            $table->date('completion_date');
            $table->string('status', 32)->default('issued');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revocation_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['member_id', 'status']);
            $table->index(['certificate_reference', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_certificates');
        Schema::dropIfExists('training_completion_records');
        Schema::dropIfExists('training_assessment_corrections');
        Schema::dropIfExists('training_assessment_results');
        Schema::dropIfExists('training_session_attendance_corrections');
        Schema::dropIfExists('training_session_attendance');
    }
};
