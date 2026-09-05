<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('welfare_assistance_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('welfare_request_id')->constrained('welfare_requests')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('delivery_type', 32);
            $table->string('method', 32);
            $table->decimal('amount', 12, 2);
            $table->string('currency', 8)->default('NGN');
            $table->date('delivered_on');
            $table->string('reference', 120);
            $table->text('notes')->nullable();
            $table->json('evidence')->nullable();
            $table->decimal('approved_value_snapshot', 12, 2);
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['welfare_request_id', 'delivered_on'], 'wad_request_delivered_idx');
            $table->unique(['welfare_request_id', 'reference'], 'wad_request_reference_unique');
        });

        Schema::create('welfare_assistance_confirmations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('welfare_assistance_delivery_id');
            $table->foreignId('welfare_request_id')->constrained('welfare_requests')->cascadeOnDelete();
            $table->string('status', 32);
            $table->timestamp('confirmed_at')->nullable();
            $table->text('waiver_reason')->nullable();
            $table->json('evidence')->nullable();
            $table->foreignId('confirmed_by_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('welfare_assistance_delivery_id', 'wac_delivery_fk')
                ->references('id')->on('welfare_assistance_deliveries')->cascadeOnDelete();
            $table->unique(['welfare_assistance_delivery_id'], 'wac_delivery_unique');
        });

        Schema::table('welfare_requests', function (Blueprint $table) {
            $table->decimal('approved_value', 12, 2)->nullable()->after('approval_status');
            $table->timestamp('disbursed_at')->nullable()->after('approved_value');
            $table->timestamp('follow_up_at')->nullable()->after('disbursed_at');
        });
    }

    public function down(): void
    {
        Schema::table('welfare_requests', function (Blueprint $table) {
            $table->dropColumn(['approved_value', 'disbursed_at', 'follow_up_at']);
        });

        Schema::dropIfExists('welfare_assistance_confirmations');
        Schema::dropIfExists('welfare_assistance_deliveries');
    }
};
