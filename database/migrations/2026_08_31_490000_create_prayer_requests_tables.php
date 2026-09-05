<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prayer_requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->foreignId('branch_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('requester_member_id')->constrained('members')->restrictOnDelete();
            $table->foreignId('requester_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('assisted_submission')->default(false);
            $table->string('category', 64);
            $table->string('priority', 32)->default('normal');
            $table->text('request_body'); // encrypted
            $table->string('confidentiality', 32);
            $table->string('previous_confidentiality', 32)->nullable();
            $table->foreignId('church_group_id')->nullable()->constrained('church_groups')->nullOnDelete();
            $table->boolean('consent_prayer_processing')->default(false);
            $table->boolean('consent_sharing')->default(false);
            $table->string('status', 32)->default('submitted');
            $table->string('data_classification', 64);
            $table->boolean('is_restricted')->default(true);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('confidentiality_changed_at')->nullable();
            $table->timestamp('propagation_completed_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->string('withdrawal_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
            $table->index(['confidentiality', 'status']);
            $table->index(['requester_member_id', 'status']);
            $table->index(['church_group_id', 'status']);
        });

        Schema::create('prayer_request_confidentiality_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prayer_request_id')->constrained('prayer_requests')->cascadeOnDelete();
            $table->string('from_confidentiality', 32)->nullable();
            $table->string('to_confidentiality', 32);
            $table->string('change_type', 32);
            $table->text('reason')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('effective_at');
            $table->timestamp('propagation_completed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            $table->index(['prayer_request_id', 'created_at'], 'pr_conf_events_request_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prayer_request_confidentiality_events');
        Schema::dropIfExists('prayer_requests');
    }
};
