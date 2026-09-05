<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('church_search_entries', function (Blueprint $table) {
            $table->id();
            $table->string('record_type', 64);
            $table->unsignedBigInteger('record_id');
            $table->foreignId('branch_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('title');
            $table->string('snippet', 500);
            $table->text('keywords');
            $table->string('required_permission', 120);
            $table->string('sensitivity', 32)->default('internal');
            $table->string('status', 32)->default('active');
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamp('indexed_at')->nullable();
            $table->timestamps();

            $table->unique(['record_type', 'record_id'], 'cse_record_unique');
            $table->index(['status', 'record_type'], 'cse_status_type_idx');
            $table->index('branch_id', 'cse_branch_idx');
        });

        Schema::create('church_search_sync_failures', function (Blueprint $table) {
            $table->id();
            $table->string('record_type', 64);
            $table->unsignedBigInteger('record_id')->nullable();
            $table->string('operation', 32);
            $table->text('error_message');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('status', 32)->default('pending');
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_retry_at'], 'cssf_status_retry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('church_search_sync_failures');
        Schema::dropIfExists('church_search_entries');
    }
};
