<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_reports', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('status', 32)->default('draft');
            $table->unsignedInteger('current_version')->default(0);
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create('custom_report_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_report_id')->constrained('custom_reports')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 32)->default('draft');
            $table->json('definition');
            $table->json('last_validation')->nullable();
            $table->json('warnings')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_to')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['custom_report_id', 'version'], 'cr_versions_report_version_unique');
        });

        Schema::create('custom_report_previews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_report_version_id', 'cr_preview_version_fk')
                ->constrained('custom_report_versions')
                ->cascadeOnDelete();
            $table->json('preview_payload');
            $table->timestamp('ran_at');
            $table->foreignId('ran_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_report_previews');
        Schema::dropIfExists('custom_report_versions');
        Schema::dropIfExists('custom_reports');
    }
};
