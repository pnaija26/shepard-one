<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('church_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('group_type', 32);
            $table->text('description')->nullable();
            $table->string('status', 32)->default('draft');
            $table->json('leaders');
            $table->json('meeting_pattern');
            $table->unsignedInteger('capacity')->nullable();
            $table->json('eligibility')->nullable();
            $table->json('communication_settings')->nullable();
            $table->json('reporting_settings')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
            $table->index(['branch_id', 'group_type']);
        });

        Schema::create('church_group_join_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('church_group_id')->constrained('church_groups')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->string('status', 32)->default('pending');
            $table->text('message')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();

            $table->index(['church_group_id', 'status']);
        });

        Schema::create('church_group_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('church_group_id')->constrained('church_groups')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->string('role', 32)->default('member');
            $table->string('status', 32)->default('active');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->foreignId('join_request_id')->nullable()->constrained('church_group_join_requests')->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('transfer_to_group_id')->nullable()->constrained('church_groups')->nullOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->index(['church_group_id', 'status']);
            $table->index(['member_id', 'status']);
        });

        Schema::create('church_group_membership_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('church_group_id')->constrained('church_groups')->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->string('change_type', 32);
            $table->string('role', 32)->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('church_group_membership_history');
        Schema::dropIfExists('church_group_memberships');
        Schema::dropIfExists('church_group_join_requests');
        Schema::dropIfExists('church_groups');
    }
};
