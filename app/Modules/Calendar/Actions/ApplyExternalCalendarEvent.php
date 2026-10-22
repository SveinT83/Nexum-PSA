<?php

namespace App\Modules\Calendar\Actions;

use App\Modules\Calendar\Models\CalendarEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ApplyExternalCalendarEvent
{
    /**
     * Calendar owns external projections and sync metadata. Integrations never mutate
     * participants, links, recurrence series or ownership through this action.
     */
    public function handle(?CalendarEvent $event, array $data): CalendarEvent
    {
        return DB::transaction(function () use ($event, $data) {
            if ($event) {
                $fresh = CalendarEvent::withTrashed()->lockForUpdate()->findOrFail($event->id);
                if ($fresh->trashed() || $fresh->source === 'workday_absence'
                    || $fresh->series_id
                    || array_intersect_key($fresh->getRawOriginal(), $event->getRawOriginal()) !== $event->getRawOriginal()) {
                    throw new RuntimeException('Calendar event changed or is owned by another workflow.');
                }
                $event = $fresh;
            } else {
                $event = new CalendarEvent([
                    'uuid' => (string) Str::uuid(),
                    'work_context_id' => app(\App\Modules\WorkContext\Actions\ResolveWorkContext::class)->internal()->id,
                ]);
            }

            $fields = [
                'calendar_id', 'title', 'description', 'location', 'meeting_url', 'starts_at',
                'ends_at', 'timezone', 'all_day', 'status', 'transparency', 'visibility',
                'created_by', 'updated_by', 'source', 'external_source', 'external_calendar_id',
                'external_event_id', 'external_uid', 'external_etag', 'sync_status',
                'last_synced_at', 'sync_hash',
            ];
            $event->fill(array_intersect_key($data, array_flip($fields)));
            if (array_key_exists('metadata', $data)) {
                $event->metadata = array_replace($event->metadata ?? [], $data['metadata']);
            }
            $event->save();

            return $event->fresh();
        });
    }
}
