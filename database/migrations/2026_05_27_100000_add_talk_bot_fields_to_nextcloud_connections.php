<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add Nextcloud Talk bot configuration fields to nextcloud_connections.
 *
 * These fields support the Talk Bot API (NC 27.1+ / Talk 17.1+) for
 * signed bot messaging, which is more capable than the simple webhook
 * approach previously used for Talk notifications.
 *
 * Fields added:
 * - talk_bot_id: The numeric bot ID assigned by `./occ talk:bot:install`
 * - talk_bot_secret: The shared secret used for HMAC-SHA256 signing (stored encrypted)
 * - talk_default_conversation_token: Default conversation token for notifications
 * - talk_bot_features: Feature flags for the bot (reaction, no-setup, etc.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nextcloud_connections', function (Blueprint $table) {
            $table->unsignedBigInteger('talk_bot_id')->nullable()->after('settings');
            $table->text('talk_bot_secret')->nullable()->after('talk_bot_id');
            $table->string('talk_default_conversation_token', 64)->nullable()->after('talk_bot_secret');
            $table->json('talk_bot_features')->nullable()->after('talk_default_conversation_token');
        });
    }

    public function down(): void
    {
        Schema::table('nextcloud_connections', function (Blueprint $table) {
            $table->dropColumn([
                'talk_bot_id',
                'talk_bot_secret',
                'talk_default_conversation_token',
                'talk_bot_features',
            ]);
        });
    }
};