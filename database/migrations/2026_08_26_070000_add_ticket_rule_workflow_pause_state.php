<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tickets')) {
            throw new RuntimeException('The Tickets table must exist before Ticket Rule Workflow pause state is deployed.');
        }

        if (! Schema::hasColumn('tickets', 'rule_workflow_paused_at')) {
            Schema::table('tickets', function (Blueprint $table): void {
                $table->timestamp('rule_workflow_paused_at')
                    ->nullable()
                    ->after('workflow_state_key');
            });
        }

        if (! Schema::hasColumn('tickets', 'rule_workflow_paused_by')) {
            Schema::table('tickets', function (Blueprint $table): void {
                $table->unsignedBigInteger('rule_workflow_paused_by')
                    ->nullable()
                    ->after('rule_workflow_paused_at');
            });
        }

        if (! Schema::hasColumn('tickets', 'rule_workflow_pause_reason')) {
            Schema::table('tickets', function (Blueprint $table): void {
                $table->string('rule_workflow_pause_reason', 1000)
                    ->nullable()
                    ->after('rule_workflow_paused_by');
            });
        }

        if (! Schema::hasIndex('tickets', 'tickets_rule_workflow_paused_by_index')) {
            Schema::table('tickets', function (Blueprint $table): void {
                $table->index('rule_workflow_paused_by', 'tickets_rule_workflow_paused_by_index');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('tickets')) {
            return;
        }

        $columns = collect([
            'rule_workflow_pause_reason',
            'rule_workflow_paused_by',
            'rule_workflow_paused_at',
        ])->filter(
            fn (string $column): bool => Schema::hasColumn('tickets', $column),
        )->values()->all();

        if ($columns !== []) {
            $evidenceQuery = DB::table('tickets');

            foreach ($columns as $position => $column) {
                if ($position === 0) {
                    $evidenceQuery->whereNotNull($column);

                    continue;
                }

                $evidenceQuery->orWhereNotNull($column);
            }

            if ($evidenceQuery->exists()) {
                throw new RuntimeException('Ticket Rule Workflow pause evidence exists; deploy a reviewed forward migration.');
            }
        }

        if (Schema::hasIndex('tickets', 'tickets_rule_workflow_paused_by_index')) {
            Schema::table('tickets', function (Blueprint $table): void {
                $table->dropIndex('tickets_rule_workflow_paused_by_index');
            });
        }

        if ($columns !== []) {
            Schema::table('tickets', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }
};
