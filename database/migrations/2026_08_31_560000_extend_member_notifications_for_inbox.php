<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_notifications', function (Blueprint $table) {
            $table->string('category', 32)->nullable()->after('type');
            $table->string('deep_link', 191)->nullable()->after('metadata');
            $table->timestamp('archived_at')->nullable()->after('read_at');

            $table->index(['user_id', 'archived_at', 'created_at'], 'member_notif_inbox_idx');
            $table->index(['user_id', 'category', 'created_at'], 'member_notif_category_idx');
        });
    }

    public function down(): void
    {
        Schema::table('member_notifications', function (Blueprint $table) {
            $table->dropIndex('member_notif_inbox_idx');
            $table->dropIndex('member_notif_category_idx');
            $table->dropColumn(['category', 'deep_link', 'archived_at']);
        });
    }
};
