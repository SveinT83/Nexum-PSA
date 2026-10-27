<?php

namespace App\Modules\Warroom\Queries;

use App\Models\Core\User;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Calendar\Queries\CalendarOverlayQuery;
use App\Modules\Calendar\Services\CalendarVisibility;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Services\MailboxAccess;
use App\Modules\Storage\Queries\StorageIndexQuery;
use App\Modules\Warroom\Support\WarroomSettings;
use App\Modules\Warroom\Support\WarroomSources;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** A pure, bounded operations projection shared by web and API. */
class WarroomDashboardQuery
{
    public function __construct(private WarroomSources $sources, private WarroomSettings $settings) {}

    public function forUser(User $actor): array
    {
        $now = now();
        $settings = $this->settings->get();
        $states = $this->sources->states($actor);
        $enabled = $settings['enabled_sections'];
        $data = ['generated_at' => $now, 'timezone' => config('app.timezone'), 'settings' => $settings,
            'pulse' => [], 'operations' => [], 'latest_tickets' => collect(), 'latest_alerts' => collect(),
            'calendar_events' => collect(), 'calendar_events_label' => 'Today', 'recent_integrations' => collect(),
            'system' => [], 'storage_should_order' => ['count' => null, 'visible' => false, 'href' => null],
            'lanes' => [], 'next_actions' => []];
        // No source query is executed without its permission/schema gate.
        foreach ($states as $source => $state) {
            if ($state !== 'available') {
                continue;
            }
            try {
                $this->sourceData($source, $actor, $settings, $enabled, $data, $now);
            } catch (QueryException|\PDOException $error) {
                $states[$source] = 'unavailable';
                // Source work is built locally and only published after a successful read.
            }
        }
        $sectionSources = [
            'pulse' => ['tickets', 'assets', 'mail'], 'tickets' => ['tickets'],
            'asset_alerts' => ['assets'], 'calendar' => ['calendar'],
            'domain_radar' => ['clients', 'contracts', 'sales', 'economy', 'storage', 'knowledge'],
            'integrations' => ['integrations'], 'system_health' => ['integrations', 'nextcloud', 'notifications'],
            'next_actions' => ['tickets', 'mail', 'integrations'],
        ];
        $data['sections'] = [];
        foreach ($sectionSources as $section => $sources) {
            $allowed = array_values(array_intersect_key($states, array_flip($sources)));
            $data['sections'][$section] = ! in_array($section, $enabled, true) ? 'hidden'
                : (in_array('available', $allowed, true) ? 'available'
                    : (in_array('unavailable', $allowed, true) ? 'unavailable' : 'inaccessible'));
        }
        if (in_array('lanes', $enabled, true)) {
            $data['lanes'] = $this->sources->links($actor);
        }
        $data['sections']['lanes'] = in_array('lanes', $enabled, true) ? 'available' : 'hidden';
        foreach ([
            ['tickets', 'Clear SLA pressure', 'Start with overdue and due-today tickets.', 'tech.tickets.index'],
            ['mail', 'Triage inbox drift', 'Review unlinked messages in your authorized mailboxes.', 'tech.mail.index'],
            ['integrations', 'Check integrations', 'Review integration health before customer-facing work.', 'tech.admin.system.integrations.index'],
        ] as [$source, $title, $description, $route]) {
            if ($data['sections']['next_actions'] === 'available' && $states[$source] === 'available') {
                $data['next_actions'][] = compact('title', 'description') + ['href' => $this->sources->url($route)];
            }
        }
        $data['sources'] = $states;

        return $data;
    }

    private function sourceData(string $source, User $actor, array $settings, array $enabled, array &$data, Carbon $now): void
    {
        $pulse = in_array('pulse', $enabled, true);
        $radar = in_array('domain_radar', $enabled, true);
        $health = in_array('system_health', $enabled, true);
        if ($source === 'tickets' && ($pulse || in_array('tickets', $enabled, true))) {
            $base = $this->table('tickets')->whereNull('closed_at')
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('ticket_statuses')->whereColumn('ticket_statuses.id', 'tickets.status_id')->where('is_closed', true));
            $open = (clone $base)->count();
            $overdue = (clone $base)->where('resolve_due_at', '<', $now->copy()->utc())->count();
            $dueSoon = (clone $base)->whereBetween('resolve_due_at', [$now->copy()->utc(), $now->copy()->addHours($settings['due_soon_hours'])->utc()])->count();
            $unread = (clone $base)->where('is_unread', true)->count();
            $unassigned = (clone $base)->whereNull('owner_id')->count();
            $rows = in_array('tickets', $enabled, true)
                ? (clone $base)->select(['id', 'ticket_key', 'subject', 'owner_id', 'is_unread', 'resolve_due_at', 'updated_at'])
                    ->orderByDesc('updated_at')->orderBy('id')->limit($settings['latest_tickets_limit'])->get() : collect();
            if ($pulse) {
                $data['pulse'][] = $this->metric('Open tickets', $open, $overdue ? 'danger' : 'primary', 'bi-ticket-detailed', 'tech.tickets.index', "$overdue overdue / $dueSoon due soon");
                $data['pulse'][] = $this->metric('Unread queue', $unread, 'warning', 'bi-broadcast', 'tech.tickets.index', "$unassigned unassigned");
            }
            $data['latest_tickets'] = $rows;
        } elseif ($source === 'assets' && ($pulse || in_array('asset_alerts', $enabled, true))) {
            $count = $this->table('asset_alerts')->whereNull('resolved_at')->count();
            $managed = $this->table('assets')->where('is_managed', true)->count();
            $rows = in_array('asset_alerts', $enabled, true)
                ? $this->table('asset_alerts')->whereNull('resolved_at')->select(['id', 'title', 'status', 'last_seen_at'])
                    ->orderByDesc('id')->limit($settings['latest_alerts_limit'])->get() : collect();
            if ($pulse) {
                $data['pulse'][] = $this->metric('Asset alerts', $count, 'danger', 'bi-shield-exclamation', 'tech.assets.index', "$managed managed assets");
            }
            $data['latest_alerts'] = $rows;
        } elseif ($source === 'mail' && $pulse) {
            // Emergency grants require Mail's use/audit guard; routine KPIs use ordinary scope only.
            $query = app(MailboxAccess::class)->scopeMessages(EmailMessage::query(), $actor);
            $count = (clone $query)->whereNull('ticket_id')->count();
            $recent = (clone $query)->where('received_at', '>=', $now->copy()->subHours($settings['inbox_recent_hours'])->utc())->count();
            $data['pulse'][] = $this->metric('Mail triage', $count, 'info', 'bi-inbox', 'tech.mail.index', $recent.' last '.$settings['inbox_recent_hours'].'h');
        } elseif ($source === 'calendar' && in_array('calendar', $enabled, true)) {
            $overlay = app(CalendarOverlayQuery::class);
            $events = $overlay->eventsForRange($actor, $now->copy()->startOfDay(), $now->copy()->endOfDay())->take($settings['calendar_today_limit']);
            $label = 'Today';
            if ($events->isEmpty()) {
                $next = CalendarEvent::query()->with(['calendar.owner', 'participants', 'links'])
                    ->whereIn('calendar_id', $overlay->visibleCalendars($actor)->pluck('id'))
                    ->where('status', '!=', 'cancelled')->where('starts_at', '>', $now->copy()->utc())->orderBy('starts_at')->orderBy('id')->first();
                $events = $next ? collect([app(CalendarVisibility::class)->maskEvent($next, $actor)]) : collect();
                $label = 'Next event';
            }
            $data['calendar_events'] = $events->map(fn ($event) => (object) [
                'id' => $event['id'], 'title' => $event['title'], 'starts_at' => $event['starts_at'],
                'ends_at' => $event['ends_at'], 'status' => $event['status'], 'details_visible' => $event['details_visible'],
            ]);
            $data['calendar_events_label'] = $label;
        } elseif ($source === 'storage') {
            $count = app(StorageIndexQuery::class)->shouldOrderCount();
            $picks = $radar ? $this->table('storage_reservations')->whereIn('status', ['active', 'pending', 'reserved'])->count() : null;
            $data['storage_should_order'] = ['count' => $count, 'visible' => $count > 0,
                'href' => $count > 0 ? $this->sources->url('tech.storage.index', ['availability' => 'should_order']) : null];
            if ($radar) {
                $data['operations'][] = $this->metric('Storage picks', $picks, 'primary', 'bi-box-seam', 'tech.storage.picking');
            }
        } elseif ($source === 'integrations' && ($health || in_array('integrations', $enabled, true))) {
            $total = $this->table('integrations')->count();
            $unhealthy = $this->table('integrations')->where(fn ($q) => $q->where('is_healthy', false)->orWhereNull('is_healthy')->orWhereNotNull('last_error'))->count();
            $rows = in_array('integrations', $enabled, true)
                ? $this->table('integrations')->select(['id', 'name', 'type', 'is_healthy', 'last_sync_at'])
                    ->selectRaw('CASE WHEN last_error IS NULL THEN 0 ELSE 1 END as has_error')->orderByDesc('id')->limit($settings['recent_integrations_limit'])->get() : collect();
            $data['recent_integrations'] = $rows;
            if ($health) {
                $data['system'] += ['integrations_total' => $total, 'integrations_unhealthy' => $unhealthy];
            }
        } elseif ($source === 'nextcloud' && $health) {
            $active = $this->table('nextcloud_connections')->where('is_active', true)->count();
            $warnings = $this->table('nextcloud_connections')->where('is_active', true)
                ->where(fn ($q) => $q->whereNotIn('health_status', ['healthy', 'ok'])->orWhereNull('health_status'))->count();
            $data['system'] += ['nextcloud_active' => $active, 'nextcloud_warnings' => $warnings];
        } elseif ($source === 'notifications' && $health) {
            $data['system']['notification_channels'] = $this->table('notification_channels')->where('is_enabled', true)->count();
        } elseif ($radar) {
            $definitions = [
                'clients' => ['clients', 'Clients', 'tech.clients.index', 'bi-buildings'],
                'contracts' => ['contracts', 'Contracts', 'tech.contracts.index', 'bi-file-earmark-check'],
                'sales' => ['sales_opportunities', 'Sales pipeline', 'tech.sales.index', 'bi-graph-up-arrow'],
                'economy' => ['economy_orders', 'Economy orders', 'tech.economy.orders.index', 'bi-receipt'],
                'knowledge' => ['articles', 'Knowledge pages', 'tech.knowledge.index', 'bi-journal-text'],
            ];
            if (isset($definitions[$source])) {
                [$table, $label, $route, $icon] = $definitions[$source];
                $query = $this->table($table);
                if ($source === 'contracts') {
                    $query->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $now->toDateString()));
                } elseif ($source === 'sales') {
                    $query->whereNotIn('status', ['won', 'lost', 'closed']);
                } elseif ($source === 'economy') {
                    $query->whereIn('status', ['draft', 'ready']);
                }
                $data['operations'][] = $this->metric($label, $query->count(), 'primary', $icon, $route);
            }
        }
    }

    private function table(string $table): \Illuminate\Database\Query\Builder
    {
        return DB::table($table)->when(Schema::hasColumn($table, 'deleted_at'), fn ($query) => $query->whereNull($table.'.deleted_at'));
    }

    private function metric(string $label, int $value, string $tone, string $icon, string $route, string $meta = ''): array
    {
        return compact('label', 'value', 'tone', 'icon', 'meta') + ['href' => $this->sources->url($route)];
    }
}
