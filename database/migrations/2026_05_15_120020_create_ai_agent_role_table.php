<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Connect agents to Spatie roles that may use them in the chat UI.
     */
    public function up(): void
    {
        Schema::create('ai_agent_role', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_agent_id')->constrained('ai_agents')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['ai_agent_id', 'role_id']);
        });
    }

    /**
     * Drop the agent role access table when rolling back.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_agent_role');
    }
};
