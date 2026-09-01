<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'ticket_rules_draft_creation_token_unique';

    public function up(): void
    {
        if (! Schema::hasTable('ticket_rules')) {
            throw new \RuntimeException(
                'The ticket_rules table must exist before draft creation identity is installed.'
            );
        }

        if (! Schema::hasColumn('ticket_rules', 'draft_creation_token')) {
            Schema::table('ticket_rules', function (Blueprint $table): void {
                $table->uuid('draft_creation_token')->nullable()->after('draft_updated_at');
            });
        }

        if (! Schema::hasIndex('ticket_rules', self::INDEX)) {
            Schema::table('ticket_rules', function (Blueprint $table): void {
                $table->unique('draft_creation_token', self::INDEX);
            });
        }

        if (! Schema::hasColumn('ticket_rules', 'draft_creation_token')
            || ! Schema::hasIndex('ticket_rules', self::INDEX)) {
            throw new \RuntimeException('Draft creation identity could not be deployed completely.');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ticket_rules')) {
            return;
        }

        if (Schema::hasColumn('ticket_rules', 'draft_creation_token')
            && DB::table('ticket_rules')->whereNotNull('draft_creation_token')->exists()) {
            throw new \RuntimeException(
                'Refusing to drop draft creation identity while creation evidence exists.'
            );
        }

        if (Schema::hasIndex('ticket_rules', self::INDEX)) {
            Schema::table('ticket_rules', function (Blueprint $table): void {
                $table->dropUnique(self::INDEX);
            });
        }

        if (Schema::hasColumn('ticket_rules', 'draft_creation_token')) {
            Schema::table('ticket_rules', function (Blueprint $table): void {
                $table->dropColumn('draft_creation_token');
            });
        }
    }
};
