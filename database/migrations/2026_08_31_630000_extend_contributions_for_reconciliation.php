<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('giving_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->string('name', 160);
            $table->string('code', 64);
            $table->foreignId('branch_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('status', 32)->default('active');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['code', 'branch_id'], 'giving_campaign_code_branch_uq');
            $table->index(['status'], 'giving_campaign_status_idx');
        });

        // Existing MySQL installs still have NOT NULL from the original 620000 run.
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            $foreignKeys = collect(DB::select('
                SELECT CONSTRAINT_NAME
                FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = \'contributions\'
                  AND COLUMN_NAME = \'payment_source_id\'
                  AND REFERENCED_TABLE_NAME IS NOT NULL
            '))->pluck('CONSTRAINT_NAME');

            foreach ($foreignKeys as $name) {
                DB::statement('ALTER TABLE contributions DROP FOREIGN KEY `' . $name . '`');
            }

            DB::statement('ALTER TABLE contributions MODIFY payment_source_id BIGINT UNSIGNED NULL');
            DB::statement('ALTER TABLE contributions MODIFY provider_payment_reference VARCHAR(120) NULL');
            Schema::table('contributions', function (Blueprint $table) {
                $table->foreign('payment_source_id', 'contrib_pay_src_fk')
                    ->references('id')->on('payment_sources')->nullOnDelete();
            });
        }

        Schema::table('contributions', function (Blueprint $table) {
            $table->string('source_type', 32)->default('integrated')->after('provider');
            $table->string('payment_reference', 120)->nullable()->after('provider_payment_reference');
            $table->unsignedBigInteger('campaign_id')->nullable()->after('category');
            $table->string('reconciliation_status', 32)->default('unmatched')->after('payer_external_id');
            $table->string('resolution_reason', 64)->nullable()->after('reconciliation_status');
            $table->foreignId('reconciled_by')->nullable()->after('resolution_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('reconciled_at')->nullable()->after('reconciled_by');
            $table->boolean('receipt_eligible')->default(true)->after('reconciled_at');
            $table->foreign('campaign_id', 'contrib_campaign_fk')
                ->references('id')->on('giving_campaigns')->nullOnDelete();
            $table->index(['reconciliation_status', 'status'], 'contrib_recon_status_idx');
            $table->index(['payment_reference'], 'contrib_pay_ref_idx');
        });

        Schema::create('contribution_reconciliation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contribution_id')->constrained('contributions')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64);
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->json('before_values')->nullable();
            $table->json('after_values')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['contribution_id', 'occurred_at'], 'contrib_recon_evt_idx');
        });

        Schema::create('contribution_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_number', 40)->unique();
            $table->string('verification_code', 64)->unique();
            $table->foreignId('contribution_id')->constrained('contributions')->restrictOnDelete();
            $table->string('status', 32)->default('issued');
            $table->unsignedBigInteger('amount_cents');
            $table->string('currency', 8);
            $table->string('category', 64);
            $table->string('campaign_name', 160)->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->json('financial_fields');
            $table->boolean('delivered')->default(false);
            $table->string('delivery_channel', 32)->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at');
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['contribution_id', 'status'], 'contrib_receipt_status_idx');
        });

        Schema::create('contribution_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->foreignId('contribution_id')->constrained('contributions')->restrictOnDelete();
            $table->foreignId('receipt_id')->nullable()->constrained('contribution_receipts')->nullOnDelete();
            $table->string('adjustment_type', 32);
            $table->bigInteger('amount_delta_cents')->default(0);
            $table->json('before_values')->nullable();
            $table->json('after_values')->nullable();
            $table->string('reason', 500);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['contribution_id', 'occurred_at'], 'contrib_adj_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contribution_adjustments');
        Schema::dropIfExists('contribution_receipts');
        Schema::dropIfExists('contribution_reconciliation_events');

        Schema::table('contributions', function (Blueprint $table) {
            $table->dropForeign('contrib_campaign_fk');
            $table->dropForeign(['reconciled_by']);
            $table->dropIndex('contrib_recon_status_idx');
            $table->dropIndex('contrib_pay_ref_idx');
            $table->dropColumn([
                'source_type',
                'payment_reference',
                'campaign_id',
                'reconciliation_status',
                'resolution_reason',
                'reconciled_by',
                'reconciled_at',
                'receipt_eligible',
            ]);
        });

        Schema::dropIfExists('giving_campaigns');
    }
};
