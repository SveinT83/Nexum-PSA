<?php

namespace App\Modules\System\Commands;

use App\Modules\System\Services\RuntimeHeartbeat;
use Illuminate\Console\Command;

final class ReconcileRuntimeProbeCommand extends Command
{
    protected $signature = 'system:runtime-probe-reconcile {queue} {probe} {--confirm-not-delivered}';

    protected $description = 'Acknowledge verified non-delivery of one exact probe without executing or deleting jobs';

    public function handle(RuntimeHeartbeat $heartbeat): int
    {
        if (! $this->option('confirm-not-delivered')) {
            $this->line('{"status":"blocked","reason_code":"explicit_non_delivery_confirmation_required"}');

            return self::FAILURE;
        }

        $changed = $heartbeat->reconcile(
            (string) $this->argument('queue'), (string) $this->argument('probe'),
        );
        $this->line(json_encode([
            'status' => $changed ? 'reconciled' : 'blocked',
            'reason_code' => $changed ? 'pending_probe_reconciled_without_delivery_claim' : 'current_probe_not_matched',
        ], JSON_THROW_ON_ERROR));

        return $changed ? self::SUCCESS : self::FAILURE;
    }
}
