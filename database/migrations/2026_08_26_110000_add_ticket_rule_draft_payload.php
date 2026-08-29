<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, string> */
    private const INDEXES = [
        'draft_checksum' => 'ticket_rules_draft_checksum_index',
        'draft_updated_by' => 'ticket_rules_draft_updated_by_index',
        'draft_updated_at' => 'ticket_rules_draft_updated_at_index',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('ticket_rules')) {
            throw new \RuntimeException('The ticket_rules table must exist before Ticket Rule draft storage is installed.');
        }

        Schema::table('ticket_rules', function (Blueprint $table): void {
            if (! Schema::hasColumn('ticket_rules', 'draft_payload_json')) {
                $table->json('draft_payload_json')->nullable()->after('compatibility_checked_at');
            }
            if (! Schema::hasColumn('ticket_rules', 'draft_checksum')) {
                $table->char('draft_checksum', 64)->nullable()->after('draft_payload_json');
            }
            if (! Schema::hasColumn('ticket_rules', 'draft_updated_by')) {
                $table->unsignedBigInteger('draft_updated_by')->nullable()->after('draft_checksum');
            }
            if (! Schema::hasColumn('ticket_rules', 'draft_updated_at')) {
                $table->timestamp('draft_updated_at')->nullable()->after('draft_updated_by');
            }
        });

        // Reconcile every named index independently so a partially applied DDL run is repairable.
        foreach (self::INDEXES as $column => $index) {
            if (! Schema::hasIndex('ticket_rules', $index)) {
                Schema::table('ticket_rules', function (Blueprint $table) use ($column, $index): void {
                    $table->index($column, $index);
                });
            }
        }

        foreach (array_merge(['draft_payload_json'], array_keys(self::INDEXES)) as $column) {
            if (! Schema::hasColumn('ticket_rules', $column)) {
                throw new \RuntimeException('Ticket Rule draft storage could not be deployed completely.');
            }
        }

        foreach (self::INDEXES as $index) {
            if (! Schema::hasIndex('ticket_rules', $index)) {
                throw new \RuntimeException('Ticket Rule draft indexes could not be deployed completely.');
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ticket_rules')) {
            return;
        }

        $evidenceColumns = array_values(array_filter([
            'draft_payload_json',
            'draft_checksum',
            'draft_updated_by',
            'draft_updated_at',
        ], static fn (string $column): bool => Schema::hasColumn('ticket_rules', $column)));

        if ($evidenceColumns !== []
            && DB::table('ticket_rules')->where(function ($query) use ($evidenceColumns): void {
                foreach ($evidenceColumns as $index => $column) {
                    $index === 0
                        ? $query->whereNotNull($column)
                        : $query->orWhereNotNull($column);
                }
            })->exists()) {
            throw new \RuntimeException(
                'Refusing to drop Ticket Rule draft storage while draft evidence exists.'
            );
        }

        foreach (self::INDEXES as $index) {
            if (Schema::hasIndex('ticket_rules', $index)) {
                Schema::table('ticket_rules', function (Blueprint $table) use ($index): void {
                    $table->dropIndex($index);
                });
            }
        }
        Schema::table('ticket_rules', function (Blueprint $table): void {
            foreach ([
                'draft_updated_at',
                'draft_updated_by',
                'draft_checksum',
                'draft_payload_json',
            ] as $column) {
                if (Schema::hasColumn('ticket_rules', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
