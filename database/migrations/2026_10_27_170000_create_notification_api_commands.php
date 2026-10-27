<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Additive replay identity; no business data, device, preference or queue backfill. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_api_commands', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('actor_id')->index();
            $table->string('token_hash', 64);
            $table->string('key_hash', 64);
            $table->string('request_hash', 64);
            $table->string('operation');
            $table->string('ability');
            $table->boolean('admin')->default(false);
            $table->string('state', 24);
            $table->unsignedSmallInteger('status')->nullable();
            $table->longText('result')->nullable();
            $table->timestamps();
            $table->unique(['actor_id', 'token_hash', 'key_hash'], 'notification_command_replay_unique');
        });
    }

    public function down(): void
    {
        // Operational rollback retains receipts to prevent replay after re-installation.
        throw new RuntimeException('Notification command history must be retained. Roll back application entry points instead.');
    }
};
