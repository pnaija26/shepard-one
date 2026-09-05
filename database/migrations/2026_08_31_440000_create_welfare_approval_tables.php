<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('welfare_approval_configs', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160)->default('Default welfare approval thresholds');
            $table->foreignId('branch_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('status', 32)->default('draft');
            $table->unsignedInteger('current_version')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('welfare_approval_config_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('welfare_approval_config_id');
            $table->unsignedInteger('version');
            $table->string('status', 32)->default('draft');
            $table->json('thresholds');
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('welfare_approval_config_id', 'welfare_approval_cfg_ver_fk')
                ->references('id')->on('welfare_approval_configs')->cascadeOnDelete();
            $table->unique(['welfare_approval_config_id', 'version'], 'welfare_approval_cfg_ver_unique');
        });

        Schema::table('welfare_requests', function (Blueprint $table) {
            $table->foreignId('approval_config_version_id')->nullable()->after('escalated_at')
                ->constrained('welfare_approval_config_versions')->nullOnDelete();
            $table->unsignedInteger('current_approval_step')->nullable()->after('approval_config_version_id');
            $table->string('approval_status', 32)->nullable()->after('current_approval_step');
        });

        Schema::create('welfare_approval_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('welfare_request_id')->constrained('welfare_requests')->cascadeOnDelete();
            $table->foreignId('approval_config_version_id')->constrained('welfare_approval_config_versions')->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('level', 32);
            $table->string('status', 32)->default('pending');
            $table->boolean('is_current')->default(false);
            $table->timestamps();

            $table->unique(['welfare_request_id', 'sequence']);
            $table->index(['welfare_request_id', 'status']);
        });

        Schema::create('welfare_approval_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('welfare_approval_step_id')->constrained('welfare_approval_steps')->cascadeOnDelete();
            $table->foreignId('welfare_request_id')->constrained('welfare_requests')->cascadeOnDelete();
            $table->string('level', 32);
            $table->string('decision', 32);
            $table->text('reason');
            $table->foreignId('decided_by')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('config_version');
            $table->timestamp('decided_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['welfare_request_id', 'level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('welfare_approval_decisions');
        Schema::dropIfExists('welfare_approval_steps');

        Schema::table('welfare_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approval_config_version_id');
            $table->dropColumn(['current_approval_step', 'approval_status']);
        });

        Schema::dropIfExists('welfare_approval_config_versions');
        Schema::dropIfExists('welfare_approval_configs');
    }
};
