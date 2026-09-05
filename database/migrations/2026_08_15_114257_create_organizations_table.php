<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {

        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type'); // headquarters, branch, campus, location, ministry, department, team, group
            $table->string('identifier')->unique(); // unique identifier for the organization unit
            $table->foreignId('parent_id')->nullable()->constrained('organizations')->onDelete('set null');
            $table->foreignId('branch_id')->nullable()->constrained('organizations')->onDelete('set null');
            $table->text('description')->nullable();
            $table->json('attributes')->nullable(); // additional attributes as needed
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
