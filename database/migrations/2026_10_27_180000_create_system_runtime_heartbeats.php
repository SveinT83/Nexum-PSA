<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One bounded metadata row per runtime component; no business payload is retained.
        Schema::create('system_runtime_heartbeats', function (Blueprint $table): void {
            $table->string('component_key', 64)->primary();
            $table->string('runtime_key', 64)->index();
            $table->string('kind', 16);
            $table->string('connection_name', 64)->nullable();
            $table->string('driver', 24)->nullable();
            $table->string('queue_name', 80)->nullable();
            $table->timestamp('observed_at', 6)->nullable();
            $table->uuid('pending_probe_id')->nullable();
            $table->timestamp('pending_since', 6)->nullable();
            $table->timestamp('enqueued_at', 6)->nullable();
            $table->string('blocked_reason', 40)->nullable();
            $table->uuid('completed_probe_id')->nullable();
            $table->timestamp('completed_at', 6)->nullable();
            $table->uuid('last_reconciled_probe_id')->nullable();
            $table->timestamp('last_reconciled_at', 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_runtime_heartbeats');
    }
};
