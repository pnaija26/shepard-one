<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_lifecycle_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->string('stage', 32);
            $table->string('status', 32);
            $table->string('previous_stage', 32)->nullable();
            $table->string('previous_status', 32)->nullable();
            $table->date('effective_date');
            $table->string('reason');
            $table->json('milestone')->nullable();
            $table->json('evidence')->nullable();
            $table->json('policy_applied')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['member_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_lifecycle_history');
    }
};
