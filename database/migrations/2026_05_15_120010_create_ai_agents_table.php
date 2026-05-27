<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create configurable AI agents that wrap provider models with tdPSA policy.
     */
    public function up(): void
    {
        Schema::create('ai_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('ai_provider_id')->nullable()->constrained('ai_providers')->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('model')->nullable();
            $table->text('instructions');
            $table->json('data_sources')->nullable();
            $table->json('allowed_tools')->nullable();
            $table->json('allowed_api_scopes')->nullable();
            $table->boolean('can_execute_actions')->default(false);
            $table->boolean('is_default')->default(false);
            $table->json('default_domains')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Drop AI agents when rolling back.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_agents');
    }
};
