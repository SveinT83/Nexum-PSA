<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_purchase_shipments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')
                ->constrained('storage_purchase_orders')
                ->restrictOnDelete();
            $table->foreignId('shipping_carrier_id')
                ->nullable()
                ->constrained('shipping_carriers')
                ->nullOnDelete();
            $table->string('reference')->nullable();
            $table->string('status')->default('pending');
            $table->string('carrier_code_snapshot')->nullable();
            $table->string('carrier_name_snapshot')->nullable();
            $table->string('carrier_tracking_method_snapshot')->nullable();
            $table->text('carrier_tracking_url_template_snapshot')->nullable();
            $table->text('carrier_tracking_page_url_snapshot')->nullable();
            $table->json('carrier_allowed_hosts_snapshot')->nullable();
            $table->string('carrier_link_visibility_snapshot')->nullable();
            $table->string('carrier_verification_state_snapshot')->nullable();
            $table->date('carrier_verified_at_snapshot')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('expected_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->unsignedBigInteger('status_changed_by')->nullable()->index();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->timestamps();

            $table->index(
                ['purchase_order_id', 'status'],
                'storage_purchase_shipments_order_status_index'
            );
            $table->index(['status', 'expected_at'], 'storage_purchase_shipments_status_expected_index');
        });

        Schema::create('storage_purchase_shipment_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_shipment_id')
                ->constrained('storage_purchase_shipments')
                ->cascadeOnDelete();
            $table->foreignId('purchase_order_line_id')
                ->constrained('storage_purchase_order_lines')
                ->restrictOnDelete();
            $table->unsignedInteger('qty_allocated');
            $table->unsignedInteger('qty_received')->default(0);
            $table->unsignedInteger('qty_rejected')->default(0);
            $table->unsignedInteger('qty_cancelled')->default(0);
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->timestamps();

            $table->unique(
                ['purchase_shipment_id', 'purchase_order_line_id'],
                'storage_purchase_shipment_lines_unique'
            );
            $table->index(
                ['purchase_order_line_id', 'qty_allocated'],
                'storage_purchase_shipment_lines_allocation_index'
            );
        });

        Schema::create('storage_purchase_shipment_trackings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_shipment_id')
                ->constrained('storage_purchase_shipments')
                ->cascadeOnDelete();
            $table->foreignId('shipping_carrier_id')
                ->nullable()
                ->constrained('shipping_carriers')
                ->nullOnDelete();
            $table->string('tracking_number');
            $table->string('tracking_type')->default('parcel');
            $table->string('label')->nullable();
            $table->text('direct_url')->nullable();
            $table->string('carrier_code_snapshot')->nullable();
            $table->string('carrier_name_snapshot')->nullable();
            $table->string('carrier_tracking_method_snapshot')->nullable();
            $table->text('carrier_tracking_url_template_snapshot')->nullable();
            $table->text('carrier_tracking_page_url_snapshot')->nullable();
            $table->json('carrier_allowed_hosts_snapshot')->nullable();
            $table->string('carrier_link_visibility_snapshot')->nullable();
            $table->string('carrier_verification_state_snapshot')->nullable();
            $table->date('carrier_verified_at_snapshot')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->timestamps();

            $table->unique(
                ['purchase_shipment_id', 'tracking_number'],
                'storage_purchase_shipment_tracking_unique'
            );
            $table->index('tracking_number', 'storage_purchase_tracking_number_index');
            $table->index(
                ['purchase_shipment_id', 'sort_order'],
                'storage_purchase_shipment_tracking_sort_index'
            );
        });

        $this->backfillLegacyTrackingNumbers();
    }

    public function down(): void
    {
        $this->guardOperationalShipmentData();

        Schema::dropIfExists('storage_purchase_shipment_trackings');
        Schema::dropIfExists('storage_purchase_shipment_lines');
        Schema::dropIfExists('storage_purchase_shipments');
    }

    private function backfillLegacyTrackingNumbers(): void
    {
        DB::table('storage_purchase_orders')
            ->whereNotNull('tracking_no')
            ->where('tracking_no', '<>', '')
            ->orderBy('id')
            ->chunkById(250, function ($orders): void {
                foreach ($orders as $order) {
                    $shipmentId = DB::table('storage_purchase_shipments')->insertGetId([
                        'purchase_order_id' => $order->id,
                        'reference' => 'Legacy tracking',
                        'status' => 'pending',
                        'notes' => 'Created automatically from the legacy purchase-order tracking number.',
                        'metadata' => json_encode([
                            'migration_source' => 'legacy_purchase_order_tracking_no',
                        ], JSON_THROW_ON_ERROR),
                        'created_by' => $order->created_by,
                        'updated_by' => $order->updated_by,
                        'created_at' => $order->created_at,
                        'updated_at' => $order->updated_at,
                    ]);

                    DB::table('storage_purchase_shipment_trackings')->insert([
                        'purchase_shipment_id' => $shipmentId,
                        'tracking_number' => trim((string) $order->tracking_no),
                        'tracking_type' => 'legacy',
                        'label' => 'Legacy tracking number',
                        'sort_order' => 0,
                        'created_by' => $order->created_by,
                        'updated_by' => $order->updated_by,
                        'created_at' => $order->created_at,
                        'updated_at' => $order->updated_at,
                    ]);
                }
            });
    }

    private function guardOperationalShipmentData(): void
    {
        if (! Schema::hasTable('storage_purchase_shipments')) {
            return;
        }
        if ((Schema::hasTable('storage_purchase_shipment_lines')
            && DB::table('storage_purchase_shipment_lines')->exists())
            || (Schema::hasTable('storage_purchase_receipts')
                && DB::table('storage_purchase_receipts')
                    ->whereNotNull('purchase_shipment_id')
                    ->exists())) {
            throw new RuntimeException(
                'Operational purchase-shipment allocations or receipts require export and a forward-fix migration.'
            );
        }

        foreach (DB::table('storage_purchase_shipments')->orderBy('id')->cursor() as $shipment) {
            if (! $this->isPristineLegacyBackfill($shipment)) {
                throw new RuntimeException(
                    'Operational purchase-shipment or tracking data requires export and a forward-fix migration.'
                );
            }
        }
    }

    private function isPristineLegacyBackfill(object $shipment): bool
    {
        $order = DB::table('storage_purchase_orders')->find($shipment->purchase_order_id);
        $trackings = Schema::hasTable('storage_purchase_shipment_trackings')
            ? DB::table('storage_purchase_shipment_trackings')
                ->where('purchase_shipment_id', $shipment->id)
                ->get()
            : collect();
        $tracking = $trackings->first();
        $metadata = is_string($shipment->metadata)
            ? json_decode($shipment->metadata, true)
            : (array) $shipment->metadata;
        $shipmentNullFields = [
            'shipping_carrier_id',
            'carrier_code_snapshot',
            'carrier_name_snapshot',
            'carrier_tracking_method_snapshot',
            'carrier_tracking_url_template_snapshot',
            'carrier_tracking_page_url_snapshot',
            'carrier_allowed_hosts_snapshot',
            'carrier_link_visibility_snapshot',
            'carrier_verification_state_snapshot',
            'carrier_verified_at_snapshot',
            'shipped_at',
            'expected_at',
            'delivered_at',
            'status_changed_at',
            'status_changed_by',
        ];
        $trackingNullFields = [
            'shipping_carrier_id',
            'direct_url',
            'carrier_code_snapshot',
            'carrier_name_snapshot',
            'carrier_tracking_method_snapshot',
            'carrier_tracking_url_template_snapshot',
            'carrier_tracking_page_url_snapshot',
            'carrier_allowed_hosts_snapshot',
            'carrier_link_visibility_snapshot',
            'carrier_verification_state_snapshot',
            'carrier_verified_at_snapshot',
            'metadata',
        ];

        return $order
            && $shipment->reference === 'Legacy tracking'
            && $shipment->status === 'pending'
            && $shipment->notes === 'Created automatically from the legacy purchase-order tracking number.'
            && $metadata === ['migration_source' => 'legacy_purchase_order_tracking_no']
            && collect($shipmentNullFields)->every(
                fn (string $field): bool => $shipment->{$field} === null
            )
            && $trackings->count() === 1
            && $tracking->tracking_number === trim((string) $order->tracking_no)
            && $tracking->tracking_type === 'legacy'
            && $tracking->label === 'Legacy tracking number'
            && (int) $tracking->sort_order === 0
            && collect($trackingNullFields)->every(
                fn (string $field): bool => $tracking->{$field} === null
            );
    }
};
