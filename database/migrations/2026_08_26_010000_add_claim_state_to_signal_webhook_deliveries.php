<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signal_webhook_deliveries', function (Blueprint $table): void {
            $table->string('claim_token', 64)->nullable()->after('attempts');
            $table->timestamp('completed_at')->nullable()->after('last_attempted_at');
            $table->index(
                ['status', 'last_attempted_at', 'id'],
                'signal_webhook_outbox_dispatch_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('signal_webhook_deliveries', function (Blueprint $table): void {
            $table->dropIndex('signal_webhook_outbox_dispatch_idx');
            $table->dropColumn(['claim_token', 'completed_at']);
        });
    }
};
