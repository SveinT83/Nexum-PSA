<?php

namespace App\Modules\Storage\Actions;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class UpdateSupplierOrderImportOperationalState
{
    public const OPERATION_KEY = 'supplier_order_imports';

    /**
     * Persist a bounded operational heartbeat or summary on the singleton state row.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function handle(array $attributes): void
    {
        $allowed = Arr::only($attributes, [
            'scheduler_heartbeat_at',
            'worker_heartbeat_at',
            'worker_sample_scheduled_at',
            'worker_queue_latency_seconds',
            'last_dispatch_started_at',
            'last_dispatch_completed_at',
            'last_dispatched_import_count',
            'last_health_check_at',
            'last_maintenance_at',
            'last_recovered_import_count',
            'last_retention_at',
            'last_retention_metadata_count',
            'last_digest_at',
            'health_state',
            'health_snapshot',
        ]);

        if (array_key_exists('health_snapshot', $allowed) && is_array($allowed['health_snapshot'])) {
            $allowed['health_snapshot'] = json_encode(
                $allowed['health_snapshot'],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        }

        $now = now();
        DB::table('storage_purchase_order_import_operations')->insertOrIgnore([
            'operation_key' => self::OPERATION_KEY,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('storage_purchase_order_import_operations')
            ->where('operation_key', self::OPERATION_KEY)
            ->update($allowed + ['updated_at' => $now]);
    }
}
