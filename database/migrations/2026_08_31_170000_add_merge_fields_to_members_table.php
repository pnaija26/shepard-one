<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->foreignId('merged_into_id')->nullable()->after('user_id')->constrained('members')->nullOnDelete();
            $table->timestamp('merged_at')->nullable()->after('merged_into_id');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropConstrainedForeignId('merged_into_id');
            $table->dropColumn('merged_at');
        });
    }
};
