<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_api_mutex', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
        });
        DB::table('contact_api_mutex')->insert(['id' => 1]);
        Schema::create('contact_api_commands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('actor_id');
            $table->string('token_hash', 64);
            $table->string('key_hash', 64);
            $table->string('operation', 100);
            $table->string('request_hash', 64);
            $table->string('state', 30);
            $table->longText('result')->nullable();
            $table->timestamps();
            $table->unique(['actor_id', 'token_hash', 'key_hash'], 'contact_api_command_replay');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('contact_api_commands') && DB::table('contact_api_commands')->exists()) {
            throw new RuntimeException('Contact command history must be retained; disable API entry points instead.');
        }
        Schema::dropIfExists('contact_api_commands');
        Schema::dropIfExists('contact_api_mutex');
    }
};
