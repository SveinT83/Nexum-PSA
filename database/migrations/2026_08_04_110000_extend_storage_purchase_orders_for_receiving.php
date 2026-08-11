<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storage_purchase_orders', function (Blueprint $table): void {
            $table->string('supplier_name_snapshot')->nullable()->after('vendor_id');
            $table->string('currency', 3)->default('NOK')->after('expected_at');
            $table->timestamp('status_changed_at')->nullable()->after('status');
            $table->unsignedBigInteger('status_changed_by')->nullable()->after('status_changed_at')->index();
            $table->timestamp('closed_at')->nullable()->after('status_changed_by');
            $table->timestamp('cancelled_at')->nullable()->after('closed_at');
            $table->json('metadata')->nullable()->after('notes');

            $table->index(
                ['deliver_to_warehouse_id', 'status'],
                'storage_purchase_orders_warehouse_status_index'
            );
            $table->index(
                ['status', 'expected_at'],
                'storage_purchase_orders_status_expected_index'
            );
        });

        Schema::table('storage_purchase_order_lines', function (Blueprint $table): void {
            $table->string('item_name_snapshot')->nullable()->after('item_id');
            $table->string('sku_snapshot')->nullable()->after('item_name_snapshot');
            $table->string('supplier_sku_snapshot')->nullable()->after('sku_snapshot');
            $table->unsignedInteger('qty_cancelled')->default(0)->after('qty_received');
            $table->string('currency', 3)->default('NOK')->after('tax_rate');
            $table->text('cancellation_reason')->nullable()->after('currency');
            $table->timestamp('cancelled_at')->nullable()->after('cancellation_reason');
            $table->unsignedBigInteger('cancelled_by')->nullable()->after('cancelled_at')->index();
            $table->unsignedBigInteger('created_by')->nullable()->after('metadata')->index();
            $table->unsignedBigInteger('updated_by')->nullable()->after('created_by')->index();

            $table->index(
                ['purchase_order_id', 'qty_received', 'qty_cancelled'],
                'storage_po_lines_receiving_progress_index'
            );
        });

        $this->backfillSnapshots();
    }

    public function down(): void
    {
        $hasOperationalRows = (
            Schema::hasTable('storage_purchase_order_lines')
            && DB::table('storage_purchase_order_lines')
                ->where(function ($query): void {
                    $query->where('qty_cancelled', '>', 0)
                        ->orWhereNotNull('supplier_sku_snapshot')
                        ->orWhereNotNull('created_by')
                        ->orWhereNotNull('updated_by');
                })
                ->exists()
        ) || (
            Schema::hasTable('storage_purchase_orders')
            && DB::table('storage_purchase_orders')
                ->where(function ($query): void {
                    $query->where('currency', '<>', 'NOK')
                        ->orWhereNotNull('metadata')
                        ->orWhereIn('status', ['partially_received', 'closed', 'cancelled']);
                })
                ->exists()
        );
        $hasReceiptMovements = Schema::hasTable('storage_movements')
            && DB::table('storage_movements')
                ->whereIn('reason', ['purchase_receipt', 'purchase_receipt_reversal'])
                ->exists();

        if ($hasOperationalRows || $hasReceiptMovements) {
            throw new RuntimeException(
                'Operational receiving or lifecycle data requires export and a forward-fix migration.'
            );
        }

        Schema::table('storage_purchase_order_lines', function (Blueprint $table): void {
            $table->dropIndex('storage_po_lines_receiving_progress_index');
            $table->dropIndex(['cancelled_by']);
            $table->dropIndex(['created_by']);
            $table->dropIndex(['updated_by']);
            $table->dropColumn([
                'item_name_snapshot',
                'sku_snapshot',
                'supplier_sku_snapshot',
                'qty_cancelled',
                'currency',
                'cancellation_reason',
                'cancelled_at',
                'cancelled_by',
                'created_by',
                'updated_by',
            ]);
        });

        Schema::table('storage_purchase_orders', function (Blueprint $table): void {
            $table->dropIndex('storage_purchase_orders_warehouse_status_index');
            $table->dropIndex('storage_purchase_orders_status_expected_index');
            $table->dropIndex(['status_changed_by']);
            $table->dropColumn([
                'supplier_name_snapshot',
                'currency',
                'status_changed_at',
                'status_changed_by',
                'closed_at',
                'cancelled_at',
                'metadata',
            ]);
        });
    }

    private function backfillSnapshots(): void
    {
        DB::table('storage_purchase_orders')
            ->select(['id', 'vendor_id', 'updated_by', 'updated_at'])
            ->orderBy('id')
            ->chunkById(250, function ($orders): void {
                $vendorNames = DB::table('vendors')
                    ->whereIn('id', $orders->pluck('vendor_id')->filter()->unique())
                    ->pluck('name', 'id');

                foreach ($orders as $order) {
                    DB::table('storage_purchase_orders')
                        ->where('id', $order->id)
                        ->update([
                            'supplier_name_snapshot' => $vendorNames[$order->vendor_id] ?? null,
                            'status_changed_at' => $order->updated_at,
                            'status_changed_by' => $order->updated_by,
                        ]);
                }
            });

        DB::table('storage_purchase_order_lines')
            ->select(['id', 'purchase_order_id', 'item_id'])
            ->orderBy('id')
            ->chunkById(250, function ($lines): void {
                $items = DB::table('storage_items')
                    ->whereIn('id', $lines->pluck('item_id')->unique())
                    ->get(['id', 'name', 'sku'])
                    ->keyBy('id');
                $currencies = DB::table('storage_purchase_orders')
                    ->whereIn('id', $lines->pluck('purchase_order_id')->unique())
                    ->pluck('currency', 'id');

                foreach ($lines as $line) {
                    $item = $items->get($line->item_id);

                    DB::table('storage_purchase_order_lines')
                        ->where('id', $line->id)
                        ->update([
                            'item_name_snapshot' => $item?->name,
                            'sku_snapshot' => $item?->sku,
                            'currency' => $currencies[$line->purchase_order_id] ?? 'NOK',
                        ]);
                }
            });
    }
};
