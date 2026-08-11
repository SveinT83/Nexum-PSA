<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const KEY_COLUMN = 'supplier_order_identity_key';

    private const KEY_UNIQUE_INDEX = 'storage_po_supplier_order_identity_key_unique';

    private const LEGACY_HASH_INDEX = 'storage_po_supplier_order_identity_unique';

    public function up(): void
    {
        $this->assertExistingReferencesAreUnique();

        $driver = DB::connection()->getDriverName();
        if (! Schema::hasColumn('storage_purchase_orders', self::KEY_COLUMN)) {
            Schema::table('storage_purchase_orders', function (Blueprint $table) use ($driver): void {
                $key = $table->string(self::KEY_COLUMN, 255)
                    ->nullable()
                    ->virtualAs("NULLIF(UPPER(TRIM(vendor_ref)), '')");
                if (in_array($driver, ['mysql', 'mariadb'], true)) {
                    $key->collation('utf8mb4_bin');
                }
            });
        }

        if (! Schema::hasIndex('storage_purchase_orders', self::KEY_UNIQUE_INDEX)) {
            Schema::table('storage_purchase_orders', function (Blueprint $table): void {
                $table->unique(
                    ['vendor_id', self::KEY_COLUMN],
                    self::KEY_UNIQUE_INDEX,
                );
            });
        }

        if (Schema::hasIndex('storage_purchase_orders', self::LEGACY_HASH_INDEX)) {
            Schema::table('storage_purchase_orders', function (Blueprint $table): void {
                $table->dropUnique(self::LEGACY_HASH_INDEX);
            });
        }
    }

    public function down(): void
    {
        // Intentionally additive. On a fresh install migration 100000 owns this
        // invariant, while on an upgraded install this migration supplies it.
        // A rollback cannot infer ownership safely, so removal belongs to the
        // owning schema migration rather than this compatibility follow-up.
    }

    /**
     * Use the same database expression and binary comparison that the virtual
     * key uses so migration preflight cannot disagree with the final index.
     */
    private function assertExistingReferencesAreUnique(): void
    {
        $expression = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
            ? "NULLIF(UPPER(TRIM(vendor_ref)), '') COLLATE utf8mb4_bin"
            : "NULLIF(UPPER(TRIM(vendor_ref)), '') COLLATE BINARY";

        $normalized = DB::table('storage_purchase_orders')
            ->select(['id', 'vendor_id'])
            ->selectRaw($expression.' AS normalized_reference');

        $collisions = DB::query()
            ->fromSub($normalized, 'normalized_orders')
            ->select(['vendor_id', 'normalized_reference'])
            ->selectRaw('GROUP_CONCAT(id) AS order_ids')
            ->whereNotNull('normalized_reference')
            ->groupBy('vendor_id', 'normalized_reference')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($collisions->isNotEmpty()) {
            throw new RuntimeException(
                'Supplier-order identity collisions exist on purchase-order rows: '
                .$collisions->pluck('order_ids')->implode('; ')
                .'. Resolve them before retrying this migration.'
            );
        }
    }
};
