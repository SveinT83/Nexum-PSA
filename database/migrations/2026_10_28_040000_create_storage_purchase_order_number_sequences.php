<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** No purchase-order backfill: preserve one canonical high-water state independently of deleted orders. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_purchase_order_number_sequences', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamp('initialized_at')->nullable();
        });
        DB::table('storage_purchase_order_number_sequences')->insert(['id' => 1, 'last_number' => 0, 'initialized_at' => null]);
    }

    public function down(): void
    {
        if (Schema::hasTable('storage_purchase_order_number_sequences')
            && DB::table('storage_purchase_order_number_sequences')->where('last_number', '>', 0)->exists()) {
            throw new RuntimeException('Preserve the used purchase order number sequence; retire it through the reviewed rollback procedure.');
        }
        Schema::dropIfExists('storage_purchase_order_number_sequences');
    }
};
