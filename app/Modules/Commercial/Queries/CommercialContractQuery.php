<?php

namespace App\Modules\Commercial\Queries;

use App\Models\Clients\Client;
use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Commercial\Models\Sla\Sla;
use Illuminate\Http\Request;

/** Current Contract list query and stable financial sorts shared by web and API. */
class CommercialContractQuery
{
    public function handle(Request $request)
    {
        $sort = $request->input('sort', 'id');
        $direction = $request->input('direction') === 'asc' ? 'asc' : 'desc';
        $sortableColumns = ['id', 'client', 'status', 'start_date', 'end_date', 'monthly_price', 'yearly_profit'];

        if (! in_array($sort, $sortableColumns, true)) {
            $sort = 'id';
        }

        $contractsQuery = Contracts::query()
            ->select('contracts.*')
            ->selectRaw('COALESCE(contracts.total_monthly_amount, 0) as monthly_price_sort')
            ->selectRaw($this->yearlyProfitSortExpression().' as yearly_profit_sort')
            ->with(['client', 'sla', 'items.service.costRelations.cost'])
            ->when($request->filled('q'), function ($query) use ($request): void {
                $search = '%'.$request->string('q')->trim()->toString().'%';
                $query->where(function ($query) use ($search): void {
                    $query->where('contracts.id', 'like', $search)
                        ->orWhere('contracts.description', 'like', $search)
                        ->orWhere('contracts.approval_status', 'like', $search)
                        ->orWhereHas('client', fn ($clientQuery) => $clientQuery->where('name', 'like', $search));
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('approval_status', $request->input('status')))
            ->when($request->filled('client_id'), fn ($query) => $query->where('client_id', $request->integer('client_id')))
            ->when($request->filled('period'), function ($query) use ($request): void {
                match ($request->input('period')) {
                    'active' => $query
                        ->whereDate('start_date', '<=', now()->toDateString())
                        ->where(fn ($periodQuery) => $periodQuery->whereNull('end_date')->orWhereDate('end_date', '>=', now()->toDateString())),
                    'future' => $query->whereDate('start_date', '>', now()->toDateString()),
                    'expired' => $query->whereNotNull('end_date')->whereDate('end_date', '<', now()->toDateString()),
                    default => null,
                };
            });

        if ($sort === 'client') {
            $contractsQuery->leftJoin('clients', 'contracts.client_id', '=', 'clients.id')
                ->orderBy('clients.name', $direction)
                ->orderBy('contracts.id', 'desc');
        } elseif ($sort === 'status') {
            $contractsQuery->orderBy('approval_status', $direction)->orderByDesc('id');
        } elseif ($sort === 'monthly_price') {
            $contractsQuery->orderBy('monthly_price_sort', $direction)->orderByDesc('id');
        } elseif ($sort === 'yearly_profit') {
            $contractsQuery->orderBy('yearly_profit_sort', $direction)->orderByDesc('id');
        } else {
            $contractsQuery->orderBy($sort, $direction)->orderByDesc('id');
        }

        $contracts = $contractsQuery->paginate($request->is('api/*') ? $request->integer('per_page', 20) : 20)->withQueryString();

        // Calculate clients without contracts for administrative overview.
        $clientsWithoutContractsCount = Client::whereDoesntHave('contracts')->count();

        return [
            'contracts' => $contracts,
            'clientsWithoutContractsCount' => $clientsWithoutContractsCount,
            'clients' => Client::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
            'statuses' => Contracts::query()->distinct()->orderBy('approval_status')->pluck('approval_status')->filter()->values(),
            'filters' => $request->only(['q', 'status', 'client_id', 'period', 'sort', 'direction']),
            'defaultSla' => Sla::query()->where('is_default', true)->orderBy('name')->first(),
        ];
    }

    private function yearlyProfitSortExpression(): string
    {
        return <<<SQL
            (
                SELECT COALESCE(SUM(
                    (
                        {$this->contractItemLineTotalExpression('contract_items')}
                        - (
                            COALESCE(
                                contract_items.cost_unit_price,
                                (
                                    SELECT COALESCE(SUM(costs.cost), 0)
                                    FROM cost_relations
                                    INNER JOIN costs ON costs.id = cost_relations.costId
                                    WHERE cost_relations.serviceId = contract_items.service_id
                                )
                            ) * contract_items.quantity
                        )
                    )
                    * CASE contract_items.billing_interval
                        WHEN 'monthly' THEN 12
                        WHEN 'quarterly' THEN 4
                        WHEN 'yearly' THEN 1
                        ELSE 0
                    END
                ), 0)
                FROM contract_items
                WHERE contract_items.contract_id = contracts.id
            )
        SQL;
    }

    private function contractItemLineTotalExpression(string $table): string
    {
        return <<<SQL
            (
                ({$table}.unit_price * {$table}.quantity)
                - CASE
                    WHEN {$table}.discount_value IS NOT NULL AND {$table}.discount_type = 'percent'
                        THEN ({$table}.unit_price * {$table}.quantity) * ({$table}.discount_value / 100)
                    WHEN {$table}.discount_value IS NOT NULL AND {$table}.discount_type = 'amount'
                        THEN CASE
                            WHEN {$table}.discount_value > ({$table}.unit_price * {$table}.quantity)
                                THEN ({$table}.unit_price * {$table}.quantity)
                            ELSE {$table}.discount_value
                        END
                    ELSE 0
                END
            )
        SQL;
    }
}
