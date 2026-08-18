<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY_LENGTH = 191;

    private const PROVIDER_PATH_LENGTH = 512;

    public function up(): void
    {
        $this->resizeMailbox(self::PROVIDER_PATH_LENGTH);
    }

    public function down(): void
    {
        // This migration rolls back before the reconciliation tables. Refuse
        // here, before changing the mailbox column or its unique index, so a
        // later 118000 rollback guard cannot leave a partially narrowed
        // order-seven schema behind.
        if ($this->hasProviderReconciliationEvidence()) {
            throw new RuntimeException(
                'Provider reconciliation evidence must be preserved before schema rollback.',
            );
        }

        if ($this->hasPathLongerThanLegacyLimit()) {
            throw new RuntimeException(
                'Provider mailbox paths longer than 191 characters must be preserved before schema rollback.',
            );
        }

        $this->resizeMailbox(self::LEGACY_LENGTH);
    }

    private function hasProviderReconciliationEvidence(): bool
    {
        foreach ([
            'email_provider_reconciliation_runs',
            'email_provider_reconciliation_folders',
            'email_provider_reconciliation_items',
        ] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                return true;
            }
        }

        return Schema::hasTable('email_mailbox_placements')
            && Schema::hasColumn(
                'email_mailbox_placements',
                'last_provider_reconciliation_run_id',
            )
            && DB::table('email_mailbox_placements')
                ->whereNotNull('last_provider_reconciliation_run_id')
                ->exists();
    }

    private function hasPathLongerThanLegacyLimit(): bool
    {
        foreach ([
            ['email_messages', 'mailbox'],
            ['email_folders', 'path'],
            ['email_provider_reconciliation_folders', 'folder_path'],
        ] as [$table, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            $lengthFunction = DB::getDriverName() === 'sqlite' ? 'length' : 'char_length';
            if (DB::table($table)
                ->whereRaw($lengthFunction.'('.$column.') > ?', [self::LEGACY_LENGTH])
                ->exists()) {
                return true;
            }
        }

        return false;
    }

    private function resizeMailbox(int $length): void
    {
        if (Schema::hasIndex('email_messages', 'em_msg_uid_ns_uq')) {
            Schema::table('email_messages', function (Blueprint $table): void {
                $table->dropUnique('em_msg_uid_ns_uq');
            });
        }
        Schema::table('email_messages', function (Blueprint $table) use ($length): void {
            $table->string('mailbox', $length)->default('INBOX')->change();
        });
        if (! Schema::hasIndex('email_messages', 'em_msg_uid_ns_uq')) {
            Schema::table('email_messages', function (Blueprint $table): void {
                $table->unique(
                    ['account_id', 'mailbox', 'imap_uid_validity', 'imap_uid'],
                    'em_msg_uid_ns_uq',
                );
            });
        }
    }
};
