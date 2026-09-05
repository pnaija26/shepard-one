<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_duplicate_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_a_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('member_b_id')->constrained('members')->cascadeOnDelete();
            $table->string('confidence', 16);
            $table->string('match_reason', 64);
            $table->json('match_signals')->nullable();
            $table->string('source', 32)->default('scan');
            $table->string('status', 32)->default('pending_review');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['member_a_id', 'member_b_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_duplicate_flags');
    }
};
