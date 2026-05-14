<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('storage_items')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('storage_warehouses')->nullOnDelete();
            $table->foreignId('box_id')->nullable()->constrained('storage_boxes')->nullOnDelete();
            $table->unsignedInteger('qty');
            $table->string('source_type');
            $table->string('source_id')->nullable();
            $table->string('strength')->default('hard');
            $table->string('status')->default('active');
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['item_id', 'status']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_reservations');
    }
};
