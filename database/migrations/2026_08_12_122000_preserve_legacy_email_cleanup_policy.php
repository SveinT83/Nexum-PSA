<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('common_settings') || ! Schema::hasTable('email_accounts')) {
            return;
        }

        if (! Schema::hasColumn('email_accounts', 'delete_policy') || ! Schema::hasColumn('email_accounts', 'ticket_ingress_enabled')) {
            return;
        }

        $legacyCleanupEnabled = DB::table('common_settings')
            ->where('type', 'emailhub')
            ->where('name', 'delete_on_success')
            ->value('value') === '1';

        if (! $legacyCleanupEnabled) {
            return;
        }

        DB::table('email_accounts')
            ->where('delete_policy', 'local_only')
            ->where('ticket_ingress_enabled', true)
            ->update(['delete_policy' => 'legacy_default']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('email_accounts') || ! Schema::hasColumn('email_accounts', 'delete_policy')) {
            return;
        }

        DB::table('email_accounts')
            ->where('delete_policy', 'legacy_default')
            ->update(['delete_policy' => 'local_only']);
    }
};
