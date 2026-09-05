<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('newsletters', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->string('name', 160);
            $table->foreignId('branch_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('status', 32)->default('draft');
            $table->unsignedInteger('current_version')->default(0);
            $table->unsignedInteger('approved_version')->nullable();
            $table->string('audience_type', 32)->default('branch');
            $table->json('audience_params')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'scheduled_at'], 'newsletters_status_sched_idx');
            $table->index(['branch_id', 'status'], 'newsletters_branch_status_idx');
        });

        Schema::create('newsletter_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('newsletter_id')->constrained('newsletters')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 32)->default('draft');
            $table->string('subject', 200);
            $table->string('preview_text', 250)->nullable();
            $table->json('sections');
            $table->boolean('has_unsubscribe')->default(false);
            $table->json('last_validation')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['newsletter_id', 'version'], 'newsletter_versions_unique');
        });

        Schema::create('newsletter_previews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('newsletter_version_id')->constrained('newsletter_versions')->cascadeOnDelete();
            $table->string('viewport', 32);
            $table->json('result');
            $table->boolean('passed')->default(false);
            $table->foreignId('ran_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ran_at');
            $table->timestamps();

            $table->index(['newsletter_version_id', 'ran_at'], 'newsletter_preview_ran_idx');
        });

        Schema::create('newsletter_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('newsletter_id')->constrained('newsletters')->cascadeOnDelete();
            $table->foreignId('newsletter_version_id')->constrained('newsletter_versions')->restrictOnDelete();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->string('channel', 32)->default('email');
            $table->string('status', 32)->default('queued'); // queued|sent|delivered|bounced|skipped
            $table->string('skip_reason', 64)->nullable();
            $table->string('provider_ref', 120)->nullable();
            $table->boolean('is_test')->default(false);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['newsletter_id', 'member_id', 'newsletter_version_id', 'is_test'], 'newsletter_delivery_once_uq');
            $table->index(['newsletter_id', 'status'], 'newsletter_delivery_status_idx');
        });

        Schema::create('newsletter_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('newsletter_id')->constrained('newsletters')->cascadeOnDelete();
            $table->foreignId('newsletter_delivery_id')->nullable()->constrained('newsletter_deliveries')->nullOnDelete();
            $table->string('event_type', 32); // sent|delivered|opened|clicked|bounced|unsubscribed
            $table->string('provider', 64)->default('simulated');
            $table->json('payload')->nullable(); // sanitized
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['newsletter_id', 'event_type'], 'newsletter_events_type_idx');
            $table->index(['occurred_at'], 'newsletter_events_occurred_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletter_events');
        Schema::dropIfExists('newsletter_deliveries');
        Schema::dropIfExists('newsletter_previews');
        Schema::dropIfExists('newsletter_versions');
        Schema::dropIfExists('newsletters');
    }
};
