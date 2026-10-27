<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Local replay and durable outbound reservation have explicit unique identities. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relationship_api_mutex', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
        });
        DB::table('relationship_api_mutex')->insert(['id' => 1]);
        Schema::create('relationship_api_commands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('actor_id');
            $table->string('token_hash', 64);
            $table->string('key_hash', 64);
            $table->string('request_hash', 64);
            $table->string('operation', 100);
            $table->unsignedBigInteger('relationship_id')->nullable();
            $table->string('state', 30);
            $table->longText('result')->nullable();
            $table->json('authority');
            $table->timestamps();
            $table->unique(['actor_id', 'token_hash', 'key_hash'], 'relationship_command_replay');
        });
        Schema::create('relationship_outbound_claims', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->unsignedBigInteger('relationship_id');
            $table->string('state', 30);
            $table->string('source_hash', 64);
            $table->longText('result')->nullable();
            $table->index(['relationship_id', 'source_hash', 'state'], 'relationship_outbound_source_state');
            $table->unsignedBigInteger('sync_link_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relationship_outbound_claims');
        Schema::dropIfExists('relationship_api_commands');
        Schema::dropIfExists('relationship_api_mutex');
    }
};
