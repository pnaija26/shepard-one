<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('church_documents', function (Blueprint $table) {
            $table->string('lifecycle_status', 32)->default('active')->after('status');
            $table->boolean('legal_hold')->default(false)->after('lifecycle_status');
            $table->timestamp('archived_at')->nullable()->after('legal_hold');
            $table->timestamp('archive_requested_at')->nullable()->after('archived_at');
        });

        Schema::create('church_document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('church_document_id')->constrained('church_documents')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('original_filename');
            $table->string('stored_filename');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->string('content_hash', 128);
            $table->string('storage_disk', 32)->default('local');
            $table->string('storage_path');
            $table->text('replacement_reason')->nullable();
            $table->foreignId('uploaded_by')->constrained('users');
            $table->boolean('is_current')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['church_document_id', 'version_number'], 'cdv_document_version_unique');
            $table->index(['church_document_id', 'is_current'], 'cdv_document_current_idx');
        });

        Schema::create('church_document_access_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('church_document_id')->constrained('church_documents')->cascadeOnDelete();
            $table->foreignId('church_document_version_id')->constrained('church_document_versions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->string('mode', 32);
            $table->string('token_hash');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['church_document_id', 'user_id', 'expires_at'], 'cdag_document_user_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('church_document_access_grants');
        Schema::dropIfExists('church_document_versions');

        Schema::table('church_documents', function (Blueprint $table) {
            $table->dropColumn(['lifecycle_status', 'legal_hold', 'archived_at', 'archive_requested_at']);
        });
    }
};
