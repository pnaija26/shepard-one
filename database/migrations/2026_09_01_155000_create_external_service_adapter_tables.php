<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_service_adapters', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->string('name');
            $table->string('adapter_type', 64);
            $table->string('provider', 64);
            $table->string('environment', 32)->default('sandbox');
            $table->foreignId('branch_id')->nullable()->constrained('organizations');
            $table->text('credentials_encrypted');
            $table->json('credential_hints')->nullable();
            $table->json('mappings')->nullable();
            $table->json('quotas')->nullable();
            $table->json('callback_urls')->nullable();
            $table->json('feature_flags')->nullable();
            $table->string('status', 32)->default('draft');
            $table->string('drain_policy', 32)->default('drain');
            $table->foreignId('replaced_by_id')->nullable()->constrained('external_service_adapters');
            $table->timestamp('effective_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_result', 32)->nullable();
            $table->json('last_test_details')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create('external_adapter_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('external_service_adapter_id')->constrained('external_service_adapters')->cascadeOnDelete();
            $table->uuid('correlation_id');
            $table->string('idempotency_key')->unique();
            $table->string('capability', 64);
            $table->json('request_payload');
            $table->json('response_payload')->nullable();
            $table->string('status', 32)->default('pending');
            $table->unsignedSmallInteger('attempt')->default(0);
            $table->unsignedInteger('timeout_ms')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_adapter_operations');
        Schema::dropIfExists('external_service_adapters');
    }
};
