<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cloudfactory_webhook_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('integration_id')->constrained('integrations')->cascadeOnDelete();
            $table->string('fingerprint', 64);
            $table->string('event_key')->index();
            $table->uuid('partner_guid');
            $table->dateTime('provider_created_at');
            $table->dateTime('provider_sent_at');
            $table->dateTime('received_at');
            $table->boolean('header_valid')->default(true);
            $table->string('processing_state')->default('queued')->index();
            $table->json('sanitized_payload')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->dateTime('processed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['integration_id', 'fingerprint'],
                'cf_webhook_integration_fingerprint_unique'
            );
            $table->index(
                ['integration_id', 'processing_state', 'received_at'],
                'cf_webhook_processing_state_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cloudfactory_webhook_receipts');
    }
};
