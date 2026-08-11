<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_purchase_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('receipt_number')->unique();
            $table->foreignId('purchase_order_id')
                ->constrained('storage_purchase_orders')
                ->restrictOnDelete();
            $table->foreignId('purchase_shipment_id')
                ->nullable()
                ->constrained('storage_purchase_shipments')
                ->restrictOnDelete();
            $table->string('receipt_type')->default('receipt');
            $table->string('status')->default('posting');
            $table->string('idempotency_token', 100)->unique();
            $table->char('request_hash', 64);
            $table->string('delivery_note_ref')->nullable();
            $table->timestamp('received_at');
            $table->foreignId('warehouse_id')->constrained('storage_warehouses')->restrictOnDelete();
            $table->foreignId('room_id')->nullable()->constrained('storage_rooms')->nullOnDelete();
            $table->foreignId('box_id')->nullable()->constrained('storage_boxes')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->timestamps();

            $table->index(
                ['purchase_order_id', 'status'],
                'storage_purchase_receipts_order_status_index'
            );
            $table->index(
                ['purchase_shipment_id', 'received_at'],
                'storage_purchase_receipts_shipment_received_index'
            );
        });

        Schema::create('storage_purchase_receipt_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_receipt_id')
                ->constrained('storage_purchase_receipts')
                ->cascadeOnDelete();
            $table->foreignId('purchase_order_line_id')
                ->constrained('storage_purchase_order_lines')
                ->restrictOnDelete();
            $table->foreignId('item_id')->constrained('storage_items')->restrictOnDelete();
            $table->foreignId('purchase_shipment_line_id')
                ->nullable()
                ->constrained('storage_purchase_shipment_lines')
                ->nullOnDelete();
            $table->unsignedBigInteger('reverses_receipt_line_id')->nullable();
            $table->unsignedInteger('qty_accepted')->default(0);
            $table->unsignedInteger('qty_rejected')->default(0);
            $table->integer('qty_on_hand_before');
            $table->integer('qty_on_hand_after');
            $table->string('item_name_snapshot');
            $table->string('sku_snapshot');
            $table->string('supplier_sku_snapshot')->nullable();
            $table->decimal('unit_cost_snapshot', 12, 2)->nullable();
            $table->decimal('tax_rate_snapshot', 5, 2)->nullable();
            $table->string('currency_snapshot', 3);
            $table->text('discrepancy_note')->nullable();
            $table->boolean('is_over_receipt')->default(false);
            $table->text('over_receipt_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign(
                'reverses_receipt_line_id',
                'storage_receipt_lines_reverses_line_fk'
            )->references('id')->on('storage_purchase_receipt_lines')->restrictOnDelete();
            $table->unique(
                ['purchase_receipt_id', 'purchase_order_line_id'],
                'storage_purchase_receipt_line_unique'
            );
            $table->index(
                ['purchase_order_line_id', 'created_at'],
                'storage_purchase_receipt_lines_order_line_index'
            );
        });

        Schema::create('storage_purchase_receipt_units', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_receipt_line_id')
                ->constrained('storage_purchase_receipt_lines')
                ->cascadeOnDelete();
            $table->foreignId('stock_unit_id')
                ->constrained('storage_stock_units')
                ->restrictOnDelete();
            $table->unsignedBigInteger('reverses_receipt_unit_id')->nullable();
            $table->unsignedInteger('quantity');
            $table->string('serial_no_snapshot')->nullable();
            $table->string('batch_no_snapshot')->nullable();
            $table->date('expiry_date_snapshot')->nullable();
            $table->timestamps();

            $table->foreign(
                'reverses_receipt_unit_id',
                'storage_receipt_units_reverses_unit_fk'
            )->references('id')->on('storage_purchase_receipt_units')->restrictOnDelete();
            $table->unique(
                ['purchase_receipt_line_id', 'stock_unit_id'],
                'storage_purchase_receipt_unit_unique'
            );
        });

        Schema::create('storage_purchase_receipt_reversals', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('original_receipt_id')->unique();
            $table->unsignedBigInteger('reversal_receipt_id')->unique();
            $table->text('reason');
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->timestamps();

            $table->foreign(
                'original_receipt_id',
                'storage_receipt_reversal_original_fk'
            )->references('id')->on('storage_purchase_receipts')->restrictOnDelete();
            $table->foreign(
                'reversal_receipt_id',
                'storage_receipt_reversal_reversal_fk'
            )->references('id')->on('storage_purchase_receipts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        $hasReceiptRows = Schema::hasTable('storage_purchase_receipts')
            && DB::table('storage_purchase_receipts')->exists();
        $hasReceiptMovements = Schema::hasTable('storage_movements')
            && DB::table('storage_movements')
                ->whereIn('reason', ['purchase_receipt', 'purchase_receipt_reversal'])
                ->exists();

        if ($hasReceiptRows || $hasReceiptMovements) {
            throw new RuntimeException(
                'Posted purchase receipts and inventory movements require reversal/export and a forward-fix migration.'
            );
        }

        Schema::dropIfExists('storage_purchase_receipt_reversals');
        Schema::dropIfExists('storage_purchase_receipt_units');
        Schema::dropIfExists('storage_purchase_receipt_lines');
        Schema::dropIfExists('storage_purchase_receipts');
    }
};
