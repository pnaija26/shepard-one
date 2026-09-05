<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_incidents', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 32)->unique();
            $table->foreignId('branch_id')->constrained('organizations')->restrictOnDelete();
            $table->string('classification', 64);
            $table->string('priority', 32);
            $table->string('status', 32)->default('open');
            $table->timestamp('occurred_at');
            $table->string('location', 255);
            $table->text('description');
            $table->text('sensitive_details')->nullable();
            $table->json('evidence')->nullable();
            $table->string('assigned_team', 64);
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->boolean('is_restricted')->default(false);
            $table->boolean('requires_review')->default(false);
            $table->text('closure_outcome')->nullable();
            $table->text('follow_up_actions')->nullable();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
            $table->index(['owner_id', 'status']);
            $table->index(['classification', 'priority']);
        });

        Schema::create('operational_incident_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operational_incident_id')->constrained('operational_incidents')->cascadeOnDelete();
            $table->string('activity_type', 32);
            $table->text('notes')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['operational_incident_id', 'created_at'], 'incident_activities_incident_created_idx');
        });

        Schema::create('operational_incident_escalations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operational_incident_id')->constrained('operational_incidents')->cascadeOnDelete();
            $table->string('trigger_type', 32);
            $table->foreignId('from_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_owner_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('escalated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['operational_incident_id', 'trigger_type'], 'incident_escalations_unique_trigger');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_incident_escalations');
        Schema::dropIfExists('operational_incident_activities');
        Schema::dropIfExists('operational_incidents');
    }
};
