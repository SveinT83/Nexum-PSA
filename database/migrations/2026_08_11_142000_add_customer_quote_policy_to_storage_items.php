<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storage_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('storage_items', 'requires_customer_quote')) {
                $table->boolean('requires_customer_quote')->default(false)->after('can_be_ordered');
            }
        });
    }

    public function down(): void
    {
        Schema::table('storage_items', function (Blueprint $table): void {
            if (Schema::hasColumn('storage_items', 'requires_customer_quote')) {
                $table->dropColumn('requires_customer_quote');
            }
        });
    }
};
