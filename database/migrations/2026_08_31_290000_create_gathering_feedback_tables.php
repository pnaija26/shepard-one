<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gathering_feedback', function (Blueprint $table) {
            $table->id();
            $table->string('gathering_type', 64);
            $table->unsignedBigInteger('gathering_id');
            $table->foreignId('branch_id')->constrained('organizations')->restrictOnDelete();
            $table->string('category', 64);
            $table->text('body');
            $table->unsignedTinyInteger('rating')->nullable();
            $table->string('identity_mode', 32)->default('identified');
            $table->string('submitter_type')->nullable();
            $table->unsignedBigInteger('submitter_id')->nullable();
            $table->string('submitter_display_name')->nullable();
            $table->string('assigned_team', 64);
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32)->default('submitted');
            $table->string('moderation_reason')->nullable();
            $table->boolean('consent_feedback_notifications')->default(false);
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['gathering_type', 'gathering_id']);
            $table->index(['branch_id', 'status']);
            $table->index(['assigned_team', 'status']);
            $table->index(['submitter_type', 'submitter_id']);
        });

        Schema::create('gathering_feedback_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gathering_feedback_id')->constrained('gathering_feedback')->cascadeOnDelete();
            $table->string('activity_type', 32);
            $table->text('notes')->nullable();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('notify_submitter')->default(false);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['gathering_feedback_id', 'created_at'], 'gf_activities_feedback_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gathering_feedback_activities');
        Schema::dropIfExists('gathering_feedback');
    }
};
