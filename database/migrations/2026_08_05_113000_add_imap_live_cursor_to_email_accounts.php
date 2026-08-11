<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_accounts', function (Blueprint $table) {
            $table->unsignedBigInteger('imap_uid_validity')->nullable()->after('imap_auth_type');
            $table->unsignedBigInteger('imap_live_start_uid')->nullable()->after('imap_uid_validity');
            $table->timestamp('imap_live_cursor_initialized_at')->nullable()->after('imap_live_start_uid');
        });
    }

    public function down(): void
    {
        Schema::table('email_accounts', function (Blueprint $table) {
            $table->dropColumn([
                'imap_uid_validity',
                'imap_live_start_uid',
                'imap_live_cursor_initialized_at',
            ]);
        });
    }
};
