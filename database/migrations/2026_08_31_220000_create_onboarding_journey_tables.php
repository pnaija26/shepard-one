<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_journeys', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('trigger_event', 64);
            $table->foreignId('branch_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('status', 32)->default('draft');
            $table->unsignedInteger('current_version')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['trigger_event', 'status']);
        });

        Schema::create('onboarding_journey_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journey_id')->constrained('onboarding_journeys')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('steps');
            $table->json('stop_conditions')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->useCurrent();

            $table->unique(['journey_id', 'version']);
        });

        Schema::create('onboarding_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journey_id')->constrained('onboarding_journeys')->restrictOnDelete();
            $table->foreignId('journey_version_id')->constrained('onboarding_journey_versions')->restrictOnDelete();
            $table->unsignedInteger('journey_version');
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('branch_id')->constrained('organizations')->restrictOnDelete();
            $table->string('status', 32)->default('active');
            $table->string('stop_reason')->nullable();
            $table->timestamp('enrolled_at')->useCurrent();
            $table->timestamp('stopped_at')->nullable();

            $table->unique(['journey_id', 'subject_type', 'subject_id']);
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('onboarding_step_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained('onboarding_enrollments')->cascadeOnDelete();
            $table->string('step_key', 64);
            $table->unsignedSmallInteger('day_offset')->default(0);
            $table->string('action_type', 32);
            $table->timestamp('scheduled_for');
            $table->string('status', 32)->default('pending');
            $table->text('skip_reason')->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('result')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);

            $table->unique(['enrollment_id', 'step_key']);
            $table->index(['status', 'scheduled_for']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_step_runs');
        Schema::dropIfExists('onboarding_enrollments');
        Schema::dropIfExists('onboarding_journey_versions');
        Schema::dropIfExists('onboarding_journeys');
    }
};
