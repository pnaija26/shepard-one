<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visitor_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visitor_id')->constrained('visitors')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('organizations')->restrictOnDelete();
            $table->date('visit_date');
            $table->string('service_or_event')->nullable();
            $table->string('attendance_status', 32)->default('first_timer');
            $table->string('source', 32)->default('service');
            $table->json('decisions')->nullable();
            $table->text('salvation_response')->nullable();
            $table->text('prayer_needs')->nullable();
            $table->boolean('membership_interest')->default(false);
            $table->boolean('consent_data_processing')->default(false);
            $table->boolean('consent_follow_up')->default(false);
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['visitor_id', 'visit_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitor_visits');
    }
};
