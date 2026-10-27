<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Separate durable lanes serialize settings and maintenance, including first creation.
        Schema::create('system_api_mutex', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('version')->default(0);
        });
        DB::table('system_api_mutex')->insert([['id' => 1], ['id' => 2]]);
        Schema::create('system_api_commands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('actor_id');
            $table->char('token_hash', 64);
            $table->char('key_hash', 64);
            $table->char('request_hash', 64);
            $table->string('operation', 100);
            $table->string('scope', 100);
            $table->string('native_permission', 100);
            $table->string('state', 30)->index();
            $table->longText('result')->nullable();
            $table->timestamps();
            $table->unique(['actor_id', 'token_hash', 'key_hash'], 'system_api_replay_identity');
            $table->index(['actor_id', 'token_hash', 'created_at'], 'system_api_actor_history');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_api_commands');
        Schema::dropIfExists('system_api_mutex');
    }
};
