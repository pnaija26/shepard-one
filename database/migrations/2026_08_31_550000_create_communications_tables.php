<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communications', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->foreignId('branch_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('name', 160);
            $table->string('subject', 200);
            $table->text('body');
            $table->string('purpose', 32);
            $table->json('channels');
            $table->string('audience_type', 32);
            $table->json('audience_params')->nullable();
            $table->string('schedule_type', 32)->default('immediate');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->string('recurrence_rule', 120)->nullable(); // e.g. daily, weekly
            $table->string('status', 32)->default('queued');
            $table->boolean('sensitive_content')->default(false);
            $table->unsignedInteger('batch_size')->nullable();
            $table->unsignedInteger('rate_limit_per_minute')->nullable();
            $table->unsignedInteger('queued_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('deferred_count')->default(0);
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_run_at'], 'comms_status_next_run_idx');
            $table->index(['branch_id', 'status'], 'comms_branch_status_idx');
            $table->index(['schedule_type', 'status'], 'comms_schedule_status_idx');
        });

        Schema::create('communication_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('communication_id')->constrained('communications')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->string('channel', 32);
            $table->string('destination', 191)->nullable();
            $table->string('status', 32)->default('pending');
            $table->string('skip_reason', 64)->nullable();
            $table->unsignedInteger('attempt')->default(0);
            $table->string('provider_ref', 120)->nullable();
            $table->json('result')->nullable(); // sanitized — no full body
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('deferred_until')->nullable();
            $table->timestamps();

            $table->unique(['communication_id', 'member_id', 'channel'], 'comms_delivery_once_uq');
            $table->index(['status', 'deferred_until'], 'comms_delivery_status_defer_idx');
            $table->index(['communication_id', 'status'], 'comms_delivery_comm_status_idx');
        });

        Schema::create('communication_suppressions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->string('channel', 32)->nullable(); // null = all channels
            $table->string('reason', 64);
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('suppressed_at');
            $table->timestamps();

            $table->index(['member_id', 'active', 'channel'], 'comms_suppress_member_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_suppressions');
        Schema::dropIfExists('communication_deliveries');
        Schema::dropIfExists('communications');
    }
};
