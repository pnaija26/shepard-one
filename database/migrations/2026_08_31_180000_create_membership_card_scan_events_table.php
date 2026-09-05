<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_card_scan_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('jti')->unique();
            $table->foreignId('member_id')->constrained('members')->restrictOnDelete();
            $table->foreignId('scanned_by')->constrained('users')->restrictOnDelete();
            $table->string('purpose', 64);
            $table->string('outcome', 32);
            $table->timestamp('scanned_at')->useCurrent();

            $table->index(['member_id', 'scanned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_card_scan_events');
    }
};
