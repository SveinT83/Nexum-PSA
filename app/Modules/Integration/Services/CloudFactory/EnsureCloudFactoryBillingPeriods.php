<?php

namespace App\Modules\Integration\Services\CloudFactory;

use App\Modules\Integration\Models\CloudFactory\BillingPeriod;
use App\Modules\Integration\Models\CloudFactory\Subscription;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class EnsureCloudFactoryBillingPeriods
{
    public function handle(CarbonInterface $periodStart, CarbonInterface $periodEnd): int
    {
        $periodStart = Carbon::parse($periodStart)->startOfDay();
        $periodEnd = Carbon::parse($periodEnd)->endOfDay();
        $count = 0;

        Subscription::query()
            ->whereIn('status', ['active', 'enabled', 'provisioned', 'committed'])
            ->where('billing_state', 'confirmed')
            ->whereNotNull('contract_item_id')
            ->whereNotNull('unit_sale_price')
            ->where(function ($query) use ($periodEnd): void {
                $query->whereNull('commitment_start_date')
                    ->orWhereDate('commitment_start_date', '<=', $periodEnd->toDateString());
            })
            ->where(function ($query) use ($periodStart): void {
                $query->whereNull('commitment_end_date')
                    ->orWhereDate('commitment_end_date', '>=', $periodStart->toDateString());
            })
            ->get()
            ->each(function (Subscription $subscription) use ($periodStart, $periodEnd, &$count): void {
                $period = BillingPeriod::query()
                    ->where('subscription_id', $subscription->id)
                    ->whereDate('period_start', $periodStart->toDateString())
                    ->whereDate('period_end', $periodEnd->toDateString())
                    ->first() ?? new BillingPeriod([
                        'subscription_id' => $subscription->id,
                        'period_start' => $periodStart->toDateString(),
                        'period_end' => $periodEnd->toDateString(),
                    ]);

                $period->forceFill([
                    'client_id' => $subscription->client_id,
                    'contract_item_id' => $subscription->contract_item_id,
                    'quantity' => $subscription->quantity,
                    'unit_price_ex_vat' => $subscription->unit_sale_price,
                    'currency' => $subscription->currency,
                    'status' => 'confirmed',
                    'confirmed_at' => $subscription->last_synced_at ?? now(),
                    'metadata' => [
                        'provider_family' => $subscription->provider_family,
                        'provider_status' => $subscription->status,
                        'external_subscription_id' => $subscription->external_subscription_id,
                    ],
                ])->save();
                $count++;
            });

        return $count;
    }
}
