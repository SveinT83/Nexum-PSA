<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('po_number')->unique();
            $table->foreignId('vendor_id')->constrained('storage_vendors');
            $table->foreignId('deliver_to_warehouse_id')->constrained('storage_warehouses');
            $table->string('status')->default('draft');
            $table->string('vendor_ref')->nullable();
            $table->string('tracking_no')->nullable();
            $table->date('ordered_at')->nullable();
            $table->date('expected_at')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['vendor_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_purchase_orders');
    }
};
