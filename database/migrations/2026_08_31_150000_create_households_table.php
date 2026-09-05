<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('households', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('organizations')->restrictOnDelete();
            $table->string('name');
            $table->foreignId('head_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->string('shared_phone', 32)->nullable();
            $table->string('shared_email')->nullable();
            $table->json('shared_address')->nullable();
            $table->json('milestones')->nullable();
            $table->json('attendance_summary')->nullable();
            $table->json('events_summary')->nullable();
            $table->json('teams_summary')->nullable();
            $table->json('welfare_references')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('households');
    }
};
