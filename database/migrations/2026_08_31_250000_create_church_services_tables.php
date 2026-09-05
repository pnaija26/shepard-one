<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('church_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('organizations')->restrictOnDelete();
            $table->string('service_type', 64);
            $table->string('title')->nullable();
            $table->date('service_date');
            $table->time('start_time');
            $table->time('end_time')->nullable();
            $table->string('venue');
            $table->json('ministers')->nullable();
            $table->json('teams')->nullable();
            $table->unsignedInteger('capacity')->nullable();
            $table->boolean('registration_required')->default(false);
            $table->unsignedInteger('registration_capacity')->nullable();
            $table->unsignedInteger('attendance_target')->nullable();
            $table->json('livestream')->nullable();
            $table->string('status', 32)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'service_date', 'status']);
            $table->index(['branch_id', 'venue', 'service_date']);
        });

        Schema::create('church_service_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('church_service_id')->constrained('church_services')->cascadeOnDelete();
            $table->string('change_type', 32);
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->text('summary')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['church_service_id', 'created_at']);
        });

        Schema::create('church_service_change_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('church_service_id')->constrained('church_services')->cascadeOnDelete();
            $table->string('event_type', 32);
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['event_type', 'processed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('church_service_change_events');
        Schema::dropIfExists('church_service_changes');
        Schema::dropIfExists('church_services');
    }
};
