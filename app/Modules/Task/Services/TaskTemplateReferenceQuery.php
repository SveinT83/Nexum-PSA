<?php

namespace App\Modules\Task\Services;

use App\Modules\Integration\Support\DomainApiRead;
use Illuminate\Http\Request;

/** Expose bounded form options with explicit fields and active-record filters. */
class TaskTemplateReferenceQuery
{
    public function paginate(Request $request, string $catalog)
    {
        $definitions = [
            'statuses' => [\App\Modules\Task\Models\TaskStatus::class, 'is_active', ['id', 'name', 'is_done', 'is_cancelled']],
            'queues' => [\App\Modules\Ticket\Models\TicketQueue::class, 'is_active', ['id', 'name']],
            'priorities' => [\App\Modules\Ticket\Models\TicketPriority::class, 'is_active', ['id', 'name', 'level']],
            'categories' => [\App\Modules\Taxonomy\Models\Category::class, 'is_active', ['id', 'name', 'type']],
            'tags' => [\App\Modules\Taxonomy\Models\Tag::class, 'active', ['id', 'name', 'slug']],
            'clients' => [\App\Models\Clients\Client::class, 'active', ['id', 'name', 'client_number']],
            'users' => [\App\Models\Core\User::class, 'status', ['id', 'name']],
        ];
        abort_unless(isset($definitions[$catalog]), 404);
        $request->validate(['q' => ['sometimes', 'string', 'max:255']]);
        [$model, $active, $fields] = $definitions[$catalog];
        $query = $model::query()->where($active, $active === 'status' ? \App\Models\Core\User::STATUS_ACTIVE : true);
        if ($request->filled('q')) {
            $query->where('name', 'like', '%'.$request->input('q').'%');
        }

        return $query->orderBy('name')->orderBy('id')->paginate(DomainApiRead::pageSize($request), $fields)->through(fn ($row) => \Illuminate\Support\Arr::only($row->attributesToArray(), $fields))->withQueryString();
    }
}
