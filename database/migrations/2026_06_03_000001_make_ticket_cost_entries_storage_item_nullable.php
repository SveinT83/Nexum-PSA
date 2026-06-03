<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_cost_entries', function (Blueprint $table): void {
            $table->dropForeign(['storage_item_id']);
            $table->unsignedBigInteger('storage_item_id')->nullable()->change();
            $table->foreign('storage_item_id')->references('id')->on('storage_items')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        DB::table('ticket_cost_entries')->whereNull('storage_item_id')->delete();

        Schema::table('ticket_cost_entries', function (Blueprint $table): void {
            $table->dropForeign(['storage_item_id']);
            $table->unsignedBigInteger('storage_item_id')->nullable(false)->change();
            $table->foreign('storage_item_id')->references('id')->on('storage_items')->cascadeOnDelete();
        });
    }
};
