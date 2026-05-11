<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('storage_items')->cascadeOnDelete();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('type');
            $table->integer('qty_before')->default(0);
            $table->integer('qty_delta')->default(0);
            $table->integer('qty_after')->default(0);
            $table->foreignId('from_warehouse_id')->nullable()->constrained('storage_warehouses')->nullOnDelete();
            $table->foreignId('to_warehouse_id')->nullable()->constrained('storage_warehouses')->nullOnDelete();
            $table->foreignId('from_room_id')->nullable()->constrained('storage_rooms')->nullOnDelete();
            $table->foreignId('to_room_id')->nullable()->constrained('storage_rooms')->nullOnDelete();
            $table->foreignId('from_box_id')->nullable()->constrained('storage_boxes')->nullOnDelete();
            $table->foreignId('to_box_id')->nullable()->constrained('storage_boxes')->nullOnDelete();
            $table->foreignId('stock_unit_id')->nullable()->constrained('storage_stock_units')->nullOnDelete();
            $table->string('source_type')->nullable();
            $table->string('source_id')->nullable();
            $table->string('reason')->nullable();
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['item_id', 'type']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_movements');
    }
};
