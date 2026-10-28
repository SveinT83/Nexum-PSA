<?php

namespace App\Modules\Ticket\Queries;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Models\Tech\Work\Assets\Asset;
use App\Modules\Calendar\Models\Calendar;
use App\Modules\Commercial\Models\Sla\Sla;
use App\Modules\Integration\Support\DomainApiRead;
use App\Modules\Storage\Models\Item;
use App\Modules\Task\Models\TaskTemplateGroup;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Taxonomy\Models\Tag;
use App\Modules\Ticket\Models\TicketPriority;
use App\Modules\Ticket\Models\TicketQueue;
use App\Modules\Ticket\Models\TicketStatus;
use App\Modules\Ticket\Models\TicketType;
use App\Modules\Ticket\Models\TicketWorkflow;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/** Explicit reference catalogs mirror Ticket form ownership and never expose arbitrary model fields. */
final class TicketApiReferenceQuery
{
    public const CATALOGS = ['queues', 'statuses', 'priorities', 'types', 'categories', 'tags', 'clients', 'sites', 'contacts', 'assets', 'technicians', 'calendars', 'task-templates', 'workflows', 'sla-policies', 'storage-items'];

    public function paginate(Request $request, string $catalog)
    {
        abort_unless(in_array($catalog, self::CATALOGS, true), 404);
        $size = DomainApiRead::pageSize($request);
        $data = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'client_id' => ['sometimes', 'nullable', 'integer', Rule::exists('clients', 'id')->where('active', true)],
            'site_id' => ['sometimes', 'nullable', 'integer', 'exists:client_sites,id'],
            'contact_id' => ['sometimes', 'nullable', 'integer', 'exists:client_users,id'],
        ]);
        $clientId = $data['client_id'] ?? null;
        $siteId = $data['site_id'] ?? null;
        $contact = empty($data['contact_id']) ? null : ClientUser::query()->with('site')->where('active', true)->findOrFail($data['contact_id']);
        abort_if($siteId && (! $clientId || ! ClientSite::query()->where('client_id', $clientId)->whereKey($siteId)->exists()), 422, 'The selected site does not belong to the selected Client.');
        abort_if($contact && (! $clientId || (int) $contact->site?->client_id !== (int) $clientId), 422, 'The selected Contact does not belong to the selected Client.');
        if ($contact) {
            $siteId = $contact->client_site_id;
        }

        [$query, $fields, $search] = match ($catalog) {
            'queues' => [TicketQueue::query()->where('is_active', true), ['id', 'name', 'slug', 'sort_order'], 'name'],
            'statuses' => [TicketStatus::query()->where('is_active', true), ['id', 'name', 'slug', 'is_closed', 'is_default', 'sort_order'], 'name'],
            'priorities' => [TicketPriority::query()->where('is_active', true), ['id', 'name', 'level', 'sort_order'], 'name'],
            'types' => [TicketType::query()->where('is_active', true), ['id', 'name', 'slug', 'sort_order'], 'name'],
            'categories' => [Category::query()->active()->where(fn ($q) => $q->where('type', Category::TYPE_TICKET)->orWhereNull('type')), ['id', 'name', 'type'], 'name'],
            'tags' => [Tag::query()->where('active', true), ['id', 'name', 'slug'], 'name'],
            'clients' => [Client::query()->where('active', true), ['id', 'name', 'client_number'], 'name'],
            'sites' => [ClientSite::query()->where('client_id', $clientId)->when(! $clientId, fn ($q) => $q->whereRaw('1 = 0')), ['id', 'client_id', 'name', 'is_default'], 'name'],
            'contacts' => [ClientUser::query()->where('active', true)->whereHas('site', fn ($q) => $q->where('client_id', $clientId))->when(! $clientId, fn ($q) => $q->whereRaw('1 = 0')), ['id', 'client_site_id', 'name', 'email', 'phone'], 'name'],
            'assets' => [Asset::query()->where('client_id', $clientId)->when($contact, fn ($q) => $q->where(fn ($group) => $group->where('user_id', $contact->id)->orWhere('site_id', $siteId)))->when($siteId && ! $contact, fn ($q) => $q->where('site_id', $siteId)), ['id', 'name', 'client_id', 'site_id', 'user_id', 'hostname', 'serial_number', 'ip_address'], 'name'],
            'technicians' => [$this->technicians($request), ['id', 'name', 'email'], 'name'],
            'calendars' => [Calendar::query()->where('owner_type', User::class)->where('owner_id', $request->user()->id), ['id', 'name', 'timezone', 'is_active'], 'name'],
            'task-templates' => [TaskTemplateGroup::query()->where('is_active', true), ['id', 'name'], 'name'],
            'workflows' => [TicketWorkflow::query()->where('is_active', true), ['id', 'name', 'published_version_id', 'is_default'], 'name'],
            'sla-policies' => [Sla::query(), ['id', 'name'], 'name'],
            'storage-items' => [Item::query()->where('status', 'active'), ['id', 'name', 'sku', 'warehouse_id', 'box_id', 'sale_price', 'can_be_ordered', 'requires_customer_quote', 'short_description'], 'name'],
        };
        if (filled($data['q'] ?? null)) {
            $query->where($search, 'like', '%'.$data['q'].'%');
        }

        return $query->orderBy($search)->orderBy('id')->paginate($size, $fields)->withQueryString()
            ->through(fn ($row) => \Illuminate\Support\Arr::only($row->attributesToArray(), $fields));
    }

    private function technicians(Request $request)
    {
        $roles = Role::query()->whereIn('name', ['ticket.admin', 'ticket.agent', 'ticket.view', 'tech.admin', 'Superuser', 'Admin', 'Tech'])->pluck('name')->all();
        $query = User::query()->where('status', User::STATUS_ACTIVE);
        if ($roles !== []) {
            $query->where(fn ($q) => $q->whereHas('roles', fn ($role) => $role->whereIn('name', $roles))->orWhere('id', $request->user()->id));
        }

        return $query;
    }
}
