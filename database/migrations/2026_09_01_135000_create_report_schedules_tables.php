<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_schedules', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->string('name');
            $table->foreignId('owner_id')->constrained('users');
            $table->foreignId('branch_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('report_type', 32);
            $table->string('report_key')->nullable();
            $table->foreignId('custom_report_id')->nullable()->constrained('custom_reports')->nullOnDelete();
            $table->string('format', 32);
            $table->string('delivery_channel', 32);
            $table->string('timezone', 64)->default('UTC');
            $table->string('recurrence', 32);
            $table->json('recurrence_params')->nullable();
            $table->json('filters')->nullable();
            $table->string('classification', 64)->default('internal');
            $table->json('recipient_user_ids');
            $table->string('status', 32)->default('active');
            $table->timestamp('next_run_at');
            $table->timestamp('last_run_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create('report_schedule_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_schedule_id')->constrained('report_schedules')->cascadeOnDelete();
            $table->string('run_key')->unique();
            $table->timestamp('scheduled_for');
            $table->string('status', 32)->default('pending');
            $table->foreignId('report_export_id')->nullable()->constrained('report_exports')->nullOnDelete();
            $table->json('recipient_snapshot')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('generation_checked_at')->nullable();
            $table->timestamp('delivery_checked_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('report_schedule_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_schedule_run_id')->constrained('report_schedule_runs')->cascadeOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users');
            $table->string('status', 32)->default('pending');
            $table->string('channel', 32);
            $table->timestamp('delivered_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['report_schedule_run_id', 'recipient_user_id'], 'rsd_run_recipient_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_schedule_deliveries');
        Schema::dropIfExists('report_schedule_runs');
        Schema::dropIfExists('report_schedules');
    }
};
