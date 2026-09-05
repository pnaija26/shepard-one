<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_offerings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('course_type', 32);
            $table->text('description')->nullable();
            $table->string('status', 32)->default('draft');
            $table->unsignedInteger('capacity')->nullable();
            $table->boolean('waitlist_enabled')->default(true);
            $table->unsignedInteger('current_version')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
            $table->index(['branch_id', 'course_type']);
        });

        Schema::create('training_offering_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_offering_id')->constrained('training_offerings')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 32)->default('draft');
            $table->json('sessions');
            $table->json('prerequisites')->nullable();
            $table->json('facilitators')->nullable();
            $table->json('assessments')->nullable();
            $table->json('materials')->nullable();
            $table->json('completion_rules')->nullable();
            $table->json('enrolment_rules')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['training_offering_id', 'version']);
        });

        Schema::create('training_enrolments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_offering_id')->constrained('training_offerings')->cascadeOnDelete();
            $table->foreignId('training_offering_version_id')->constrained('training_offering_versions')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('status', 32);
            $table->unsignedInteger('waitlist_position')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->json('schedule_snapshot')->nullable();
            $table->json('materials_snapshot')->nullable();
            $table->foreignId('enrolled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['training_offering_id', 'status']);
            $table->index(['member_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_enrolments');
        Schema::dropIfExists('training_offering_versions');
        Schema::dropIfExists('training_offerings');
    }
};
