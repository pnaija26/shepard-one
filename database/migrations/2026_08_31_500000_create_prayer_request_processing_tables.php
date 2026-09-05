<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prayer_request_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prayer_request_id')->constrained('prayer_requests')->cascadeOnDelete();
            $table->string('activity_type', 32);
            $table->string('status_after', 32)->nullable();
            $table->text('notes')->nullable();
            $table->text('restricted_notes')->nullable(); // encrypted
            $table->foreignId('from_officer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_officer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['prayer_request_id', 'recorded_at'], 'pr_activities_request_recorded_idx');
        });

        Schema::table('prayer_requests', function (Blueprint $table) {
            $table->foreignId('assigned_officer_id')->nullable()->after('submitted_by_user_id')->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable()->after('submitted_at');
            $table->timestamp('acknowledged_at')->nullable()->after('assigned_at');
            $table->timestamp('answered_at')->nullable()->after('acknowledged_at');
            $table->timestamp('closed_at')->nullable()->after('answered_at');
            $table->timestamp('escalated_at')->nullable()->after('closed_at');
            $table->text('process_notes')->nullable()->after('escalated_at'); // encrypted summary
            $table->boolean('published_to_group')->default(false)->after('consent_sharing');
            $table->timestamp('published_to_group_at')->nullable()->after('published_to_group');
        });
    }

    public function down(): void
    {
        Schema::table('prayer_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_officer_id');
            $table->dropColumn([
                'assigned_at',
                'acknowledged_at',
                'answered_at',
                'closed_at',
                'escalated_at',
                'process_notes',
                'published_to_group',
                'published_to_group_at',
            ]);
        });

        Schema::dropIfExists('prayer_request_activities');
    }
};
