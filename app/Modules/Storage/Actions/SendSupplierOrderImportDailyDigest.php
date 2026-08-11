<?php

namespace App\Modules\Storage\Actions;

use App\Modules\Storage\Models\PurchaseOrderAutomationPolicy;
use App\Modules\Storage\Models\PurchaseOrderImport;
use App\Modules\Storage\Notifications\SupplierOrderImportDailyDigestNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class SendSupplierOrderImportDailyDigest
{
    public function __construct(
        private readonly PublishSupplierOrderImportOperationalAlert $alerts,
        private readonly UpdateSupplierOrderImportOperationalState $operationalState,
    ) {}

    /**
     * Send at most one per-user digest for the previous local calendar day.
     */
    public function handle(?CarbonImmutable $at = null): ?int
    {
        $at ??= CarbonImmutable::now();
        $policy = PurchaseOrderAutomationPolicy::query()->where('is_current', true)->first();
        $this->operationalState->handle(['last_digest_at' => $at]);
        if (! $policy?->daily_digest_enabled) {
            return null;
        }

        $end = $at->startOfDay();
        $start = $end->subDay();
        $base = PurchaseOrderImport::query()
            ->where(function ($query) use ($start, $end): void {
                $query->where(function ($processed) use ($start, $end): void {
                    $processed->where('processed_at', '>=', $start)
                        ->where('processed_at', '<', $end);
                })->orWhere(function ($created) use ($start, $end): void {
                    $created->whereNull('processed_at')
                        ->where('created_at', '>=', $start)
                        ->where('created_at', '<', $end);
                });
            });
        $statusCounts = (clone $base)
            ->select('status', DB::raw('COUNT(*) AS aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
        $total = array_sum($statusCounts);
        if ($total === 0) {
            return null;
        }
        $reasonCounts = (clone $base)
            ->whereNotNull('reason_code')
            ->select('reason_code', DB::raw('COUNT(*) AS aggregate'))
            ->groupBy('reason_code')
            ->orderByDesc('aggregate')
            ->limit(10)
            ->pluck('aggregate', 'reason_code')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
        $period = $start->toDateString();
        $alertId = $this->alerts->handle([
            'identity' => 'daily_digest:'.$period,
            'type' => 'daily_digest',
            'severity' => 'info',
            'reason_code' => 'daily_digest',
            'title' => 'Supplier-order import digest '.$period,
            'summary' => $total.' supplier-order import(s) were recorded for '.$period.'.',
            'context' => [
                'period' => $period,
                'total' => $total,
                'status_counts' => $statusCounts,
                'reason_counts' => $reasonCounts,
            ],
        ], false);
        $this->alerts->deliver($alertId, new SupplierOrderImportDailyDigestNotification(
            alertId: $alertId,
            period: $period,
            total: $total,
            statusCounts: $statusCounts,
            reasonCounts: $reasonCounts,
        ));

        return $alertId;
    }
}
