<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Additive domain receipts; no account, permission, credential or auth-cutover changes. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_management_api_mutex', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
        });
        DB::table('user_management_api_mutex')->insert(['id' => 1]);
        Schema::create('user_management_api_commands', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('actor_id')->index();
            $table->string('token_hash', 64);
            $table->string('key_hash', 64);
            $table->string('request_hash', 64);
            $table->string('operation', 200);
            $table->string('state', 30);
            $table->longText('result');
            $table->timestamps();
            $table->unique(['actor_id', 'token_hash', 'key_hash'], 'um_api_command_replay');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_management_api_commands');
        Schema::dropIfExists('user_management_api_mutex');
    }
};
