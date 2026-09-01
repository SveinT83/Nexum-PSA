<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const REASON_INDEX = 'tre_loop_reason_ix';

    public function up(): void
    {
        if (! Schema::hasTable('ticket_rule_events')) {
            throw new RuntimeException('Ticket Rule event evidence must exist before loop evidence is added.');
        }

        if (! Schema::hasColumn('ticket_rule_events', 'blocked_event_fingerprint')) {
            Schema::table('ticket_rule_events', function (Blueprint $table): void {
                $table->char('blocked_event_fingerprint', 64)
                    ->nullable()
                    ->after('event_fingerprint');
            });
        }

        if (! Schema::hasColumn('ticket_rule_events', 'loop_reason_code')) {
            Schema::table('ticket_rule_events', function (Blueprint $table): void {
                $table->string('loop_reason_code', 80)
                    ->nullable()
                    ->after('status');
            });
        }

        if (! Schema::hasIndex('ticket_rule_events', self::REASON_INDEX)) {
            Schema::table('ticket_rule_events', function (Blueprint $table): void {
                $table->index('loop_reason_code', self::REASON_INDEX);
            });
        }

        if (! Schema::hasColumn('ticket_rule_events', 'blocked_event_fingerprint')
            || ! Schema::hasColumn('ticket_rule_events', 'loop_reason_code')
            || ! Schema::hasIndex('ticket_rule_events', self::REASON_INDEX)) {
            throw new RuntimeException('Ticket Rule loop evidence could not be deployed completely.');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ticket_rule_events')) {
            return;
        }

        $evidenceColumns = array_values(array_filter([
            'loop_reason_code',
            'blocked_event_fingerprint',
        ], static fn (string $column): bool => Schema::hasColumn(
            'ticket_rule_events',
            $column,
        )));

        if ($evidenceColumns !== []
            && DB::table('ticket_rule_events')->where(function ($query) use ($evidenceColumns): void {
                foreach ($evidenceColumns as $position => $column) {
                    $position === 0
                        ? $query->whereNotNull($column)
                        : $query->orWhereNotNull($column);
                }
            })->exists()) {
            throw new RuntimeException(
                'Cannot remove Ticket Rule loop evidence after it has been recorded.'
            );
        }

        if (Schema::hasIndex('ticket_rule_events', self::REASON_INDEX)) {
            Schema::table('ticket_rule_events', function (Blueprint $table): void {
                $table->dropIndex(self::REASON_INDEX);
            });
        }

        if ($evidenceColumns !== []) {
            Schema::table('ticket_rule_events', function (Blueprint $table) use ($evidenceColumns): void {
                $table->dropColumn($evidenceColumns);
            });
        }
    }
};
