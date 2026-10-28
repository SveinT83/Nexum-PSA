<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Add independent, default-off destination configuration and body-free send evidence. */
    public function up(): void
    {
        Schema::create('nextcloud_talk_destinations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained('nextcloud_connections')->cascadeOnDelete();
            $table->string('conversation_token', 128);
            $table->string('name', 512);
            $table->unsignedTinyInteger('room_type')->default(0);
            $table->boolean('is_enabled')->default(false);
            $table->boolean('is_available')->default(false);
            $table->string('health_status', 24)->default('unknown');
            $table->timestamp('last_discovered_at')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_state', 24)->nullable();
            $table->string('last_test_reason', 80)->nullable();
            $table->unsignedBigInteger('last_message_id')->nullable();
            $table->timestamps();
            $table->unique(['connection_id', 'conversation_token'], 'talk_destination_connection_token_unique');
        });
        Schema::create('nextcloud_talk_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->char('idempotency_hash', 64)->unique();
            $table->char('payload_hash', 64);
            $table->foreignId('connection_id')->nullable()->constrained('nextcloud_connections')->nullOnDelete();
            $table->foreignId('destination_id')->nullable()->constrained('nextcloud_talk_destinations')->nullOnDelete();
            $table->uuid('connection_uuid');
            $table->string('conversation_token', 128);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('kind', 24);
            $table->string('state', 24)->default('claimed');
            $table->string('reason_code', 80)->nullable();
            $table->char('reference_id', 64);
            $table->unsignedBigInteger('provider_message_id')->nullable();
            $table->timestamp('claimed_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('nextcloud_talk_deliveries')->exists() || DB::table('nextcloud_talk_destinations')->exists()) {
            throw new RuntimeException('Talk destination/receipt history requires explicit preservation before rollback.');
        }
        Schema::dropIfExists('nextcloud_talk_deliveries');
        Schema::dropIfExists('nextcloud_talk_destinations');
    }
};
