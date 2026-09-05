<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('volunteer_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->unique()->constrained('members')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('organizations')->restrictOnDelete();
            $table->json('skills')->nullable();
            $table->json('expertise')->nullable();
            $table->json('availability')->nullable();
            $table->json('preferences')->nullable();
            $table->json('experience')->nullable();
            $table->json('certifications')->nullable();
            $table->json('training')->nullable();
            $table->json('service_history')->nullable();
            $table->decimal('volunteer_hours', 8, 2)->default(0);
            $table->text('restricted_notes')->nullable();
            $table->string('status', 32)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
        });

        Schema::create('volunteer_profile_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('volunteer_profile_id')->constrained('volunteer_profiles')->cascadeOnDelete();
            $table->string('field', 64);
            $table->string('change_source', 32);
            $table->json('previous_value')->nullable();
            $table->json('new_value')->nullable();
            $table->string('verification_status', 32)->default('applied');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['volunteer_profile_id', 'verification_status'], 'vp_changes_profile_verification_idx');
            $table->index(['field', 'verification_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('volunteer_profile_changes');
        Schema::dropIfExists('volunteer_profiles');
    }
};
