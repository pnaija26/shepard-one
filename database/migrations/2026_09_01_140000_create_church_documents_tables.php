<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('church_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->string('title');
            $table->string('category', 64);
            $table->string('record_type', 64);
            $table->unsignedBigInteger('record_id')->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('classification', 64);
            $table->string('access_scope', 64);
            $table->string('retention_policy', 64);
            $table->timestamp('retention_ends_at')->nullable();
            $table->string('original_filename');
            $table->string('stored_filename');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->string('content_hash', 128);
            $table->string('storage_disk', 32)->default('local');
            $table->string('storage_path');
            $table->unsignedInteger('version_number')->default(1);
            $table->string('status', 32)->default('active');
            $table->string('malware_scan_status', 32)->default('pending');
            $table->string('thumbnail_path')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestamps();

            $table->index(['record_type', 'record_id']);
            $table->index(['branch_id', 'classification']);
        });

        Schema::create('church_document_processing_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('church_document_id')->constrained('church_documents')->cascadeOnDelete();
            $table->string('job_type', 64);
            $table->string('status', 32)->default('pending');
            $table->string('classification', 64);
            $table->string('access_scope', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('failure_reason')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['church_document_id', 'job_type'], 'cdpj_document_job_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('church_document_processing_jobs');
        Schema::dropIfExists('church_documents');
    }
};
