<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('name');
            $table->string('category', 64);
            $table->text('description')->nullable();
            $table->json('leaders')->nullable();
            $table->json('required_skills')->nullable();
            $table->json('minimum_staffing')->nullable();
            $table->json('schedules')->nullable();
            $table->json('objectives')->nullable();
            $table->json('attendance_rules')->nullable();
            $table->json('reporting_template')->nullable();
            $table->json('approval_hierarchy')->nullable();
            $table->string('status', 32)->default('draft');
            $table->unsignedInteger('current_config_version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'name'], 'service_teams_branch_name_unique');
            $table->index(['branch_id', 'status']);
            $table->index(['department_id', 'status']);
        });

        Schema::create('service_team_config_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_team_id')->constrained('service_teams')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('config');
            $table->timestamp('effective_from')->useCurrent();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['service_team_id', 'version']);
        });

        Schema::create('service_team_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_team_id')->constrained('service_teams')->cascadeOnDelete();
            $table->string('change_type', 32);
            $table->unsignedInteger('config_version')->nullable();
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->text('summary')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['service_team_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_team_changes');
        Schema::dropIfExists('service_team_config_versions');
        Schema::dropIfExists('service_teams');
    }
};
