<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->string('name');
            $table->string('endpoint_url', 2048);
            $table->text('signing_secret_encrypted');
            $table->string('signing_secret_hint', 16)->nullable();
            $table->json('allowed_event_types');
            $table->foreignId('branch_id')->nullable()->constrained('organizations');
            $table->string('status', 32)->default('draft');
            $table->boolean('sensitive_payload_approved')->default(false);
            $table->string('verification_token', 64)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->timestamp('quarantined_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('revoked_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_subscription_id')->constrained('webhook_subscriptions')->cascadeOnDelete();
            $table->uuid('event_id');
            $table->string('idempotency_key')->unique();
            $table->string('event_type', 120);
            $table->string('payload_version', 16);
            $table->json('payload');
            $table->string('status', 32)->default('pending');
            $table->unsignedSmallInteger('attempt')->default(0);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('response_excerpt')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->unique(['webhook_subscription_id', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_subscriptions');
    }
};
