<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_cost_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->foreignId('storage_item_id')->constrained('storage_items')->cascadeOnDelete();
            $table->foreignId('storage_reservation_id')->nullable()->constrained('storage_reservations')->nullOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('item_name');
            $table->string('item_sku')->nullable();
            $table->decimal('unit_price_ex_vat', 12, 2)->nullable();
            $table->string('currency', 3)->default('NOK');
            $table->string('status')->default('reserved');
            $table->string('billing_status')->default('pending');
            $table->text('invoice_text')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['ticket_id', 'status']);
            $table->index(['storage_item_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_cost_entries');
    }
};
