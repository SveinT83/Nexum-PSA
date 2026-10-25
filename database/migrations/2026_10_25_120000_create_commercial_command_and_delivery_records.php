<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_command_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('actor_key', 64);
            $table->string('operation', 160);
            $table->string('key_hash', 64);
            $table->string('request_hash', 64);
            $table->longText('result');
            $table->timestamp('created_at');
            $table->unique(['actor_key', 'operation', 'key_hash'], 'commercial_command_replay_unique');
        });
        Schema::create('commercial_delivery_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('actor_id')->index();
            $table->unsignedBigInteger('contract_id')->index();
            $table->string('kind', 30);
            $table->string('state', 30)->index();
            $table->string('revision', 64);
            $table->string('preview_hash', 64);
            $table->longText('payload');
            $table->timestamp('expires_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('message_id')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('commercial_command_receipts')->exists() || DB::table('commercial_delivery_attempts')->exists()) {
            throw new RuntimeException('Retain Commercial command/delivery evidence; use an operational rollback.');
        }
        Schema::dropIfExists('commercial_delivery_attempts');
        Schema::dropIfExists('commercial_command_receipts');
    }
};
