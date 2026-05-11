<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('storage_warehouses')->cascadeOnDelete();
            $table->string('name');
            $table->string('code')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['warehouse_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_rooms');
    }
};
