<?php

namespace App\Modules\Warroom\Support;

use App\Models\Core\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/** Source availability never substitutes zero for denied or unavailable data. */
class WarroomSources
{
    public const SOURCES = [
        'tickets' => ['ticket.view', ['tickets' => ['id', 'subject', 'ticket_key', 'closed_at', 'owner_id', 'is_unread', 'resolve_due_at', 'updated_at'], 'ticket_statuses' => ['id', 'is_closed']]],
        'tasks' => ['task.view', ['tasks' => ['id', 'title', 'completed_at', 'assigned_to', 'due_at', 'scheduled_start_at', 'status_id'], 'task_statuses' => ['id', 'is_done', 'is_cancelled']]],
        'calendar' => ['calendar.view', ['calendar_events' => ['id', 'calendar_id', 'starts_at', 'ends_at', 'status'], 'calendars' => ['id', 'is_active']]],
        'assets' => ['asset.view', ['asset_alerts' => ['id', 'title', 'status', 'resolved_at', 'last_seen_at'], 'assets' => ['id', 'is_managed']]],
        'mail' => ['email.inbox_view', ['email_messages' => ['id', 'account_id', 'ticket_id', 'received_at'], 'email_accounts' => ['id', 'account_kind', 'owner_id', 'is_active'],
            'email_account_user_grants' => ['user_id', 'can_view'],
            'email_mailbox_placements' => ['account_id', 'email_message_id', 'local_state', 'provider_missing_at']]],
        'clients' => ['client.view', ['clients' => ['id']]],
        'contracts' => ['commercial.contract_manage', ['contracts' => ['id', 'end_date']]],
        'sales' => ['sales.view', ['sales_opportunities' => ['id', 'status']]],
        'economy' => ['economy.order_manage', ['economy_orders' => ['id', 'status']]],
        'storage' => ['storage.view', ['storage_items' => ['id'], 'storage_reservations' => ['id', 'status']]],
        'knowledge' => ['knowledge.view', ['articles' => ['id']]],
        'integrations' => ['integration.view', ['integrations' => ['id', 'name', 'type', 'is_healthy', 'last_error', 'last_sync_at']]],
        'nextcloud' => ['nextcloud.connection_manage', ['nextcloud_connections' => ['id', 'is_active', 'health_status']]],
        'notifications' => ['notification.manage_channels', ['notification_channels' => ['id', 'is_enabled']]],
    ];

    public function states(User $actor): array
    {
        $states = [];
        foreach (self::SOURCES as $source => [$permission, $tables]) {
            $states[$source] = 'inaccessible';
            // Mail's own content scope does not use the legacy empty-Admin-role fallback.
            $permitted = $source === 'mail' ? $actor->can($permission) : WarroomAccess::allows($actor, $permission);
            if (! $permitted) {
                continue;
            }
            $states[$source] = 'available';
            try {
                foreach ($tables as $table => $columns) {
                    if (! Schema::hasTable($table) || ! Schema::hasColumns($table, $columns)) {
                        $states[$source] = 'unavailable';
                        break;
                    }
                }
            } catch (QueryException|\PDOException $error) {
                $states[$source] = 'unavailable';
            }
        }

        return $states;
    }

    /** Use native source permissions for navigation; links never execute source actions. */
    public function links(User $actor, bool $personal = false): array
    {
        $definitions = $personal ? [
            ['New ticket', 'tech.tickets.create', 'ticket.create', 'bi-ticket-detailed'],
            ['New task', 'tech.tasks.create', 'task.create', 'bi-check2-square'],
            ['Calendar', 'tech.calendar.index', 'calendar.view', 'bi-calendar3'],
            ['Mail', 'tech.mail.index', 'email.inbox_view', 'bi-inbox'],
        ] : [
            ['Ticket command', 'tech.tickets.index', 'ticket.view', 'bi-ticket-detailed'],
            ['Client operations', 'tech.clients.index', 'client.view', 'bi-buildings'],
            ['Assets', 'tech.assets.index', 'asset.view', 'bi-hdd-network'],
            ['Mail', 'tech.mail.index', 'email.inbox_view', 'bi-inbox'],
            ['Economy', 'tech.economy.orders.index', 'economy.order_manage', 'bi-receipt'],
            ['Knowledge', 'tech.knowledge.index', 'knowledge.view', 'bi-journal-text'],
            ['My Day', 'tech.my-day.index', 'warroom.view', 'bi-calendar2-check'],
        ];
        $links = [];
        foreach ($definitions as [$label, $route, $permission, $icon]) {
            if (Route::has($route) && WarroomAccess::allows($actor, $permission)) {
                $links[] = compact('label', 'route', 'icon') + ['href' => route($route)];
            }
        }

        return $links;
    }

    /** Keep the existing header shortcuts discoverable without duplicating browser controls. */
    public function workdayLinks(User $actor): array
    {
        if (! app(\App\Modules\Workday\Support\WorkdaySettings::class)->enabled()) {
            return [];
        }
        $links = [];
        foreach ([
            ['My workdays', 'tech.workdays.index', 'workday.view_own', 'bi-clock'],
            ['My absences', 'tech.absences.index', 'workday.absence_view_own', 'bi-calendar-x'],
        ] as [$label, $route, $permission, $icon]) {
            if (Route::has($route) && WarroomAccess::allows($actor, $permission)) {
                $links[] = compact('label', 'route', 'icon') + ['href' => route($route)];
            }
        }

        return $links;
    }

    public function url(string $name, array $parameters = []): ?string
    {
        return Route::has($name) ? route($name, $parameters) : null;
    }
}
