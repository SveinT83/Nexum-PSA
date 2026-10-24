<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Durable replay identities are separate from the activity journal's configurable retention. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_api_command_receipts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_id');
            $table->string('operation', 80);
            $table->char('key_hash', 64);
            $table->char('request_hash', 64);
            $table->string('target_type', 200);
            $table->unsignedBigInteger('target_id');
            $table->timestamp('created_at');
            $table->unique(['actor_id', 'operation', 'key_hash'], 'calendar_api_receipt_identity');
        });
    }

    public function down(): void
    {
        // Operational rollback must retain this table after commands have been accepted.
        Schema::dropIfExists('calendar_api_command_receipts');
    }
};
