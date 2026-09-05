<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('branch_id')->constrained('organizations')->restrictOnDelete();
            $table->string('service_type', 64)->default('sunday_service');
            $table->date('gathering_date');
            $table->string('status', 32);
            $table->unsignedBigInteger('team_id')->nullable();
            $table->boolean('service_cancelled')->default(false);
            $table->boolean('branch_transfer')->default(false);
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('corrected_at')->nullable();
            $table->timestamps();

            $table->unique(['subject_type', 'subject_id', 'branch_id', 'service_type', 'gathering_date', 'team_id'], 'attendance_records_unique_gathering');
            $table->index(['subject_type', 'subject_id', 'gathering_date']);
            $table->index(['branch_id', 'service_type', 'gathering_date']);
        });

        Schema::create('attendance_exception_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('rule_type', 64);
            $table->foreignId('branch_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('service_type', 64)->nullable();
            $table->string('status', 32)->default('draft');
            $table->unsignedInteger('current_version')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['rule_type', 'status']);
        });

        Schema::create('attendance_exception_rule_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_id')->constrained('attendance_exception_rules')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('parameters');
            $table->json('exclusions')->nullable();
            $table->string('correction_policy', 32)->default('resolve');
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->useCurrent();

            $table->unique(['rule_id', 'version']);
        });

        Schema::create('attendance_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_id')->constrained('attendance_exception_rules')->restrictOnDelete();
            $table->foreignId('rule_version_id')->constrained('attendance_exception_rule_versions')->restrictOnDelete();
            $table->unsignedInteger('rule_version');
            $table->string('rule_type', 64);
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('branch_id')->constrained('organizations')->restrictOnDelete();
            $table->string('service_type', 64)->nullable();
            $table->string('period_key', 64);
            $table->string('status', 32)->default('open');
            $table->text('summary');
            $table->json('evidence')->nullable();
            $table->string('resolution_reason')->nullable();
            $table->timestamp('detected_at')->useCurrent();
            $table->timestamp('resolved_at')->nullable();

            $table->unique(['rule_id', 'subject_type', 'subject_id', 'period_key'], 'attendance_exceptions_unique_period');
            $table->index(['status', 'branch_id']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_exceptions');
        Schema::dropIfExists('attendance_exception_rule_versions');
        Schema::dropIfExists('attendance_exception_rules');
        Schema::dropIfExists('attendance_records');
    }
};
