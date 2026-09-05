<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('care_cases', function (Blueprint $table) {
            $table->id();
            $table->string('case_number', 40)->unique();
            $table->foreignId('branch_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('beneficiary_member_id')->constrained('members')->restrictOnDelete();
            $table->string('category', 64);
            $table->text('description'); // encrypted at application layer
            $table->text('sensitive_notes')->nullable(); // encrypted at application layer
            $table->string('priority', 32);
            $table->string('status', 32)->default('open');
            $table->string('consent_basis', 64);
            $table->string('confidentiality', 64);
            $table->string('data_classification', 64);
            $table->boolean('is_restricted')->default(true);
            $table->json('evidence')->nullable();
            $table->string('assigned_care_role', 64)->nullable();
            $table->foreignId('assigned_officer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
            $table->index(['assigned_officer_id', 'status']);
            $table->index(['category', 'priority']);
            $table->index(['beneficiary_member_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('care_cases');
    }
};
