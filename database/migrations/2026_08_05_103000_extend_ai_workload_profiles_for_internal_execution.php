<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_workload_profiles', function (Blueprint $table): void {
            $table->string('workload_type', 40)
                ->default('coordinator_api')
                ->after('slug')
                ->index();
            $table->foreignId('ai_agent_id')
                ->nullable()
                ->after('ai_provider_id')
                ->constrained('ai_agents')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ai_workload_profiles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ai_agent_id');
            $table->dropColumn('workload_type');
        });
    }
};
