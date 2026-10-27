<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_api_mutex', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
        });
        DB::table('marketing_api_mutex')->insert(['id' => 1]);
        Schema::create('marketing_api_commands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('actor_id');
            $table->string('token_hash', 64);
            $table->string('key_hash', 64);
            $table->string('request_hash', 64);
            $table->string('operation', 150);
            $table->string('state', 30);
            $table->longText('result');
            $table->timestamps();
            $table->unique(['actor_id', 'token_hash', 'key_hash'], 'marketing_api_command_replay');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_api_commands');
        Schema::dropIfExists('marketing_api_mutex');
    }
};
