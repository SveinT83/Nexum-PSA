<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Additive evidence only: no import, delivery or scheduler activation during migration. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_exchange_write_mutex', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
        });
        DB::table('data_exchange_write_mutex')->insert(['id' => 1]);
        Schema::create('data_exchange_command_receipts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('actor_id');
            $table->unsignedBigInteger('profile_id')->nullable()->index();
            $table->string('operation', 180);
            $table->string('key_hash', 64);
            $table->string('request_hash', 64);
            $table->longText('result');
            $table->timestamps();
            $table->unique(['actor_id', 'operation', 'key_hash'], 'dx_command_replay_unique');
        });
        Schema::create('data_exchange_schedule_occurrences', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('schedule_id')->index();
            $table->string('occurrence_key', 64)->unique();
            $table->dateTime('scheduled_at');
            $table->string('status', 30)->default('queued');
            $table->unsignedBigInteger('run_id')->nullable();
            $table->json('snapshot')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Roll back entry points before dropping durable evidence; take a separate evidence backup.
        Schema::dropIfExists('data_exchange_schedule_occurrences');
        Schema::dropIfExists('data_exchange_command_receipts');
        Schema::dropIfExists('data_exchange_write_mutex');
    }
};
