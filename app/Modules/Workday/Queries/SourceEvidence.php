<?php

namespace App\Modules\Workday\Queries;

use App\Models\Core\User;
use App\Modules\Calendar\Queries\WorkdayEvidence;
use App\Modules\Workday\Models\Workday;
use Carbon\CarbonImmutable;

class SourceEvidence
{
    public const KINDS = ['task', 'ticket', 'calendar'];

    public function permitted(User $worker, string $kind): bool
    {
        $ability = match ($kind) {
            'task' => 'tasks.read', 'ticket' => 'tickets.read', 'calendar' => 'calendar.read', default => ''
        };

        return $ability !== '' && $worker->can($kind.'.view') && (! $worker->currentAccessToken() || $worker->tokenCan($ability));
    }

    public function window(Workday $day, ?array $snapshot = null): array
    {
        $snapshot ??= $day->currentRevision->snapshot;
        $from = CarbonImmutable::parse($day->work_date, $day->timezone)->startOfDay();
        $to = $from->addDay();
        foreach ($snapshot['intervals'] as $interval) {
            $to = $to->max(CarbonImmutable::parse($interval['end']));
        }

        return [$from->utc(), $to->utc()];
    }

    public function discover(User $worker, Workday $day, string $kind, int $page = 1, int $perPage = 20, ?int $calendarId = null): array
    {
        $meta = ['kind' => $kind, 'page' => $page, 'per_page' => $perPage, 'next_page' => null,
            'status' => 'unavailable', 'reason' => 'Source permission or token ability is unavailable.', 'total' => null, 'truncated' => false];
        if (! $this->permitted($worker, $kind)) {
            return ['data' => [], 'meta' => $meta];
        }
        [$from, $to] = $this->window($day);
        if ($kind === 'calendar') {
            if (! $calendarId) {
                return ['data' => [], 'meta' => array_replace($meta, ['reason' => 'Choose a Calendar explicitly.']),
                    'calendars' => app(WorkdayEvidence::class)->calendars($worker)->take(100)->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values()->all()];
            }
            $result = app(WorkdayEvidence::class)->read($worker, $calendarId, $from, $to);
            if (! $result['available']) {
                return ['data' => [], 'meta' => $meta];
            }
            $rows = collect($result['rows'])->sortBy('source_key')->values();
            $total = $rows->count();
            $truncated = $result['partial'] || $total > 500;
            $items = $rows->take(500)->forPage($page, $perPage)->values()->all();
        } else {
            $query = $this->timeQuery($worker, $kind)->whereDate('work_date', '>=', $from->setTimezone($day->timezone)->toDateString())
                ->whereDate('work_date', '<=', $to->subSecond()->setTimezone($day->timezone)->toDateString());
            $total = (clone $query)->count();
            $truncated = $total > 500;
            $take = max(0, min($perPage, 500 - ($page - 1) * $perPage));
            $items = $take ? $query->orderBy('id')->offset(($page - 1) * $perPage)->limit($take)->get()->map(fn ($entry) => $this->describeTime($entry, $kind))->all() : [];
        }
        $next = $page * $perPage < min($total, 500) ? $page + 1 : null;

        return ['data' => $items, 'meta' => array_replace($meta, ['total' => $total, 'next_page' => $next,
            'status' => ($truncated || $next || $page > 1) ? 'partial' : 'complete', 'truncated' => $truncated,
            'reason' => $truncated ? 'Source discovery reached a bounded query or recurrence limit.' : ($next || $page > 1 ? 'This response is one page of the permitted sources.' : null)])];
    }

    /** Resolve by stable identity rather than a page position; recheck ownership/access for every use. */
    public function resolve(User $worker, Workday $day, array $reference, bool $lock = false, ?array $snapshot = null): ?array
    {
        $kind = $reference['kind'];
        if (! $this->permitted($worker, $kind)) {
            return null;
        }
        [$from, $to] = $this->window($day, $snapshot);
        if ($kind === 'calendar') {
            $result = app(WorkdayEvidence::class)->read($worker, (int) ($reference['calendar_id'] ?? 0), $from, $to, $lock);

            return collect($result['rows'])->firstWhere('source_key', $reference['source_key']);
        }
        if (! preg_match('/^'.preg_quote($kind, '/').':([1-9][0-9]*)$/D', $reference['source_key'], $match)) {
            return null;
        }
        $query = $this->timeQuery($worker, $kind)->whereKey($match[1]);
        if ($lock) {
            $query->lockForUpdate();
        }
        $entry = $query->first();
        if (! $entry) {
            return null;
        }
        $owner = $kind === 'task' ? $entry->task : $entry->ticket;
        if ($lock) {
            $owner = $owner->newQuery()->whereKey($owner->id)->lockForUpdate()->first();
            if (! $owner) {
                return null;
            }
            $entry->setRelation($kind, $owner);
        }

        // Read historical references even if the source date moved; validation separately checks the target day.
        return $this->describeTime($entry, $kind);
    }

    private function timeQuery(User $worker, string $kind)
    {
        return $kind === 'task' ? app(\App\Modules\Task\Queries\OwnWorkdayTime::class)->query($worker)
            : app(\App\Modules\Ticket\Queries\OwnWorkdayTime::class)->query($worker);
    }

    /** Pure projection after the calling domain adapter has enforced current access. */
    public function describeTime($entry, string $kind): array
    {
        $owner = $kind === 'task' ? $entry->task : $entry->ticket;
        $type = $kind === 'task' ? $entry->source_type : $entry->type;
        $basis = in_array($type, ['manual', 'ticket_time_entry'], true) ? 'recorded' : ($type === 'estimated' ? 'estimated' : 'unknown');
        $row = ['source_key' => $kind.':'.$entry->id, 'kind' => $kind, 'basis' => $basis, 'minutes' => (int) $entry->minutes,
            'date' => $entry->work_date?->toDateString(),
            'start' => $entry->started_at?->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'end' => $entry->ended_at?->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'calendar_id' => null, 'title' => $kind === 'task' ? $owner->title : ($owner->subject ?? $owner->ticket_key),
            'url' => route($kind === 'task' ? 'tech.tasks.show' : 'tech.tickets.show', $owner)];
        $row['source_revision'] = hash('sha256', json_encode([$row, $entry->note, $entry->updated_at?->toIso8601String(), $owner->updated_at?->toIso8601String()], JSON_THROW_ON_ERROR));

        return $row;
    }
}
