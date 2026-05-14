<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_boxes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('warehouse_id')->constrained('storage_warehouses');
            $table->foreignId('room_id')->nullable()->constrained('storage_rooms')->nullOnDelete();
            $table->string('code_human')->nullable()->unique();
            $table->string('name')->nullable();
            $table->string('barcode_value')->nullable()->unique();
            $table->string('barcode_type')->default('QR');
            $table->string('status')->default('in_stock');
            $table->text('placement_note')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['warehouse_id', 'room_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_boxes');
    }
};
