<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 12.1: rotatable hybrid device credentials (refresh tokens).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('device_id', 64);
            $table->string('device_name')->nullable();
            $table->string('platform', 32); // ios|android|web-hybrid
            $table->string('refresh_token_hash', 64);
            $table->unsignedBigInteger('access_token_id')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'device_id']);
            $table->index('refresh_token_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_credentials');
    }
};
