<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Completion freshness alone must not conceal a canary's excessive queue wait.
        Schema::table('system_runtime_heartbeats', function (Blueprint $table): void {
            $table->unsignedInteger('completed_latency_seconds')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('system_runtime_heartbeats', function (Blueprint $table): void {
            $table->dropColumn('completed_latency_seconds');
        });
    }
};
