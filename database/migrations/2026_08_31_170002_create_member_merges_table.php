<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_merges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survivor_id')->constrained('members')->restrictOnDelete();
            $table->foreignId('merged_member_id')->constrained('members')->restrictOnDelete();
            $table->string('retired_membership_id', 32);
            $table->json('field_resolutions');
            $table->foreignId('merged_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique('merged_member_id');
            $table->unique('retired_membership_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_merges');
    }
};
