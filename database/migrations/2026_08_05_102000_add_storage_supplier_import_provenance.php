<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Keep automatic catalog and supplier-SKU decisions visible and reviewable.
     */
    public function up(): void
    {
        Schema::table('storage_items', function (Blueprint $table): void {
            $table->foreignId('created_from_import_id')
                ->nullable()
                ->after('can_be_ordered')
                ->constrained('storage_purchase_order_imports')
                ->nullOnDelete();
            $table->string('catalog_review_status')->default('not_required')->after('created_from_import_id');
            $table->json('source_provenance')->nullable()->after('catalog_review_status');
            $table->index(['catalog_review_status', 'created_from_import_id'], 'storage_items_import_review_index');
        });

        Schema::table('storage_item_vendors', function (Blueprint $table): void {
            $table->foreignId('created_from_import_line_id')
                ->nullable()
                ->after('vendor_sku')
                ->constrained('storage_purchase_order_import_lines')
                ->nullOnDelete();
            $table->char('supplier_sku_claim_hash', 64)->nullable()->after('created_from_import_line_id');
            $table->unique('supplier_sku_claim_hash', 'storage_item_vendors_supplier_sku_claim_unique');
            $table->string('resolution_method')->nullable()->after('created_from_import_line_id');
            $table->json('mapping_provenance')->nullable()->after('resolution_method');
            $table->foreignId('confirmed_by')
                ->nullable()
                ->after('mapping_provenance')
                ->constrained('user_management')
                ->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable()->after('confirmed_by');
            $table->index(['vendor_id', 'vendor_sku'], 'storage_item_vendors_supplier_sku_index');
        });
    }

    /**
     * Remove provenance columns without changing historical item quantities.
     */
    public function down(): void
    {
        Schema::table('storage_item_vendors', function (Blueprint $table): void {
            $table->dropUnique('storage_item_vendors_supplier_sku_claim_unique');
            $table->dropIndex('storage_item_vendors_supplier_sku_index');
            $table->dropForeign(['confirmed_by']);
            $table->dropForeign(['created_from_import_line_id']);
            $table->dropColumn([
                'confirmed_at',
                'confirmed_by',
                'mapping_provenance',
                'resolution_method',
                'supplier_sku_claim_hash',
                'created_from_import_line_id',
            ]);
        });

        Schema::table('storage_items', function (Blueprint $table): void {
            $table->dropIndex('storage_items_import_review_index');
            $table->dropForeign(['created_from_import_id']);
            $table->dropColumn([
                'source_provenance',
                'catalog_review_status',
                'created_from_import_id',
            ]);
        });
    }
};
