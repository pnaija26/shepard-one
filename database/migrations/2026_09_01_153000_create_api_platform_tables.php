<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_clients', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->string('name');
            $table->string('client_id', 64)->unique();
            $table->string('secret_hash');
            $table->string('principal_type', 32)->default('machine');
            $table->json('allowed_scopes');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('organizations');
            $table->unsignedInteger('rate_limit_per_minute')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('api_access_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('correlation_id');
            $table->foreignId('api_client_id')->nullable()->constrained('api_clients')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('route_name')->nullable();
            $table->string('method', 16);
            $table->string('path');
            $table->unsignedSmallInteger('status_code');
            $table->string('outcome', 32);
            $table->string('error_code', 64)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_access_events');
        Schema::dropIfExists('api_clients');
    }
};
