<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_api_mutex', function (Blueprint $t) {
            $t->unsignedTinyInteger('id')->primary();
        });
        DB::table('risk_api_mutex')->insert(['id' => 1]);
        Schema::create('risk_api_commands', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('actor_key', 64);
            $t->string('operation', 180);
            $t->string('key_hash', 64);
            $t->string('request_hash', 64);
            $t->longText('context');
            $t->longText('result');
            $t->string('state', 20);
            $t->timestamps();
            $t->unique(['actor_key', 'operation', 'key_hash'], 'risk_command_identity');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('risk_api_commands') && DB::table('risk_api_commands')->exists()) {
            throw new RuntimeException('Preserve nonempty Risk command evidence; rollback refused.');
        }
        Schema::dropIfExists('risk_api_commands');
        Schema::dropIfExists('risk_api_mutex');
    }
};
