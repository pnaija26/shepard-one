<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->string('membership_id', 32)->unique();
            $table->foreignId('branch_id')->constrained('organizations')->restrictOnDelete();
            $table->string('registration_channel', 32)->default('web');

            $table->string('first_name');
            $table->string('last_name');
            $table->string('preferred_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 32)->nullable();

            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postal_code', 32)->nullable();
            $table->string('country', 64)->nullable();

            $table->string('membership_status', 32)->default('active');
            $table->boolean('consent_data_processing')->default(false);
            $table->boolean('consent_directory')->default(false);

            $table->json('spiritual_gifts')->nullable();
            $table->json('skills')->nullable();
            $table->json('ministry_interests')->nullable();
            $table->json('communication_preferences')->nullable();

            $table->json('restricted_summaries')->nullable();

            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'membership_status']);
            $table->index(['last_name', 'first_name']);
            $table->index('email');
            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};
