<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_logs', function (Blueprint $table): void {
            $table->string('idempotency_key', 160)
                ->nullable()
                ->after('rfc_message_id')
                ->unique('email_logs_idempotency_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('email_logs', function (Blueprint $table): void {
            $table->dropUnique('email_logs_idempotency_key_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};
