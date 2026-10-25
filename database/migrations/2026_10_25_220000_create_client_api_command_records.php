<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Durable command identity is independent from generic audit retention. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_api_mutex', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
        });
        DB::table('client_api_mutex')->insert(['id' => 1]);
        Schema::create('client_api_commands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('actor_key', 64);
            $table->string('operation', 160);
            $table->string('key_hash', 64);
            $table->string('request_hash', 64);
            $table->string('state', 24);
            $table->string('resource_key', 64)->nullable()->index();
            $table->unsignedBigInteger('client_id')->nullable()->index();
            $table->longText('result')->nullable();
            $table->timestamps();
            $table->unique(['actor_key', 'operation', 'key_hash'], 'client_api_replay_unique');
        });
        Schema::create('client_rmm_intents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('integration_id');
            $table->string('target_type', 24);
            $table->unsignedBigInteger('target_id');
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('expected_name');
            $table->string('external_id')->nullable();
            $table->string('state', 24);
            $table->timestamps();
            $table->unique(['integration_id', 'target_type', 'target_id'], 'client_rmm_target_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('client_api_commands')->exists() || DB::table('client_rmm_intents')->exists()) {
            throw new RuntimeException('Preserve Client command evidence; disable API writes for operational rollback.');
        }
        Schema::dropIfExists('client_rmm_intents');
        Schema::dropIfExists('client_api_commands');
        Schema::dropIfExists('client_api_mutex');
    }
};
