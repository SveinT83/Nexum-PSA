<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_item_vendors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('storage_items')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->string('vendor_sku')->nullable();
            $table->string('purchase_url')->nullable();
            $table->string('currency', 3)->default('NOK');
            $table->decimal('unit_cost', 12, 2)->nullable();
            $table->unsignedInteger('moq')->default(1);
            $table->unsignedInteger('pack_size')->default(1);
            $table->unsignedInteger('lead_time_days')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->string('vat_policy')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestamps();

            $table->unique(['item_id', 'vendor_id', 'vendor_sku']);
            $table->index(['item_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_item_vendors');
    }
};
