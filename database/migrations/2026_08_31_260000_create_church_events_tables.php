<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('church_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('organizations')->restrictOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('event_date');
            $table->date('end_date')->nullable();
            $table->time('start_time');
            $table->time('end_time')->nullable();
            $table->string('venue');
            $table->unsignedInteger('capacity')->nullable();
            $table->json('speakers')->nullable();
            $table->json('registration')->nullable();
            $table->json('ticketing_policy')->nullable();
            $table->json('volunteers')->nullable();
            $table->json('materials')->nullable();
            $table->json('budget')->nullable();
            $table->json('reminders')->nullable();
            $table->string('status', 32)->default('draft');
            $table->string('registration_availability', 32)->default('not_applicable');
            $table->date('postponed_to_date')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('postponed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'event_date', 'status']);
        });

        Schema::create('church_event_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('church_event_id')->constrained('church_events')->cascadeOnDelete();
            $table->string('change_type', 32);
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->text('summary')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['church_event_id', 'created_at']);
        });

        Schema::create('church_event_change_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('church_event_id')->constrained('church_events')->cascadeOnDelete();
            $table->string('event_type', 32);
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('church_event_close_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('church_event_id')->constrained('church_events')->cascadeOnDelete();
            $table->unsignedInteger('registrations_count')->default(0);
            $table->unsignedInteger('attendance_count')->default(0);
            $table->json('volunteer_participation')->nullable();
            $table->unsignedInteger('feedback_count')->default(0);
            $table->unsignedInteger('incidents_count')->default(0);
            $table->json('budget_summary')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('snapshot_at')->useCurrent();

            $table->unique('church_event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('church_event_close_snapshots');
        Schema::dropIfExists('church_event_change_events');
        Schema::dropIfExists('church_event_changes');
        Schema::dropIfExists('church_events');
    }
};