<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 1.7: governed settings — drafts, locks, archival, and references.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->text('draft_value')->nullable()->after('value');
            $table->boolean('is_locked')->default(false)->after('is_public');
            $table->boolean('is_archived')->default(false)->after('is_locked');
            $table->unsignedBigInteger('branch_id')->nullable()->after('category');
        });

        Schema::create('setting_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('setting_id')->constrained('settings')->cascadeOnDelete();
            $table->string('reference_type', 64);
            $table->unsignedBigInteger('reference_id');
            $table->timestamps();

            $table->unique(['setting_id', 'reference_type', 'reference_id'], 'setting_refs_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('setting_references');

        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['draft_value', 'is_locked', 'is_archived', 'branch_id']);
        });
    }
};
