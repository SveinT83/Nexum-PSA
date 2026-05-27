<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('contract_items', function (Blueprint $table) {
            $table->id();

            // Relations
            $table->unsignedBigInteger('contract_id');
            $table->unsignedBigInteger('service_id')->nullable();

            // Snapshot fields (copied from service at time of adding)
            $table->string('name');
            $table->string('sku')->nullable();

            // Pricing
            $table->decimal('unit_price', 12, 2);
            $table->integer('quantity')->default(1);
            $table->string('unit');

            // Billing
            $table->string('billing_interval'); // month, year, etc

            // Discount
            $table->decimal('discount_value', 10, 2)->nullable();
            $table->enum('discount_type', ['amount', 'percent'])->nullable();

            // Fees
            $table->decimal('setup_fee', 12, 2)->nullable();
            $table->foreignId('sla_id')->nullable()->constrained('sla')->nullOnDelete();
            $table->boolean('uses_contract_default_sla')->default(true);
            $table->json('sla_snapshot')->nullable();

            // Optional advanced fields (can be expanded later)
            $table->string('sla')->nullable();
            $table->integer('caps')->nullable();

            // Timestamps
            $table->timestamps();

            // Indexes
            $table->index('contract_id');
            $table->index('service_id');

            // Foreign keys (optional to enforce now or later)
            $table->foreign('contract_id')->references('id')->on('contracts')->cascadeOnDelete();
            $table->foreign('service_id')->references('id')->on('services')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_items');
    }
};
