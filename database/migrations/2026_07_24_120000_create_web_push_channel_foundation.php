<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $subscriptionConnection = $this->subscriptionConnection();
        $subscriptionTable = $this->subscriptionTable();

        Schema::connection($subscriptionConnection)
            ->create($subscriptionTable, function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->uuid('public_id')->unique();
                $table->morphs('subscribable', 'push_subscriptions_subscribable_morph_idx');
                $table->string('endpoint', 500)->unique();
                $table->string('public_key')->nullable();
                $table->string('auth_token')->nullable();
                $table->string('content_encoding', 30)->nullable();
                $table->string('device_label', 120);
                $table->string('browser_family', 60);
                $table->string('platform_family', 60);
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamps();
            });

        Schema::create('web_push_subscription_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('target_user_id')
                ->constrained('user_management')
                ->cascadeOnDelete();
            $table->foreignId('actor_id')
                ->nullable()
                ->constrained('user_management')
                ->nullOnDelete();
            $table->uuid('subscription_public_id')->nullable()->index();
            $table->string('action', 60)->index();
            $table->string('device_label', 120);
            $table->string('browser_family', 60);
            $table->string('platform_family', 60);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['target_user_id', 'created_at']);
        });

        Schema::table('notification_settings', function (Blueprint $table): void {
            $table->boolean('web_push_enabled')
                ->default(false)
                ->after('database_enabled');
            $table->boolean('web_push_preview_enabled')
                ->default(false)
                ->after('web_push_enabled');
        });
    }

    public function down(): void
    {
        $subscriptionConnection = $this->subscriptionConnection();
        $subscriptionTable = $this->subscriptionTable();

        Schema::table('notification_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'web_push_enabled',
                'web_push_preview_enabled',
            ]);
        });

        Schema::dropIfExists('web_push_subscription_events');

        Schema::connection($subscriptionConnection)
            ->dropIfExists($subscriptionTable);
    }

    private function subscriptionConnection(): string
    {
        return trim((string) config('webpush.database_connection'))
            ?: (string) config('database.default', 'mysql');
    }

    private function subscriptionTable(): string
    {
        return trim((string) config('webpush.table_name'))
            ?: 'push_subscriptions';
    }
};
