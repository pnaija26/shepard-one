<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->string('session_type', 64)->nullable()->after('team_id');
            $table->unsignedBigInteger('session_id')->nullable()->after('session_type');
            $table->string('capture_method', 32)->default('manual')->after('status');
            $table->timestamp('captured_at')->nullable()->after('capture_method');
            $table->string('device_id', 64)->nullable()->after('captured_at');
            $table->string('sync_status', 32)->default('synced')->after('device_id');
            $table->string('client_reference', 64)->nullable()->after('sync_status');
            $table->string('original_status', 32)->nullable()->after('corrected_at');
            $table->text('correction_reason')->nullable()->after('original_status');

            $table->unique('client_reference', 'attendance_records_client_reference_unique');
            $table->unique(
                ['subject_type', 'subject_id', 'session_type', 'session_id'],
                'attendance_records_unique_session'
            );
            $table->index(['session_type', 'session_id', 'gathering_date']);
        });

        Schema::create('attendance_record_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_record_id')->constrained('attendance_records')->cascadeOnDelete();
            $table->foreignId('corrected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('before_status', 32);
            $table->string('after_status', 32);
            $table->text('reason');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_record_corrections');

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropUnique('attendance_records_client_reference_unique');
            $table->dropUnique('attendance_records_unique_session');
            $table->dropIndex(['session_type', 'session_id', 'gathering_date']);
            $table->dropColumn([
                'session_type',
                'session_id',
                'capture_method',
                'captured_at',
                'device_id',
                'sync_status',
                'client_reference',
                'original_status',
                'correction_reason',
            ]);
        });
    }
};
