<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_team_id')->constrained('service_teams')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('organizations')->restrictOnDelete();
            $table->date('reporting_period_start');
            $table->date('reporting_period_end');
            $table->unsignedInteger('template_version')->default(1);
            $table->json('template_snapshot')->nullable();
            $table->string('status', 32)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->json('field_values')->nullable();
            $table->json('attachments')->nullable();
            $table->json('incidents')->nullable();
            $table->text('concerns')->nullable();
            $table->json('results')->nullable();
            $table->json('recommendations')->nullable();
            $table->boolean('is_locked')->default(false);
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_decision', 32)->nullable();
            $table->text('review_comments')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['service_team_id', 'status']);
            $table->index(['reporting_period_start', 'reporting_period_end']);
        });

        Schema::create('team_report_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_report_id')->constrained('team_reports')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('change_type', 32);
            $table->json('snapshot')->nullable();
            $table->text('comments')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['team_report_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_report_versions');
        Schema::dropIfExists('team_reports');
    }
};
