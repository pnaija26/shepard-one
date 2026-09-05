<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('giving_statements', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->date('period_from');
            $table->date('period_to');
            $table->unsignedBigInteger('total_cents')->default(0);
            $table->string('currency', 8)->default('USD');
            $table->unsignedInteger('line_count')->default(0);
            $table->json('totals_by_category')->nullable();
            $table->json('line_items'); // member-safe snapshot (no other donors)
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->index(['member_id', 'period_from', 'period_to'], 'giving_stmt_member_period_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('giving_statements');
    }
};
