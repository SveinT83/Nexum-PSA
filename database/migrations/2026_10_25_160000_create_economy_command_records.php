<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('economy_write_mutex', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedBigInteger('revision')->default(0);
        });
        DB::table('economy_write_mutex')->insert(['id' => 1, 'revision' => 0]);
        Schema::create('economy_command_receipts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('actor_id')->index();
            $table->string('operation', 160);
            $table->string('key_hash', 64);
            $table->string('request_hash', 64);
            $table->longText('result');
            $table->timestamp('created_at');
            $table->unique(['actor_id', 'operation', 'key_hash'], 'economy_command_replay_unique');
        });
        Schema::create('economy_source_owners', function (Blueprint $table): void {
            $table->id();
            $table->string('source_type', 191);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('client_id')->index();
            $table->timestamp('created_at');
            $table->unique(['source_type', 'source_id'], 'economy_source_owner_unique');
        });
        Schema::create('economy_notification_intents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('order_id')->index();
            $table->string('state', 30)->default('pending')->index();
            $table->json('payload');
            $table->unsignedInteger('recipients')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('economy_command_receipts')->exists() || DB::table('economy_notification_intents')->exists() || DB::table('economy_source_owners')->exists()) {
            throw new RuntimeException('Retain Economy financial command and notification evidence; use an operational rollback.');
        }
        Schema::dropIfExists('economy_source_owners');
        Schema::dropIfExists('economy_notification_intents');
        Schema::dropIfExists('economy_command_receipts');
        Schema::dropIfExists('economy_write_mutex');
    }
};
