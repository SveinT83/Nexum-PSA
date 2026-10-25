<?php

namespace App\Modules\Economy\Queries;

use App\Modules\Commercial\Models\Contracts\ClientContractTimeConsumption;
use App\Modules\Economy\Actions\EnsureEconomyDefaults;
use App\Modules\Economy\Support\EconomyMutex;
use App\Modules\Economy\Support\EconomyRevision;
use App\Modules\Integration\Models\CloudFactory\BillingPeriod;
use App\Modules\Integration\Models\CloudFactory\Subscription;
use App\Modules\Ticket\Models\TicketCostEntry;
use App\Modules\Ticket\Models\TicketTimeEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Pure candidate/readiness preview; it never calls the mutating generator or persists defaults. */
class PreviewEconomyGeneration
{
    public static function dates(array $input): array
    {
        return [Carbon::parse($input['period_start'] ?? now()->startOfMonth())->startOfDay(), Carbon::parse($input['period_end'] ?? now()->endOfMonth())->endOfDay()];
    }

    public function handle(array $input): array
    {
        [$start, $end] = self::dates($input);
        abort_if($end->lt($start), 422, 'The period end must be on or after its start.');
        $settings = app(EnsureEconomyDefaults::class)->effective();
        $time = TicketTimeEntry::query()->where('billable', true)->where('billing_status', 'pending')->where('minutes', '>', 0)
            ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
            ->whereHas('ticket', function ($q) use ($settings) {
                $q->whereNotNull('client_id')->where(fn ($q) => $q->whereNull('close_outcome')->orWhereNotIn('close_outcome', ['customer_declined', 'cancelled', 'no_sale']));
                if (! $settings->include_unresolved_ticket_time_in_period_close) {
                    $q->where(function ($q) use ($settings) {
                        $q->whereRaw('1 = 0');
                        if ($settings->create_orders_from_closed_ticket_time) {
                            $q->orWhereHas('status', fn ($q) => $q->where('is_closed', true));
                        }
                        if ($settings->create_orders_from_resolved_ticket_time) {
                            $q->orWhereNotNull('resolved_at');
                        }
                    });
                }
            });
        $cost = TicketCostEntry::query()->where('billing_status', 'pending')->where('status', 'picked')->whereBetween('created_at', [$start, $end])->where('quantity', '>', 0)->where('unit_price_ex_vat', '>=', 0)
            ->whereHas('ticket', fn ($q) => $q->whereNotNull('client_id')->where(fn ($q) => $q->whereNull('close_outcome')->orWhereNotIn('close_outcome', ['customer_declined', 'cancelled', 'no_sale'])));
        if (! $settings->create_orders_from_picked_ticket_costs) {
            $cost->whereRaw('1 = 0');
        }
        $quick = ClientContractTimeConsumption::query()->where('overused_minutes', '>', 0)->where('rate_amount_ex_vat', '>', 0)->whereBetween('work_date', [$start->toDateString(), $end->toDateString()]);
        $period = BillingPeriod::query()->where('status', 'confirmed')->whereDate('period_start', $start)->whereDate('period_end', $end);
        $subscription = Subscription::query()->whereIn('status', ['active', 'enabled', 'provisioned', 'committed'])->where('billing_state', 'confirmed')->whereNotNull('contract_item_id')->whereNotNull('unit_sale_price')
            ->where(fn ($q) => $q->whereNull('commitment_start_date')->orWhereDate('commitment_start_date', '<=', $end))
            ->where(fn ($q) => $q->whereNull('commitment_end_date')->orWhereDate('commitment_end_date', '>=', $start));
        $counts = ['ticket_time' => $time->count(), 'ticket_costs' => $cost->count(), 'quick_timebank_overuse' => $quick->count(), 'confirmed_billing_periods' => $period->count(), 'confirmed_subscriptions' => $subscription->count()];
        $clients = array_merge($time->with('ticket')->get()->map(fn ($e) => $e->ticket->client_id)->all(), $cost->with('ticket')->get()->map(fn ($e) => $e->ticket->client_id)->all(), $quick->pluck('client_id')->all(), $period->pluck('client_id')->all(), $subscription->pluck('client_id')->all());
        $hash = hash_init('sha256');
        hash_update($hash, EconomyRevision::hash([$start->toDateString(), $end->toDateString(), $settings->getAttributes(), EconomyMutex::revision()]));
        // Hash dependency bytes in memory only: no source content, bearer values or settings secrets are returned.
        $models = [new TicketTimeEntry, new TicketCostEntry, new ClientContractTimeConsumption, new BillingPeriod, new Subscription, new \App\Modules\Ticket\Models\Ticket, new \App\Modules\Ticket\Models\TicketStatus, new \App\Modules\Ticket\Models\TicketTimeEntryAllocation, new \App\Modules\Commercial\Models\Contracts\Contracts, new \App\Modules\Commercial\Models\Contracts\ContractItem, new \App\Modules\Commercial\Models\Services\Services, new \App\Modules\Commercial\Models\TimeRate, new \App\Modules\Economy\Models\EconomyOrder, new \App\Modules\Economy\Models\EconomyOrderLine];
        foreach ($models as $model) {
            $table = $model->getTable();
            if (Schema::hasTable($table)) {
                hash_update($hash, $table);
                foreach (DB::table($table)->orderBy('id')->cursor() as $row) {
                    hash_update($hash, json_encode((array) $row, JSON_THROW_ON_ERROR));
                }
            }
        }
        $clients = array_values(array_unique(array_map('intval', $clients)));
        sort($clients);

        return ['period_start' => $start->toDateString(), 'period_end' => $end->toDateString(), 'scope' => 'installation', 'candidate_counts' => $counts, 'candidate_client_ids' => array_slice($clients, 0, 100), 'candidate_clients_count' => count($clients), 'clients_truncated' => count($clients) > 100, 'counts_are' => 'candidates_before_contract_allocation_and_existing_source_deduplication', 'preview_hash' => hash_final($hash), 'settings_revision' => EconomyRevision::settings(), 'external_invoice_or_stock_action' => false];
    }
}
