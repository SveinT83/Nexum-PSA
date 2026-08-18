<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_remote_operations', function (Blueprint $table): void {
            $table->unsignedBigInteger('inverse_of_email_remote_operation_id')
                ->nullable()
                ->after('idempotency_key');
            $table->json('result_snapshot_json')
                ->nullable()
                ->after('provider_response_json');
            // These application-owned audit instants use DATETIME so MariaDB
            // strict mode does not require implicit TIMESTAMP defaults.
            $table->dateTime('result_snapshot_captured_at')
                ->nullable()
                ->after('result_snapshot_json');
            $table->dateTime('undo_verified_at')
                ->nullable()
                ->after('result_snapshot_captured_at');

            $table->foreign(
                'inverse_of_email_remote_operation_id',
                'email_remote_ops_inverse_of_fk',
            )->references('id')->on('email_remote_operations')->nullOnDelete();
            $table->unique(
                'inverse_of_email_remote_operation_id',
                'email_remote_ops_inverse_unique',
            );
            $table->index(
                ['status', 'acknowledged_at', 'operation_type'],
                'email_remote_ops_recent_undo_index',
            );
        });

        // Existing successes deliberately remain without a result snapshot.
        // Their historical response cannot prove the exact post-operation
        // placement state required for a safe inverse, so they fail closed.
    }

    public function down(): void
    {
        Schema::table('email_remote_operations', function (Blueprint $table): void {
            $table->dropForeign('email_remote_ops_inverse_of_fk');
            $table->dropUnique('email_remote_ops_inverse_unique');
            $table->dropIndex('email_remote_ops_recent_undo_index');
            $table->dropColumn([
                'inverse_of_email_remote_operation_id',
                'result_snapshot_json',
                'result_snapshot_captured_at',
                'undo_verified_at',
            ]);
        });
    }
};
