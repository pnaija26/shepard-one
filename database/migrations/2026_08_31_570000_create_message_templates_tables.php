<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->string('slug', 160)->nullable();
            $table->string('scenario', 64);
            $table->string('channel', 32);
            $table->string('language', 8)->default('en');
            $table->foreignId('branch_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('status', 32)->default('draft');
            $table->unsignedInteger('current_version')->default(0);
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();

            $table->index(['scenario', 'channel', 'status'], 'msg_tpl_scenario_channel_status_idx');
            $table->index(['branch_id', 'status'], 'msg_tpl_branch_status_idx');
        });

        Schema::create('message_template_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_template_id')->constrained('message_templates')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 32)->default('draft');
            $table->string('subject', 200)->nullable();
            $table->text('body');
            $table->json('variables_used')->nullable();
            $table->json('last_validation')->nullable();
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_to')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['message_template_id', 'version'], 'msg_tpl_versions_unique');
            $table->index(['message_template_id', 'status', 'effective_from'], 'msg_tpl_versions_effective_idx');
        });

        Schema::create('message_template_previews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_template_version_id')->constrained('message_template_versions')->cascadeOnDelete();
            $table->json('sample_data');
            $table->json('rendered');
            $table->boolean('passed')->default(false);
            $table->foreignId('ran_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ran_at');
            $table->timestamps();

            $table->index(['message_template_version_id', 'ran_at'], 'msg_tpl_preview_ran_idx');
        });

        Schema::table('communication_deliveries', function (Blueprint $table) {
            $table->foreignId('message_template_version_id')
                ->nullable()
                ->after('communication_id')
                ->constrained('message_template_versions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('communication_deliveries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('message_template_version_id');
        });
        Schema::dropIfExists('message_template_previews');
        Schema::dropIfExists('message_template_versions');
        Schema::dropIfExists('message_templates');
    }
};
