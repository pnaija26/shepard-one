<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
            $table->string('occupation')->nullable()->after('country');
            $table->string('photo_path')->nullable()->after('occupation');
            $table->json('emergency_contact')->nullable()->after('photo_path');

            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['occupation', 'photo_path', 'emergency_contact']);
        });
    }
};
