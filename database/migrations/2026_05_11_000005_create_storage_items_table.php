<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('storage_warehouses');
            $table->foreignId('room_id')->nullable()->constrained('storage_rooms')->nullOnDelete();
            $table->foreignId('box_id')->nullable()->constrained('storage_boxes')->nullOnDelete();
            $table->foreignId('primary_vendor_id')->nullable()->constrained('storage_vendors')->nullOnDelete();
            $table->string('sku')->unique();
            $table->string('name');
            $table->text('short_description')->nullable();
            $table->text('long_description')->nullable();
            $table->string('manufacturer')->nullable();
            $table->string('manufacturer_part_number')->nullable();
            $table->string('ean_number')->nullable()->index();
            $table->decimal('purchase_price', 12, 2)->nullable();
            $table->decimal('markup_percent', 8, 2)->nullable();
            $table->decimal('sale_price', 12, 2)->nullable();
            $table->decimal('vat_rate', 5, 2)->nullable();
            $table->boolean('has_serials')->default(false);
            $table->boolean('track_batch')->default(false);
            $table->boolean('expiry_enabled')->default(false);
            $table->boolean('becomes_asset')->default(false);
            $table->unsignedInteger('default_warranty_months')->nullable();
            $table->unsignedInteger('reorder_point')->default(0);
            $table->unsignedInteger('target_level')->default(0);
            $table->unsignedInteger('lead_time_days')->default(0);
            $table->unsignedInteger('moq')->default(1);
            $table->integer('qty_on_hand')->default(0);
            $table->integer('qty_reserved')->default(0);
            $table->boolean('should_order')->default(false);
            $table->string('status')->default('active');
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['warehouse_id', 'room_id', 'box_id']);
            $table->index(['status', 'should_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_items');
    }
};
