<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_box_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('box_id')->constrained('storage_boxes')->cascadeOnDelete();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('type');
            $table->foreignId('from_warehouse_id')->nullable()->constrained('storage_warehouses')->nullOnDelete();
            $table->foreignId('to_warehouse_id')->nullable()->constrained('storage_warehouses')->nullOnDelete();
            $table->foreignId('from_room_id')->nullable()->constrained('storage_rooms')->nullOnDelete();
            $table->foreignId('to_room_id')->nullable()->constrained('storage_rooms')->nullOnDelete();
            $table->json('details')->nullable();
            $table->timestamps();

            $table->index(['box_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_box_events');
    }
};
