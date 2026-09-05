<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_team_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_team_id')->constrained('service_teams')->restrictOnDelete();
            $table->foreignId('member_id')->constrained('members')->restrictOnDelete();
            $table->string('team_role', 32)->default('member');
            $table->string('sub_team', 120)->nullable();
            $table->string('shift_label', 120)->nullable();
            $table->json('responsibilities')->nullable();
            $table->string('status', 32)->default('pending');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->unsignedInteger('team_config_version')->default(1);
            $table->boolean('override_applied')->default(false);
            $table->text('override_reason')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->index(['service_team_id', 'status']);
            $table->index(['member_id', 'status']);
            $table->index(['effective_from', 'effective_to']);
        });

        Schema::create('service_team_assignment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_team_assignment_id');
            $table->string('event_type', 32);
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('service_team_assignment_id', 'sta_events_assignment_fk')
                ->references('id')->on('service_team_assignments')->cascadeOnDelete();
            $table->index(['service_team_assignment_id', 'created_at'], 'sta_events_assignment_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_team_assignment_events');
        Schema::dropIfExists('service_team_assignments');
    }
};
