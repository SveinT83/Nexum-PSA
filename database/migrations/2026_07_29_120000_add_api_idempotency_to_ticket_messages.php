<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_messages', function (Blueprint $table): void {
            $table->string('idempotency_key', 100)->nullable()->after('metadata');
            $table->char('idempotency_fingerprint', 64)->nullable()->after('idempotency_key');
            $table->unique(
                ['ticket_id', 'idempotency_key'],
                'ticket_messages_ticket_id_idempotency_key_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('ticket_messages', function (Blueprint $table): void {
            $table->dropUnique('ticket_messages_ticket_id_idempotency_key_unique');
            $table->dropColumn(['idempotency_key', 'idempotency_fingerprint']);
        });
    }
};
