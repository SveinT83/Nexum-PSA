<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            if (! Schema::hasColumn('notifications', 'delivery_identity')) {
                $table->string('delivery_identity', 191)
                    ->nullable()
                    ->unique()
                    ->after('type');
            }
        });

        Schema::create('notification_inbound_email_scopes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('user_management')
                ->cascadeOnDelete();
            $table->string('notification_type', 80);
            $table->string('scope_kind', 40);
            $table->unsignedBigInteger('scope_id');
            $table->timestamps();

            $table->unique(
                ['user_id', 'notification_type', 'scope_kind', 'scope_id'],
                'notification_inbound_email_scope_unique'
            );
            $table->index(['notification_type', 'scope_kind', 'scope_id'], 'notification_inbound_email_scope_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_inbound_email_scopes');

        Schema::table('notifications', function (Blueprint $table): void {
            if (Schema::hasColumn('notifications', 'delivery_identity')) {
                $table->dropUnique(['delivery_identity']);
                $table->dropColumn('delivery_identity');
            }
        });
    }
};
