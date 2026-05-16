<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create provider records for LLM backends used by AI agents.
     */
    public function up(): void
    {
        Schema::create('ai_providers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('provider_key');
            $table->text('base_url')->nullable();
            $table->string('default_model')->nullable();
            $table->string('embedding_model')->nullable();
            $table->string('status')->default('disabled');
            $table->json('config')->nullable();
            $table->json('secrets')->nullable();
            $table->text('last_error')->nullable();
            $table->boolean('is_healthy')->default(false);
            $table->timestamps();

            $table->index(['provider_key', 'status']);
        });
    }

    /**
     * Drop provider records when rolling back the AI integration schema.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_providers');
    }
};
