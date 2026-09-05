<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('composable_dashboards', function (Blueprint $table) {
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

        Schema::create('composable_dashboard_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('composable_dashboard_id')->constrained('composable_dashboards')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 32)->default('draft');
            $table->json('widgets');
            $table->json('role_ids');
            $table->json('scope')->nullable();
            $table->json('last_validation')->nullable();
            $table->json('warnings')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_to')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['composable_dashboard_id', 'version'], 'cd_versions_dashboard_version_unique');
        });

        Schema::create('composable_dashboard_previews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('composable_dashboard_version_id');
            $table->foreign('composable_dashboard_version_id', 'cd_preview_version_fk')
                ->references('id')
                ->on('composable_dashboard_versions')
                ->cascadeOnDelete();
            $table->json('preview_payload');
            $table->timestamp('ran_at');
            $table->foreignId('ran_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('composable_dashboard_previews');
        Schema::dropIfExists('composable_dashboard_versions');
        Schema::dropIfExists('composable_dashboards');
    }
};
