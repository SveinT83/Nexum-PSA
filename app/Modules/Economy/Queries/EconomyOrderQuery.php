<?php

namespace App\Modules\Economy\Queries;

use App\Modules\Economy\Models\EconomyOrder;
use App\Modules\Economy\Support\EconomyInput;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** One bounded staff query contract for lists, counters and UI consumers. */
class EconomyOrderQuery
{
    public static function filters(Request $request): array
    {
        return EconomyInput::validate($request, [
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', 'in:draft,ready,approved,exported,manual_invoiced,cancelled'],
            'client_id' => ['sometimes', 'nullable', 'integer', 'exists:clients,id'],
            'period_start' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'period_end' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:period_start'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1'],
            'sort' => ['sometimes', 'in:id,order_number,period_start,period_end,status,created_at'],
            'direction' => ['sometimes', 'in:asc,desc'],
        ]);
    }

    public static function make(array $filters): Builder
    {
        return EconomyOrder::query()
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['client_id'] ?? null, fn ($q, $v) => $q->where('client_id', $v))
            ->when($filters['period_start'] ?? null, fn ($q, $v) => $q->whereDate('period_end', '>=', $v))
            ->when($filters['period_end'] ?? null, fn ($q, $v) => $q->whereDate('period_start', '<=', $v))
            ->when($filters['q'] ?? null, fn ($q, $v) => $q->where(fn ($q) => $q->where('order_number', 'like', '%'.$v.'%')->orWhereHas('client', fn ($q) => $q->where('name', 'like', '%'.$v.'%'))));
    }

    public static function stats(Builder $query): array
    {
        $result = array_fill_keys(['draft', 'ready', 'approved', 'exported', 'manual_invoiced', 'cancelled'], 0);
        foreach ((clone $query)->selectRaw('status, COUNT(*) AS aggregate')->groupBy('status')->get() as $row) {
            $result[$row->status] = (int) $row->aggregate;
        }

        return $result;
    }
}
