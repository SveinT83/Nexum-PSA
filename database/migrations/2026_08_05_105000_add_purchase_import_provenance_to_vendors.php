<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the guarded supplier-import claim and its sanitized source provenance.
     */
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table): void {
            $table->foreignId('created_from_purchase_import_id')->nullable();
            $table->char('supplier_import_identity_hash', 64)->nullable();
            $table->string('supplier_bootstrap_status')->default('not_applicable');
            $table->json('source_provenance')->nullable();

            $table->index('created_from_purchase_import_id', 'vendors_pi_source_ix');
            $table->unique('supplier_import_identity_hash', 'vendors_pi_identity_uq');
            $table->index('supplier_bootstrap_status', 'vendors_bootstrap_status_ix');
            $table->foreign(
                'created_from_purchase_import_id',
                'vendors_pi_source_fk'
            )->references('id')->on('storage_purchase_order_imports')->nullOnDelete();
        });
    }

    /**
     * Remove the import provenance in dependency order.
     */
    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table): void {
            $table->dropForeign('vendors_pi_source_fk');
            $table->dropUnique('vendors_pi_identity_uq');
            $table->dropIndex('vendors_pi_source_ix');
            $table->dropIndex('vendors_bootstrap_status_ix');
            $table->dropColumn([
                'created_from_purchase_import_id',
                'supplier_import_identity_hash',
                'supplier_bootstrap_status',
                'source_provenance',
            ]);
        });
    }
};
