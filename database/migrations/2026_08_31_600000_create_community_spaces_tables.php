<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_spaces', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->string('name', 160);
            $table->string('space_type', 32);
            $table->foreignId('branch_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('status', 32)->default('active'); // active|archived
            $table->unsignedInteger('retention_days')->default(365);
            $table->boolean('requires_consent')->default(true);
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['space_type', 'status'], 'cs_spaces_type_status_idx');
            $table->index(['branch_id', 'status'], 'cs_spaces_branch_status_idx');
        });

        Schema::create('community_space_memberships', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('community_space_id');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->string('role', 32)->default('member'); // member|moderator
            $table->string('status', 32)->default('active'); // active|muted|banned|left
            $table->timestamp('joined_at');
            $table->timestamp('moderated_at')->nullable();
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('moderation_reason', 255)->nullable();
            $table->timestamps();

            $table->foreign('community_space_id', 'cs_member_space_fk')
                ->references('id')->on('community_spaces')->cascadeOnDelete();
            $table->unique(['community_space_id', 'user_id'], 'cs_member_uq');
            $table->index(['community_space_id', 'status'], 'cs_member_status_idx');
        });

        Schema::create('community_space_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('community_space_id');
            $table->foreignId('sender_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('sender_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->string('message_type', 32);
            $table->text('body')->nullable();
            $table->json('attachments')->nullable();
            $table->json('poll_options')->nullable();
            $table->string('status', 32)->default('visible'); // visible|restricted|removed
            $table->boolean('is_pinned')->default(false);
            $table->timestamp('pinned_at')->nullable();
            $table->foreignId('pinned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('removal_reason', 255)->nullable();
            $table->boolean('is_sensitive')->default(false);
            $table->timestamp('retain_until')->nullable();
            $table->timestamps();

            $table->foreign('community_space_id', 'cs_msg_space_fk')
                ->references('id')->on('community_spaces')->cascadeOnDelete();
            $table->index(['community_space_id', 'created_at'], 'cs_msg_created_idx');
            $table->index(['community_space_id', 'status'], 'cs_msg_status_idx');
            $table->index(['community_space_id', 'is_pinned'], 'cs_msg_pinned_idx');
            $table->index(['retain_until'], 'cs_msg_retain_idx');
        });

        Schema::create('community_space_moderation_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('community_space_id');
            $table->unsignedBigInteger('community_space_message_id')->nullable();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('action', 32);
            $table->string('reason', 255)->nullable();
            $table->json('details')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->foreign('community_space_id', 'cs_mod_space_fk')
                ->references('id')->on('community_spaces')->cascadeOnDelete();
            $table->foreign('community_space_message_id', 'cs_mod_msg_fk')
                ->references('id')->on('community_space_messages')->nullOnDelete();
            $table->index(['community_space_id', 'occurred_at'], 'cs_mod_occurred_idx');
            $table->index(['action'], 'cs_mod_action_idx');
        });

        Schema::create('community_space_integrations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('community_space_id');
            $table->string('provider', 64);
            $table->boolean('enabled')->default(false);
            $table->boolean('consent_documented')->default(false);
            $table->json('identity_mapping')->nullable();
            $table->text('moderation_boundary')->nullable();
            $table->json('config')->nullable();
            $table->foreignId('configured_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('configured_at')->nullable();
            $table->timestamps();

            $table->foreign('community_space_id', 'cs_integ_space_fk')
                ->references('id')->on('community_spaces')->cascadeOnDelete();
            $table->unique(['community_space_id', 'provider'], 'cs_integ_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_space_integrations');
        Schema::dropIfExists('community_space_moderation_events');
        Schema::dropIfExists('community_space_messages');
        Schema::dropIfExists('community_space_memberships');
        Schema::dropIfExists('community_spaces');
    }
};
