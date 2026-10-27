<?php

namespace App\Modules\Warroom\Resources\Api\V1;

use App\Modules\Warroom\Support\WarroomSources;
use Illuminate\Support\Carbon;

/** Fixed allowlists: never serialize source models, mailbox bodies or provider error text. */
class WarroomSnapshot
{
    public static function date($value): ?string
    {
        return $value === null ? null : ($value instanceof \DateTimeInterface ? Carbon::instance($value) : Carbon::parse($value, 'UTC'))->toIso8601String();
    }

    public static function myDay(array $data): array
    {
        $urls = app(WarroomSources::class);
        $result = [
            'generated_at' => self::date($data['generated_at']), 'timezone' => $data['timezone'],
            'window' => array_map(self::date(...), $data['window']),
            'sources' => $data['sources'], 'counts' => $data['counts'],
            'tickets' => $data['tickets']->map(fn ($row) => [
                'id' => (int) $row->id, 'key' => $row->ticket_key, 'title' => $row->subject,
                'client' => $row->client?->name, 'due_at' => self::date($row->resolve_due_at),
                'is_unread' => (bool) $row->is_unread, 'url' => $urls->url('tech.tickets.show', [$row->ticket_key]),
            ])->values()->all(),
            'tasks' => $data['tasks']->map(fn ($row) => [
                'id' => (int) $row->id, 'title' => $row->title, 'client' => $row->client?->name,
                'status' => $row->status?->name, 'due_at' => self::date($row->due_at),
                'scheduled_start_at' => self::date($row->scheduled_start_at), 'url' => $urls->url('tech.tasks.show', [$row->id]),
            ])->values()->all(),
            'events' => $data['events']->map(fn ($row) => [
                'id' => (int) $row->id, 'title' => $row->title, 'calendar' => $row->calendar?->name,
                'location' => $row->location, 'starts_at' => self::date($row->starts_at),
                'ends_at' => self::date($row->ends_at), 'status' => $row->status,
                'details_visible' => (bool) $row->details_visible,
            ])->values()->all(),
            'actions' => array_merge($data['actions'], $data['workday_actions']), 'overdue_state' => $data['overdue_state'],
        ];
        if (isset($data['overdue_items'])) {
            $result['overdue'] = self::overdue($data['overdue_pagination'] + ['items' => $data['overdue_items']->all()]);
        }

        return $result;
    }

    public static function overdue(array $data): array
    {
        $data['items'] = array_map(fn ($row) => array_replace($row, ['due_at' => self::date($row['due_at'])]), $data['items']);

        return $data;
    }

    public static function dashboard(array $data): array
    {
        return [
            'generated_at' => self::date($data['generated_at']), 'timezone' => $data['timezone'],
            'settings' => $data['settings'], 'sources' => $data['sources'], 'sections' => $data['sections'],
            'pulse' => $data['pulse'], 'operations' => $data['operations'],
            'storage_should_order' => $data['storage_should_order'], 'system' => (object) $data['system'],
            'calendar_events_label' => $data['calendar_events_label'],
            'latest_tickets' => $data['latest_tickets']->map(fn ($row) => [
                'id' => (int) $row->id, 'key' => $row->ticket_key, 'title' => $row->subject,
                'assigned' => $row->owner_id !== null, 'is_unread' => (bool) $row->is_unread,
                'due_at' => self::date($row->resolve_due_at), 'updated_at' => self::date($row->updated_at),
            ])->values()->all(),
            'latest_alerts' => $data['latest_alerts']->map(fn ($row) => [
                'id' => (int) $row->id, 'title' => $row->title, 'status' => $row->status,
                'last_seen_at' => self::date($row->last_seen_at),
            ])->values()->all(),
            'calendar_events' => $data['calendar_events']->map(fn ($row) => [
                'id' => (int) $row->id, 'title' => $row->title, 'status' => $row->status,
                'starts_at' => self::date($row->starts_at), 'ends_at' => self::date($row->ends_at),
                'details_visible' => (bool) $row->details_visible,
            ])->values()->all(),
            'recent_integrations' => $data['recent_integrations']->map(fn ($row) => [
                'id' => (string) $row->id, 'name' => $row->name, 'type' => $row->type,
                'health' => $row->has_error ? 'attention' : ($row->is_healthy === null ? 'unknown' : ((bool) $row->is_healthy ? 'healthy' : 'attention')),
                'has_error' => (bool) $row->has_error, 'last_sync_at' => self::date($row->last_sync_at),
            ])->values()->all(),
            'lanes' => $data['lanes'], 'next_actions' => $data['next_actions'],
        ];
    }
}
