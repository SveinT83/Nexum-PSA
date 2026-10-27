<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_intelligence_api_mutex', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
        });
        DB::table('lead_intelligence_api_mutex')->insert(['id' => 1]);
        Schema::create('lead_intelligence_api_commands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('actor_id');
            $table->string('token_hash', 64);
            $table->string('key_hash', 64);
            $table->string('request_hash', 64);
            $table->string('operation', 100);
            $table->string('ability', 100);
            $table->string('state', 30);
            $table->longText('result')->nullable();
            $table->timestamps();
            $table->unique(['actor_id', 'token_hash', 'key_hash'], 'lead_api_replay');
        });
        Schema::table('lead_research_runs', function (Blueprint $table) {
            $table->uuid('api_command_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('lead_research_runs', function (Blueprint $table) {
            $table->dropColumn('api_command_id');
        });
        Schema::dropIfExists('lead_intelligence_api_commands');
        Schema::dropIfExists('lead_intelligence_api_mutex');
    }
};
