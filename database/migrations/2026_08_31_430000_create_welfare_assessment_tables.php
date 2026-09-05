<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('welfare_requests', function (Blueprint $table) {
            $table->foreignId('assigned_officer_id')->nullable()->after('updated_by')->constrained('users')->nullOnDelete();
            $table->unsignedInteger('current_assessment_version')->default(0)->after('assigned_officer_id');
            $table->string('beneficiary_status_message', 255)->nullable()->after('current_assessment_version');
            $table->timestamp('returned_at')->nullable()->after('beneficiary_status_message');
            $table->timestamp('escalated_at')->nullable()->after('returned_at');

            $table->index(['assigned_officer_id', 'status']);
        });

        Schema::create('welfare_assessment_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('welfare_request_id')->constrained('welfare_requests')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->foreignId('assessor_id')->constrained('users')->cascadeOnDelete();
            $table->text('assessment_notes');
            $table->json('verified_documents')->nullable();
            $table->string('priority', 32);
            $table->string('recommendation', 32);
            $table->string('proposed_assistance_type', 32)->nullable();
            $table->decimal('proposed_value', 12, 2)->nullable();
            $table->string('currency', 8)->default('NGN');
            $table->text('follow_up_needs')->nullable();
            $table->boolean('complete')->default(false);
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->unique(['welfare_request_id', 'version']);
            $table->index(['welfare_request_id', 'complete']);
        });

        Schema::create('welfare_case_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('welfare_request_id')->constrained('welfare_requests')->cascadeOnDelete();
            $table->string('event_type', 48);
            $table->string('condition_type', 48)->nullable();
            $table->text('notes')->nullable();
            $table->string('beneficiary_message', 255)->nullable();
            $table->foreignId('from_officer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_officer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['welfare_request_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('welfare_case_events');
        Schema::dropIfExists('welfare_assessment_versions');

        Schema::table('welfare_requests', function (Blueprint $table) {
            $table->dropIndex(['assigned_officer_id', 'status']);
            $table->dropConstrainedForeignId('assigned_officer_id');
            $table->dropColumn([
                'current_assessment_version',
                'beneficiary_status_message',
                'returned_at',
                'escalated_at',
            ]);
        });
    }
};
