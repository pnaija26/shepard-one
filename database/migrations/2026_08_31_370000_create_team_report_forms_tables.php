<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_report_forms', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->foreignId('branch_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('status', 32)->default('draft');
            $table->unsignedInteger('current_version')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
        });

        Schema::create('team_report_form_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_report_form_id')->constrained('team_report_forms')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('fields');
            $table->string('status', 32)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['team_report_form_id', 'version']);
        });

        Schema::create('team_report_form_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_report_form_id')->constrained('team_report_forms')->cascadeOnDelete();
            $table->foreignId('service_team_id')->constrained('service_teams')->cascadeOnDelete();
            $table->unsignedInteger('form_version');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->useCurrent();

            $table->unique('service_team_id');
            $table->index(['team_report_form_id', 'form_version'], 'trf_assignments_form_version_idx');
        });

        Schema::create('team_report_form_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_report_form_id')->constrained('team_report_forms')->cascadeOnDelete();
            $table->unsignedInteger('version')->nullable();
            $table->string('change_type', 32);
            $table->json('metadata')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::table('team_reports', function (Blueprint $table) {
            $table->foreignId('team_report_form_id')->nullable()->after('branch_id')->constrained('team_report_forms')->nullOnDelete();
            $table->unsignedInteger('team_report_form_version')->nullable()->after('team_report_form_id');
        });
    }

    public function down(): void
    {
        Schema::table('team_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('team_report_form_id');
            $table->dropColumn('team_report_form_version');
        });

        Schema::dropIfExists('team_report_form_changes');
        Schema::dropIfExists('team_report_form_assignments');
        Schema::dropIfExists('team_report_form_versions');
        Schema::dropIfExists('team_report_forms');
    }
};
