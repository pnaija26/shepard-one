<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->string('lifecycle_stage', 32)->default('member')->after('membership_status');
            $table->string('lifecycle_status', 32)->default('active')->after('lifecycle_stage');
            $table->json('lifecycle_policy')->nullable()->after('lifecycle_status');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['lifecycle_stage', 'lifecycle_status', 'lifecycle_policy']);
        });
    }
};
