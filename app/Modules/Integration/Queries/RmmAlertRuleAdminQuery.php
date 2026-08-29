<?php

namespace App\Modules\Integration\Queries;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Models\Tech\Work\Assets\Asset;
use App\Modules\Integration\Models\RmmAlertRule;
use App\Modules\Integration\Models\RmmAlertRuleExecution;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Ticket\Models\TicketPriority;
use App\Modules\Ticket\Models\TicketQueue;
use App\Modules\Ticket\Models\TicketStatus;
use App\Modules\Ticket\Models\TicketType;

class RmmAlertRuleAdminQuery
{
    /** @return array<string, mixed> */
    public function indexData(): array
    {
        return [
            'rules' => RmmAlertRule::query()
                ->with('latestExecution')
                ->withCount('executions')
                ->orderBy('priority')
                ->orderBy('id')
                ->get(),
            'executions' => RmmAlertRuleExecution::query()
                ->with(['occurrence', 'workItems'])
                ->latest('id')
                ->paginate(25, ['*'], 'executions'),
        ];
    }

    /** @return array<string, mixed> */
    public function formData(?RmmAlertRule $rule = null): array
    {
        return [
            'rule' => $rule,
            'formConditions' => old('conditions', $rule?->conditions ?? []),
            'formActions' => old('actions', $rule?->actions ?? [['type' => 'create_ticket']]),
            'clients' => Client::query()->orderBy('name')->get(['id', 'name', 'active']),
            'assets' => Asset::query()->orderBy('name')->get(['id', 'name', 'hostname', 'client_id']),
            'queues' => TicketQueue::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'ticketTypes' => TicketType::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'priorities' => TicketPriority::query()->where('is_active', true)->orderBy('level')->get(['id', 'name']),
            'categories' => Category::query()->forTickets()->active()->orderBy('name')->get(['id', 'name']),
            'users' => User::query()->where('status', User::STATUS_ACTIVE)->where('is_system_actor', false)
                ->orderBy('name')->get(['id', 'name']),
            'reopenStatuses' => TicketStatus::query()->where('is_active', true)->where('is_closed', false)
                ->orderBy('sort_order')->get(['id', 'name']),
        ];
    }
}
