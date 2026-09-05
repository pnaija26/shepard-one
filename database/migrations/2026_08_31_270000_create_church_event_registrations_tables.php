<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('church_event_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('church_event_id')->constrained('church_events')->cascadeOnDelete();
            $table->string('person_type')->nullable();
            $table->unsignedBigInteger('person_id')->nullable();
            $table->string('registrant_name');
            $table->string('registrant_email')->nullable();
            $table->string('registrant_phone', 32)->nullable();
            $table->string('channel', 32)->default('web');
            $table->string('status', 32)->default('confirmed');
            $table->string('confirmation_code', 32)->unique();
            $table->string('credential_jti', 64)->unique();
            $table->string('payment_status', 32)->default('not_required');
            $table->boolean('consent_data_processing')->default(false);
            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('admitted_at')->nullable();
            $table->timestamps();

            $table->unique(['church_event_id', 'person_type', 'person_id'], 'event_registrations_person_unique');
            $table->index(['church_event_id', 'status']);
        });

        Schema::create('church_event_scan_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('church_event_id')->constrained('church_events')->cascadeOnDelete();
            $table->foreignId('registration_id')->nullable()->constrained('church_event_registrations')->nullOnDelete();
            $table->string('credential_jti', 64);
            $table->string('outcome', 32);
            $table->string('reason', 64)->nullable();
            $table->foreignId('scanned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['credential_jti', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('church_event_scan_events');
        Schema::dropIfExists('church_event_registrations');
    }
};
