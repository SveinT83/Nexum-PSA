<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pin the complete effective global/profile policy used by each import.
     */
    public function up(): void
    {
        Schema::table('storage_purchase_order_imports', function (Blueprint $table): void {
            $table->json('effective_policy_snapshot')->nullable()->after('policy_revision_id');
            $table->char('effective_policy_checksum', 64)->nullable()->after('effective_policy_snapshot');
        });
    }

    /**
     * Remove only the additional policy evidence columns.
     */
    public function down(): void
    {
        Schema::table('storage_purchase_order_imports', function (Blueprint $table): void {
            $table->dropColumn([
                'effective_policy_snapshot',
                'effective_policy_checksum',
            ]);
        });
    }
};
