<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operations_snapshots', function (Blueprint $table) {
            $table->id();
            $table->uuid('correlation_id');
            $table->string('component', 64);
            $table->string('status', 32);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->decimal('error_rate', 8, 4)->nullable();
            $table->unsignedInteger('queue_depth')->nullable();
            $table->unsignedInteger('failed_jobs')->nullable();
            $table->json('metrics')->nullable();
            $table->timestamp('captured_at');
            $table->timestamps();
        });

        Schema::create('operations_alerts', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->string('dedup_key');
            $table->string('component', 64);
            $table->string('metric', 64);
            $table->string('severity', 16);
            $table->string('status', 32)->default('open');
            $table->text('message');
            $table->json('context')->nullable();
            $table->string('runbook_key', 64)->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->timestamp('triggered_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users');
            $table->unsignedInteger('time_to_acknowledge_minutes')->nullable();
            $table->unsignedInteger('time_to_resolve_minutes')->nullable();
            $table->timestamps();

            $table->index(['dedup_key', 'triggered_at']);
        });

        Schema::create('backup_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->string('run_type', 32);
            $table->string('status', 32);
            $table->boolean('encrypted')->default(true);
            $table->boolean('replicated_offsite')->default(false);
            $table->string('integrity_check', 32)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('recovery_exercises', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->string('exercise_type', 32);
            $table->string('status', 32)->default('planned');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('measured_rpo_minutes')->nullable();
            $table->unsignedInteger('measured_rto_minutes')->nullable();
            $table->boolean('rpo_met')->nullable();
            $table->boolean('rto_met')->nullable();
            $table->json('verification_evidence')->nullable();
            $table->json('findings')->nullable();
            $table->json('corrective_actions')->nullable();
            $table->foreignId('conducted_by')->nullable()->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recovery_exercises');
        Schema::dropIfExists('backup_runs');
        Schema::dropIfExists('operations_alerts');
        Schema::dropIfExists('operations_snapshots');
    }
};
