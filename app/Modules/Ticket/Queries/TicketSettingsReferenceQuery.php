<?php

namespace App\Modules\Ticket\Queries;

use App\Models\Core\User;
use App\Modules\Integration\Support\DomainApiRead;
use App\Modules\Ticket\Models\TicketAssignmentSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/** Administrative form references have fixed models/fields and never serialize mailbox credentials. */
class TicketSettingsReferenceQuery
{
    public function paginate(Request $request, string $catalog, bool $ownProfile = false)
    {
        if ($ownProfile) {
            abort_unless(in_array($catalog, ['categories', 'tags'], true), 404);
        }
        $definitions = [
            'email-accounts' => [\App\Modules\Email\Models\EmailAccount::class, ['id', 'address'], 'address', 'is_active'],
            'users' => [User::class, ['id', 'name', 'email'], 'name', 'status'],
            'technicians-without-profiles' => [User::class, ['id', 'name', 'email'], 'name', 'status'],
            'clients' => [\App\Models\Clients\Client::class, ['id', 'name', 'client_number'], 'name', 'active'],
            'contacts' => [\App\Models\Clients\ClientUser::class, ['id', 'name', 'email', 'client_site_id'], 'name', 'active'],
            'categories' => [\App\Modules\Taxonomy\Models\Category::class, ['id', 'name', 'type'], 'name', 'is_active'],
            'tags' => [\App\Modules\Taxonomy\Models\Tag::class, ['id', 'name', 'slug'], 'name', 'active'],
            'queues' => [\App\Modules\Ticket\Models\TicketQueue::class, ['id', 'name'], 'name', 'is_active'],
            'statuses' => [\App\Modules\Ticket\Models\TicketStatus::class, ['id', 'name'], 'name', 'is_active'],
            'priorities' => [\App\Modules\Ticket\Models\TicketPriority::class, ['id', 'name', 'level'], 'name', 'is_active'],
            'types' => [\App\Modules\Ticket\Models\TicketType::class, ['id', 'name'], 'name', 'is_active'],
            'slas' => [\App\Modules\Commercial\Models\Sla\Sla::class, ['id', 'name', 'description', 'is_default'], 'name', null],
        ];
        abort_unless(isset($definitions[$catalog]), 404);
        $request->validate(['q' => ['sometimes', 'string', 'max:255']]);
        [$model, $fields, $name, $active] = $definitions[$catalog];
        $query = $model::query();
        if ($active) {
            $query->where($active, $active === 'status' ? User::STATUS_ACTIVE : true);
        }
        if ($catalog === 'categories') {
            $query->forTickets();
        }
        if ($catalog === 'technicians-without-profiles') {
            $query->whereNotIn('id', TicketAssignmentSetting::query()->select('user_id'));
        }
        if ($request->filled('q')) {
            $query->where($name, 'like', '%'.$request->input('q').'%');
        }

        return $query->orderBy($name)->orderBy('id')->paginate(DomainApiRead::pageSize($request), $fields)
            ->through(fn ($row) => Arr::only($row->attributesToArray(), $fields))->withQueryString();
    }
}
