<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_api_command_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('actor_key', 64);
            $table->string('operation', 160);
            $table->string('key_hash', 64);
            $table->string('request_hash', 64);
            $table->json('result');
            $table->timestamp('created_at');
            $table->unique(['actor_key', 'operation', 'key_hash'], 'sales_command_replay_unique');
        });
        Schema::create('sales_delivery_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->unsignedBigInteger('opportunity_id')->index();
            $table->unsignedBigInteger('quote_version_id')->nullable()->index();
            $table->unsignedBigInteger('activity_id')->nullable()->index();
            $table->string('kind', 30);
            $table->string('state', 30)->index();
            $table->string('revision', 64);
            $table->string('preview_hash', 64);
            $table->longText('payload');
            $table->timestamp('expires_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('message_id', 255)->nullable();
            $table->string('error_code', 100)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Evidence is irreversible once commands or SMTP attempts exist; use an operational rollback.
        if (\Illuminate\Support\Facades\DB::table('sales_api_command_receipts')->exists()
            || \Illuminate\Support\Facades\DB::table('sales_delivery_attempts')->exists()) {
            throw new RuntimeException('Retain Sales command and delivery evidence; disable the API instead.');
        }
        Schema::dropIfExists('sales_delivery_attempts');
        Schema::dropIfExists('sales_api_command_receipts');
    }
};
