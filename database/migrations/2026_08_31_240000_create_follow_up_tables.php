<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follow_ups', function (Blueprint $table) {
            $table->id();
            $table->string('person_type');
            $table->unsignedBigInteger('person_id');
            $table->foreignId('branch_id')->constrained('organizations')->restrictOnDelete();
            $table->text('reason');
            $table->foreignId('assignee_id')->constrained('users')->restrictOnDelete();
            $table->date('due_date');
            $table->string('contact_method', 32)->default('phone');
            $table->string('priority', 32)->default('normal');
            $table->string('source_type', 64)->default('manual');
            $table->string('source_reference_type')->nullable();
            $table->unsignedBigInteger('source_reference_id')->nullable();
            $table->string('status', 32)->default('assigned');
            $table->boolean('is_restricted')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['assignee_id', 'status', 'due_date']);
            $table->index(['person_type', 'person_id']);
            $table->index(['branch_id', 'status']);
            $table->index(['source_reference_type', 'source_reference_id']);
        });

        Schema::create('follow_up_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('follow_up_id')->constrained('follow_ups')->cascadeOnDelete();
            $table->string('activity_type', 32);
            $table->string('outcome', 32)->nullable();
            $table->text('notes')->nullable();
            $table->string('next_action', 32)->nullable();
            $table->date('next_due_date')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['follow_up_id', 'created_at']);
        });

        Schema::create('follow_up_escalations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('follow_up_id')->constrained('follow_ups')->cascadeOnDelete();
            $table->string('trigger_type', 32);
            $table->foreignId('from_assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_assignee_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('escalated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['follow_up_id', 'trigger_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_up_escalations');
        Schema::dropIfExists('follow_up_activities');
        Schema::dropIfExists('follow_ups');
    }
};
