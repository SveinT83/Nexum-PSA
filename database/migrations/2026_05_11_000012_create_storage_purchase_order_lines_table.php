<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('storage_purchase_orders')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('storage_items');
            $table->unsignedInteger('qty_ordered');
            $table->unsignedInteger('qty_received')->default(0);
            $table->decimal('unit_cost', 12, 2)->nullable();
            $table->decimal('tax_rate', 5, 2)->nullable();
            $table->date('expected_at')->nullable();
            $table->timestamps();

            $table->index(['item_id', 'expected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_purchase_order_lines');
    }
};
