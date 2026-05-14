<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_stock_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('storage_items')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('storage_warehouses');
            $table->foreignId('room_id')->nullable()->constrained('storage_rooms')->nullOnDelete();
            $table->foreignId('box_id')->nullable()->constrained('storage_boxes')->nullOnDelete();
            $table->string('serial_no')->nullable();
            $table->string('batch_no')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('status')->default('available');
            $table->unsignedInteger('current_qty')->default(1);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['item_id', 'serial_no']);
            $table->index(['item_id', 'batch_no', 'expiry_date']);
            $table->index(['warehouse_id', 'room_id', 'box_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_stock_units');
    }
};
