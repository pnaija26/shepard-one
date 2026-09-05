<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_rosters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_team_id')->constrained('service_teams')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('organizations')->restrictOnDelete();
            $table->string('roster_type', 32);
            $table->string('title', 160);
            $table->string('status', 32)->default('draft');
            $table->string('gathering_key', 64)->nullable();
            $table->unsignedBigInteger('gathering_id')->nullable();
            $table->date('period_start');
            $table->date('period_end');
            $table->json('staffing_requirements')->nullable();
            $table->json('conflict_summary')->nullable();
            $table->boolean('override_applied')->default(false);
            $table->text('override_reason')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['service_team_id', 'status']);
            $table->index(['period_start', 'period_end']);
            $table->index(['gathering_key', 'gathering_id']);
        });

        Schema::create('team_roster_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_roster_id')->constrained('team_rosters')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('members')->restrictOnDelete();
            $table->foreignId('service_team_assignment_id')->nullable()->constrained('service_team_assignments')->nullOnDelete();
            $table->string('duty_label', 120);
            $table->string('shift_label', 120)->nullable();
            $table->date('shift_date');
            $table->time('shift_start')->nullable();
            $table->time('shift_end')->nullable();
            $table->string('status', 32)->default('draft');
            $table->string('member_response', 32)->nullable();
            $table->text('response_reason')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->foreignId('substitute_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->foreignId('replaced_slot_id')->nullable()->constrained('team_roster_slots')->nullOnDelete();
            $table->json('conflict_flags')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['team_roster_id', 'status']);
            $table->index(['member_id', 'shift_date']);
        });

        Schema::create('team_roster_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_roster_id')->constrained('team_rosters')->cascadeOnDelete();
            $table->foreignId('team_roster_slot_id')->nullable()->constrained('team_roster_slots')->nullOnDelete();
            $table->string('event_type', 32);
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['team_roster_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_roster_events');
        Schema::dropIfExists('team_roster_slots');
        Schema::dropIfExists('team_rosters');
    }
};
