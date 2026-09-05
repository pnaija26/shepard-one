<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('welfare_requests', function (Blueprint $table) {
            $table->id();
            $table->string('case_number', 32)->unique();
            $table->foreignId('branch_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('beneficiary_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->string('beneficiary_name', 160)->nullable();
            $table->foreignId('requester_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->foreignId('requester_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('request_type', 32);
            $table->text('description')->nullable();
            $table->string('priority', 32)->default('normal');
            $table->decimal('requested_value', 12, 2)->nullable();
            $table->string('currency', 8)->default('NGN');
            $table->boolean('consent_data_processing')->default(false);
            $table->boolean('consent_welfare_review')->default(false);
            $table->string('status', 32)->default('draft');
            $table->json('supporting_documents')->nullable();
            $table->json('validation_errors')->nullable();
            $table->boolean('is_restricted')->default(true);
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
            $table->index(['beneficiary_member_id', 'status']);
            $table->index(['requester_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('welfare_requests');
    }
};
