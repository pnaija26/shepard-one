<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('church_contents', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->string('content_type', 32);
            $table->string('title', 200);
            $table->foreignId('branch_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('status', 32)->default('draft');
            $table->unsignedInteger('current_version')->default(0);
            $table->unsignedInteger('approved_version')->nullable();
            $table->string('visibility', 32)->default('members');
            $table->string('audience_type', 32)->default('all');
            $table->json('audience_params')->nullable();
            $table->string('device_target', 32)->default('all');
            $table->timestamp('publish_from')->nullable();
            $table->timestamp('publish_to')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'publish_from', 'publish_to'], 'cc_status_window_idx');
            $table->index(['branch_id', 'status'], 'cc_branch_status_idx');
            $table->index(['content_type', 'status'], 'cc_type_status_idx');
        });

        Schema::create('church_content_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('church_content_id');
            $table->unsignedInteger('version');
            $table->string('status', 32)->default('draft'); // draft|approved|superseded
            $table->string('title', 200);
            $table->text('body')->nullable();
            $table->json('media')->nullable(); // [{name,mime,size_bytes,url,alt,label}]
            $table->json('links')->nullable(); // [{label,href}]
            $table->json('last_validation')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('church_content_id', 'cc_ver_content_fk')
                ->references('id')->on('church_contents')->cascadeOnDelete();
            $table->unique(['church_content_id', 'version'], 'cc_ver_uq');
        });

        Schema::create('church_content_previews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('church_content_version_id');
            $table->string('device', 32)->default('web');
            $table->json('result');
            $table->boolean('passed')->default(false);
            $table->foreignId('ran_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ran_at');
            $table->timestamps();

            $table->foreign('church_content_version_id', 'cc_preview_ver_fk')
                ->references('id')->on('church_content_versions')->cascadeOnDelete();
            $table->index(['church_content_version_id', 'ran_at'], 'cc_preview_ran_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('church_content_previews');
        Schema::dropIfExists('church_content_versions');
        Schema::dropIfExists('church_contents');
    }
};
