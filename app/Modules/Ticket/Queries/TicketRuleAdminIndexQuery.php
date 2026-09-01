<?php

namespace App\Modules\Ticket\Queries;

use App\Modules\Ticket\Models\TicketRule;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

final class TicketRuleAdminIndexQuery
{
    /** @return LengthAwarePaginator<int, TicketRule> */
    public function get(Request $request): LengthAwarePaginator
    {
        $sort = in_array($request->string('sort')->toString(), [
            'name', 'weight', 'published_at', 'draft_updated_at', 'updated_at',
        ], true)
            ? $request->string('sort')->toString()
            : 'weight';
        $direction = $request->string('direction')->toString() === 'desc' ? 'desc' : 'asc';

        return TicketRule::query()
            ->with(['publishedVersion', 'latestExecution'])
            ->withCount([
                'executions',
                'executions as failed_executions_count' => fn ($query) => $query->where('status', 'failed'),
            ])
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = trim($request->string('search')->toString());
                $query->where(function ($query) use ($term): void {
                    $query->where('name', 'like', '%'.$term.'%')
                        ->orWhere('description', 'like', '%'.$term.'%');
                });
            })
            ->when($request->filled('lifecycle'), fn ($query) => $query->where(
                'lifecycle_status',
                $request->string('lifecycle')->toString(),
            ))
            ->when($request->string('state')->toString() === 'active', fn ($query) => $query->where('is_active', true))
            ->when($request->string('state')->toString() === 'inactive', fn ($query) => $query->where('is_active', false))
            ->when($request->string('state')->toString() === 'draft', fn ($query) => $query->whereNotNull('draft_payload_json'))
            ->orderBy($sort, $direction)
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString();
    }
}
