<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intake_api_commands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('actor_key', 100);
            $table->string('token_hash', 64);
            $table->string('key_hash', 64);
            $table->string('operation', 160);
            $table->string('request_hash', 64);
            $table->string('state', 30);
            $table->longText('result')->nullable();
            $table->timestamps();
            $table->unique(['actor_key', 'token_hash', 'key_hash'], 'intake_api_command_replay');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intake_api_commands');
    }
};
