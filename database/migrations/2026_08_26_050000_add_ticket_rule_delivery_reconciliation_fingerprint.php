<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ticket_rule_after_commit_results')) {
            throw new RuntimeException('Ticket Rule delivery evidence must exist before reconciliation evidence is added.');
        }

        if (! Schema::hasColumn('ticket_rule_after_commit_results', 'reconciliation_fingerprint')) {
            Schema::table('ticket_rule_after_commit_results', function (Blueprint $table): void {
                $table->char('reconciliation_fingerprint', 64)->nullable();
            });
        }

        if (! Schema::hasColumn('ticket_rule_after_commit_results', 'reconciliation_fingerprint')) {
            throw new RuntimeException('Ticket Rule delivery reconciliation evidence could not be deployed.');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ticket_rule_after_commit_results')
            || ! Schema::hasColumn('ticket_rule_after_commit_results', 'reconciliation_fingerprint')) {
            return;
        }

        if (DB::table('ticket_rule_after_commit_results')
            ->whereNotNull('reconciliation_fingerprint')
            ->exists()) {
            throw new RuntimeException('Cannot remove Ticket Rule delivery reconciliation evidence after it has been recorded.');
        }

        Schema::table('ticket_rule_after_commit_results', function (Blueprint $table): void {
            $table->dropColumn('reconciliation_fingerprint');
        });
    }
};
