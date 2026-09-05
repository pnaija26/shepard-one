<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->json('directory_visibility')->nullable()->after('consent_directory');
            $table->json('directory_visibility_pending')->nullable()->after('directory_visibility');
            $table->timestamp('directory_visibility_effective_at')->nullable()->after('directory_visibility_pending');
            $table->timestamp('directory_consent_at')->nullable()->after('directory_visibility_effective_at');
        });

        Schema::create('member_directory_consent_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->boolean('consent_directory');
            $table->json('visibility_before')->nullable();
            $table->json('visibility_after')->nullable();
            $table->timestamp('effective_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['member_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_directory_consent_events');

        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn([
                'directory_visibility',
                'directory_visibility_pending',
                'directory_visibility_effective_at',
                'directory_consent_at',
            ]);
        });
    }
};
