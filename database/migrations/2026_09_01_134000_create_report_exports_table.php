<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_exports', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('requested_by')->constrained('users');
            $table->string('report_type', 32);
            $table->string('report_key')->nullable();
            $table->foreignId('custom_report_id')->nullable()->constrained('custom_reports')->nullOnDelete();
            $table->string('format', 32);
            $table->string('status', 32)->default('pending');
            $table->json('filters')->nullable();
            $table->json('metadata')->nullable();
            $table->string('classification', 64)->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->string('storage_path')->nullable();
            $table->string('download_token_hash')->nullable();
            $table->timestamp('download_expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
    }
};
