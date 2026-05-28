<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the standard Laravel notifications table plus a per-user
     * notification settings table for channel preferences.
     */
    public function up(): void
    {
        // Standard Laravel notifications table
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->nullableTimestamps();

            $table->index(['notifiable_type', 'notifiable_id', 'read_at'], 'notifications_notifiable_read_index');
        });

        // Per-user notification channel preferences
        Schema::create('notification_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('user_management')->cascadeOnDelete();
            $table->string('notification_type'); // e.g. 'ticket_assigned', 'ticket_updated', 'asset_alert'
            $table->boolean('mail_enabled')->default(true);
            $table->boolean('database_enabled')->default(true);
            $table->boolean('nextcloud_talk_enabled')->default(false);
            $table->string('nextcloud_talk_webhook_url')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'notification_type']);
        });

        // Nextcloud Talk integration config (system-wide)
        Schema::create('notification_channels', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // e.g. 'nextcloud_talk'
            $table->string('label');           // e.g. 'Nextcloud Talk'
            $table->string('driver');          // e.g. 'nextcloud_talk'
            $table->boolean('is_enabled')->default(false);
            $table->text('config')->nullable(); // JSON: base_url, admin webhook, etc.
            $table->text('secrets')->nullable(); // JSON (encrypted): API tokens
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_result')->nullable();
            $table->timestamps();
        });

        // Seed default notification types
        $defaults = [
            'ticket_created',
            'ticket_assigned',
            'ticket_updated',
            'ticket_status_changed',
            'ticket_comment_added',
            'ticket_sla_warning',
            'asset_alert',
            'asset_alert_resolved',
            'invitation_sent',
            'system_announcement',
        ];

        // Seed default Nextcloud Talk channel (disabled)
        \DB::table('notification_channels')->insert([
            'name' => 'nextcloud_talk',
            'label' => 'Nextcloud Talk',
            'driver' => 'nextcloud_talk',
            'is_enabled' => false,
            'config' => json_encode([
                'default_webhook_url' => '',
            ]),
            'secrets' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_channels');
        Schema::dropIfExists('notification_settings');
        Schema::dropIfExists('notifications');
    }
};
