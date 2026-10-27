<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Browser and API acquire the same permanent singleton before reading the revision.
        Schema::create('warroom_api_mutex', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedBigInteger('revision')->default(0);
        });
        DB::table('warroom_api_mutex')->insert(['id' => 1]);
        Schema::create('warroom_api_commands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('actor_id');
            $table->string('token_hash', 64);
            $table->string('key_hash', 64);
            $table->string('request_hash', 64);
            $table->longText('result');
            $table->timestamps();
            $table->unique(['actor_id', 'token_hash', 'key_hash'], 'warroom_api_command_replay');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warroom_api_commands');
        Schema::dropIfExists('warroom_api_mutex');
    }
};
