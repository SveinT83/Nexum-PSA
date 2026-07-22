<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cloudfactory_vendor_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('integration_id')->constrained('integrations')->cascadeOnDelete();
            $table->string('external_category_id');
            $table->string('external_name');
            $table->string('external_product_type')->nullable();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->string('match_method')->nullable();
            $table->json('provider_payload')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['integration_id', 'external_category_id'],
                'cf_vendor_link_external_unique'
            );
            $table->index(['integration_id', 'vendor_id'], 'cf_vendor_link_vendor_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cloudfactory_vendor_links');
    }
};
