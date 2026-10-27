<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nextcloud_api_mutex', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
        });
        DB::table('nextcloud_api_mutex')->insert(['id' => 1]);
        Schema::create('nextcloud_api_commands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('actor_id');
            $table->unsignedBigInteger('connection_id')->nullable()->index();
            $table->string('token_hash', 64);
            $table->string('key_hash', 64);
            $table->string('operation', 100);
            $table->string('request_hash', 64);
            $table->string('state', 30);
            $table->longText('result')->nullable();
            $table->timestamps();
            $table->unique(['actor_id', 'token_hash', 'key_hash'], 'nextcloud_api_command_replay');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nextcloud_api_commands');
        Schema::dropIfExists('nextcloud_api_mutex');
    }
};
