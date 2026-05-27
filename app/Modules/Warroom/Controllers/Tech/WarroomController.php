<?php

namespace App\Modules\Warroom\Controllers\Tech;

use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class WarroomController extends Controller
{
    /**
     * Show the fixed v1 operations dashboard.
     */
    public function __invoke(): View
    {
        $openTickets = $this->count('tickets', fn (Builder $query) => $query->whereNull('closed_at'));
        $overdueTickets = $this->count('tickets', fn (Builder $query) => $query
            ->whereNull('closed_at')
            ->whereNotNull('resolve_due_at')
            ->where('resolve_due_at', '<', now()));
        $dueSoonTickets = $this->count('tickets', fn (Builder $query) => $query
            ->whereNull('closed_at')
            ->whereNotNull('resolve_due_at')
            ->whereBetween('resolve_due_at', [now(), now()->addHours(8)]));

        $warroom = [
            'generated_at' => now(),
            'pulse' => [
                [
                    'label' => 'Open tickets',
                    'value' => $openTickets,
                    'tone' => $overdueTickets > 0 ? 'danger' : 'primary',
                    'icon' => 'bi-ticket-detailed',
                    'href' => $this->routeUrl('tech.tickets.index'),
                    'meta' => "{$overdueTickets} overdue / {$dueSoonTickets} due today",
                ],
                [
                    'label' => 'Unread queue',
                    'value' => $this->count('tickets', fn (Builder $query) => $query->where('is_unread', true)->whereNull('closed_at')),
                    'tone' => 'warning',
                    'icon' => 'bi-broadcast',
                    'href' => $this->routeUrl('tech.tickets.index'),
                    'meta' => $this->count('tickets', fn (Builder $query) => $query->whereNull('owner_id')->whereNull('closed_at')).' unassigned',
                ],
                [
                    'label' => 'Asset alerts',
                    'value' => $this->count('asset_alerts', fn (Builder $query) => $query->whereNull('resolved_at')),
                    'tone' => 'danger',
                    'icon' => 'bi-shield-exclamation',
                    'href' => $this->routeUrl('tech.assets.index'),
                    'meta' => $this->count('assets', fn (Builder $query) => $query->where('is_managed', true)).' managed assets',
                ],
                [
                    'label' => 'Inbox triage',
                    'value' => $this->count('email_messages', fn (Builder $query) => $query->whereNull('ticket_id')),
                    'tone' => 'info',
                    'icon' => 'bi-inbox',
                    'href' => $this->routeUrl('tech.inbox.index'),
                    'meta' => $this->count('email_messages', fn (Builder $query) => $query->where('received_at', '>=', now()->subDay())).' last 24h',
                ],
            ],
            'operations' => [
                [
                    'label' => 'Clients',
                    'value' => $this->count('clients'),
                    'href' => $this->routeUrl('tech.clients.index'),
                    'icon' => 'bi-buildings',
                ],
                [
                    'label' => 'Contracts',
                    'value' => $this->count('contracts', fn (Builder $query) => $query->where(function (Builder $inner) {
                        $inner->whereNull('end_date')->orWhere('end_date', '>=', now()->toDateString());
                    })),
                    'href' => $this->routeUrl('tech.contracts.index'),
                    'icon' => 'bi-file-earmark-check',
                ],
                [
                    'label' => 'Sales pipeline',
                    'value' => $this->count('sales_opportunities', fn (Builder $query) => $query->whereNotIn('status', ['won', 'lost', 'closed'])),
                    'href' => $this->routeUrl('tech.sales.index'),
                    'icon' => 'bi-graph-up-arrow',
                ],
                [
                    'label' => 'Economy orders',
                    'value' => $this->count('economy_orders', fn (Builder $query) => $query->whereIn('status', ['draft', 'ready'])),
                    'href' => $this->routeUrl('tech.economy.orders.index'),
                    'icon' => 'bi-receipt',
                ],
                [
                    'label' => 'Storage picks',
                    'value' => $this->count('storage_reservations', fn (Builder $query) => $query->whereIn('status', ['active', 'pending', 'reserved'])),
                    'href' => $this->routeUrl('tech.storage.picking'),
                    'icon' => 'bi-box-seam',
                ],
                [
                    'label' => 'Knowledge pages',
                    'value' => $this->count('articles'),
                    'href' => $this->routeUrl('tech.knowledge.index'),
                    'icon' => 'bi-journal-text',
                ],
            ],
            'system' => [
                'integrations_total' => $this->count('integrations'),
                'integrations_unhealthy' => $this->count('integrations', fn (Builder $query) => $query->where(function (Builder $inner) {
                    $inner->where('is_healthy', false)->orWhereNotNull('last_error');
                })),
                'nextcloud_active' => $this->count('nextcloud_connections', fn (Builder $query) => $query->where('is_active', true)),
                'nextcloud_warnings' => $this->count('nextcloud_connections', fn (Builder $query) => $query
                    ->where('is_active', true)
                    ->whereNotIn('health_status', ['healthy', 'ok'])),
                'notification_channels' => $this->count('notification_channels', fn (Builder $query) => $query->where('is_enabled', true)),
            ],
            'latest_tickets' => $this->latest('tickets', ['id', 'ticket_key', 'subject', 'client_id', 'owner_id', 'is_unread', 'resolve_due_at', 'updated_at'], 6, fn (Builder $query) => $query->whereNull('closed_at')),
            'latest_alerts' => $this->latest('asset_alerts', ['id', 'title', 'status', 'last_seen_at', 'resolved_at'], 5, fn (Builder $query) => $query->whereNull('resolved_at')),
            'today_events' => $this->latest('calendar_events', ['id', 'title', 'starts_at', 'ends_at', 'status'], 5, fn (Builder $query) => $query->whereBetween('starts_at', [now()->startOfDay(), now()->endOfDay()])),
            'recent_integrations' => $this->latest('integrations', ['id', 'name', 'type', 'status', 'is_healthy', 'last_sync_at', 'last_error'], 5),
        ];

        return view('warroom::Tech.dashboard', compact('warroom'));
    }

    /**
     * Count records only when the owning table exists.
     */
    private function count(string $table, ?callable $scope = null): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        $query = DB::table($table);
        $this->withoutDeleted($table, $query);

        if ($scope) {
            $scope($query);
        }

        return (int) $query->count();
    }

    /**
     * Fetch recent records for dashboard lists without requiring Eloquent model coupling.
     */
    private function latest(string $table, array $columns, int $limit, ?callable $scope = null)
    {
        if (! Schema::hasTable($table)) {
            return collect();
        }

        $safeColumns = collect($columns)
            ->filter(fn (string $column) => Schema::hasColumn($table, $column))
            ->values()
            ->all();

        $query = DB::table($table)->select($safeColumns ?: ['*']);
        $this->withoutDeleted($table, $query);

        if ($scope) {
            $scope($query);
        }

        $orderColumn = Schema::hasColumn($table, 'updated_at') ? 'updated_at' : 'id';

        return $query->orderByDesc($orderColumn)->limit($limit)->get();
    }

    /**
     * Apply the common soft-delete filter when a table supports it.
     */
    private function withoutDeleted(string $table, Builder $query): void
    {
        if (Schema::hasColumn($table, 'deleted_at')) {
            $query->whereNull('deleted_at');
        }
    }

    /**
     * Resolve optional dashboard links without making route existence a render-time risk.
     */
    private function routeUrl(string $route): ?string
    {
        return Route::has($route) ? route($route) : null;
    }
}
