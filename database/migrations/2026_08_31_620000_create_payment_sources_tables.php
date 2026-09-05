<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_sources', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->string('name', 160);
            $table->string('provider', 64);
            $table->string('environment', 16)->default('sandbox'); // sandbox|live
            $table->string('currency', 8)->default('USD');
            $table->foreignId('branch_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->json('supported_categories');
            $table->json('branch_mapping')->nullable(); // external_branch_code => organization_id
            $table->text('api_key_encrypted')->nullable(); // Laravel encrypted
            $table->text('webhook_secret_encrypted')->nullable();
            $table->string('api_key_hint', 12)->nullable(); // last 4 only for UI
            $table->string('webhook_secret_hint', 12)->nullable();
            $table->boolean('enabled')->default(false);
            $table->string('status', 32)->default('draft'); // draft|tested|active|disabled
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_result', 32)->nullable(); // passed|failed
            $table->json('last_test_details')->nullable(); // sanitized
            $table->foreignId('configured_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['provider', 'environment', 'branch_id'], 'pay_src_provider_env_branch_uq');
            $table->index(['status', 'enabled'], 'pay_src_status_enabled_idx');
        });

        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_source_id')->nullable()->constrained('payment_sources')->nullOnDelete();
            $table->string('provider', 64);
            $table->string('provider_event_id', 120)->nullable();
            $table->string('payment_reference', 120)->nullable();
            $table->string('status', 32)->default('received'); // received|processed|rejected|replayed|conflict
            $table->string('reject_reason', 64)->nullable();
            $table->unsignedBigInteger('amount_cents')->nullable();
            $table->string('currency', 8)->nullable();
            $table->json('payload_sanitized')->nullable(); // no secrets
            $table->string('signature_valid', 16)->nullable(); // valid|invalid|missing
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_event_id'], 'pay_wh_event_uq');
            $table->index(['payment_reference', 'provider'], 'pay_wh_ref_idx');
            $table->index(['status', 'created_at'], 'pay_wh_status_idx');
        });

        Schema::create('contributions', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->foreignId('payment_source_id')->nullable()->constrained('payment_sources')->nullOnDelete();
            $table->string('provider', 64);
            $table->string('provider_payment_reference', 120)->nullable();
            $table->string('status', 32)->default('pending');
            $table->unsignedBigInteger('amount_cents');
            $table->string('currency', 8);
            $table->string('category', 64);
            $table->foreignId('branch_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->boolean('payer_linked')->default(false);
            $table->string('payer_external_id', 120)->nullable();
            $table->json('provider_evidence')->nullable(); // immutable original snapshot (sanitized)
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_payment_reference'], 'contrib_provider_ref_uq');
            $table->index(['branch_id', 'status', 'occurred_at'], 'contrib_branch_status_idx');
            $table->index(['member_id', 'occurred_at'], 'contrib_member_idx');
            $table->index(['category', 'occurred_at'], 'contrib_category_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contributions');
        Schema::dropIfExists('payment_webhook_events');
        Schema::dropIfExists('payment_sources');
    }
};
