<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // The old aggregate counter cannot prove whether a failed worker
        // stopped before or after IMAP accepted its write. Conservatively
        // route every attempted legacy failure through provider
        // reconciliation before the first recovery retry.
        DB::table('email_remote_operations')
            ->where('status', 'failed')
            ->where('attempts', '>', 0)
            ->whereNull('failure_classification')
            ->update([
                'failure_classification' => 'ambiguous',
                'status_reason_code' => 'REMOTE_OPERATION_LEGACY_UNCERTAIN',
                'status_reason_message' => 'Legacy failure requires provider reconciliation before retry.',
                'reconciliation_required_at' => now(),
            ]);

        DB::table('email_remote_operations')
            ->where('status', 'running')
            ->where('attempts', '>', 0)
            ->whereNull('failure_classification')
            ->update([
                'failure_classification' => 'ambiguous',
                'status_reason_code' => 'REMOTE_OPERATION_LEGACY_RUNNING',
                'status_reason_message' => 'Legacy running work requires stale-worker recovery and provider reconciliation.',
                'reconciliation_required_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Recovery classifications may have changed after deployment. They
        // are operational audit state and must not be erased on rollback.
    }
};
