<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_duplicate_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matched_member_id')->constrained('members')->cascadeOnDelete();
            $table->string('confidence', 16);
            $table->string('match_reason', 64);
            $table->json('submitted_payload');
            $table->string('status', 32)->default('pending_review');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_duplicate_reviews');
    }
};
