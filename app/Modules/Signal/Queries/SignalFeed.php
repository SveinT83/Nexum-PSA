<?php

namespace App\Modules\Signal\Queries;

use App\Modules\Signal\Models\Signal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** One query contract for the browser feed and official API. */
class SignalFeed
{
    public function query(Request $request): Builder
    {
        $request->validate([
            'range' => ['nullable', 'in:7,30,90,custom,all'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'q' => ['nullable', 'string', 'max:255'],
            'source_domain' => ['nullable', 'string', 'max:80'],
            'signal_type' => ['nullable', 'string', 'max:100'],
            'severity' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'string', 'max:50'],
            'sort' => ['nullable', 'in:signal_type,source_domain,severity,confidence,status,executions_count,occurred_at'],
            'direction' => ['nullable', 'in:asc,desc'],
        ]);

        $range = (string) $request->query('range', '30');
        $allowedRanges = ['7', '30', '90', 'custom', 'all'];
        if (! in_array($range, $allowedRanges, true)) {
            $range = '30';
        }

        $sort = (string) $request->query('sort', 'occurred_at');
        $allowedSorts = ['signal_type', 'source_domain', 'severity', 'confidence', 'status', 'executions_count', 'occurred_at'];
        if (! in_array($sort, $allowedSorts, true)) {
            $sort = 'occurred_at';
        }

        $direction = strtolower((string) $request->query('direction', 'desc')) === 'asc' ? 'asc' : 'desc';
        $query = Signal::query()->with(['contact', 'client'])->withCount('executions');

        if ($range === 'custom') {
            $query
                ->when($request->date('from'), fn (Builder $builder, $from) => $builder->where('occurred_at', '>=', $from->startOfDay()))
                ->when($request->date('to'), fn (Builder $builder, $to) => $builder->where('occurred_at', '<=', $to->endOfDay()));
        } elseif ($range !== 'all') {
            $query->where('occurred_at', '>=', now()->subDays((int) $range));
        }

        $query
            ->when(trim((string) $request->query('q')), function (Builder $builder, string $search): void {
                $builder->where(function (Builder $nested) use ($search): void {
                    $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
                    $nested
                        ->where('summary', 'like', $like)
                        ->orWhere('signal_type', 'like', $like)
                        ->orWhere('source_domain', 'like', $like)
                        ->orWhereHas('contact', fn (Builder $contact) => $contact->where('display_name', 'like', $like))
                        ->orWhereHas('client', fn (Builder $client) => $client->where('name', 'like', $like));
                });
            })
            ->when($request->query('source_domain'), fn (Builder $builder, string $value) => $builder->where('source_domain', $value))
            ->when($request->query('signal_type'), fn (Builder $builder, string $value) => $builder->where('signal_type', $value))
            ->when($request->query('severity'), fn (Builder $builder, string $value) => $builder->where('severity', $value))
            ->when($request->query('status'), fn (Builder $builder, string $value) => $builder->where('status', $value))
            ->orderBy($sort, $direction)
            ->orderBy('id', $direction);

        return $query;
    }
}
