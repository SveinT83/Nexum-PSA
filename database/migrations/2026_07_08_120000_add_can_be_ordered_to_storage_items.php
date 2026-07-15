<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storage_items', function (Blueprint $table): void {
            $table->boolean('can_be_ordered')->default(true)->after('should_order');
        });
    }

    public function down(): void
    {
        Schema::table('storage_items', function (Blueprint $table): void {
            $table->dropColumn('can_be_ordered');
        });
    }
};
