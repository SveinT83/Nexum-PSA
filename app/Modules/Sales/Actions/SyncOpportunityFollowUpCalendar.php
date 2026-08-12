<?php

namespace App\Modules\Sales\Actions;

use App\Models\Core\User;
use App\Modules\Calendar\Actions\EnsureCalendarDefaults;
use App\Modules\Calendar\Actions\LinkCalendarEvent;
use App\Modules\Calendar\Actions\StoreCalendarEvent;
use App\Modules\Calendar\Actions\UpdateCalendarEvent;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Sales\Models\SalesOpportunity;
use App\Modules\Sales\Models\SalesSetting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SyncOpportunityFollowUpCalendar
{
    public function __construct(
        private readonly EnsureCalendarDefaults $calendarDefaults,
        private readonly StoreCalendarEvent $storeCalendarEvent,
        private readonly UpdateCalendarEvent $updateCalendarEvent,
        private readonly LinkCalendarEvent $linkCalendarEvent,
    ) {}

    public function handle(SalesOpportunity $opportunity, User $actor): void
    {
        if (! SalesSetting::get('create_calendar_followups', true)) {
            return;
        }

        DB::transaction(function () use ($opportunity, $actor): void {
            $lockedOpportunity = SalesOpportunity::query()
                ->with('owner')
                ->lockForUpdate()
                ->findOrFail($opportunity->getKey());

            $event = $this->pointedGeneratedFollowUp($lockedOpportunity);

            if (! $lockedOpportunity->next_follow_up_at) {
                $event?->delete();

                if ($lockedOpportunity->follow_up_calendar_event_id !== null) {
                    $lockedOpportunity->forceFill(['follow_up_calendar_event_id' => null])->save();
                }

                return;
            }

            $owner = $lockedOpportunity->owner ?: $actor;
            $calendar = $this->calendarDefaults->ensurePersonalCalendar($owner);
            $startsAt = Carbon::parse($lockedOpportunity->next_follow_up_at);
            $duration = (int) SalesSetting::get('default_followup_duration_minutes', 30);
            $eventData = [
                'calendar_id' => $calendar->id,
                'title' => 'Sales follow-up: '.$lockedOpportunity->title,
                'description' => trim(($lockedOpportunity->next_follow_up_note ?: '')."\n\nOpportunity: ".$lockedOpportunity->opportunity_key),
                'starts_at' => $startsAt->toDateTimeString(),
                'ends_at' => $startsAt->copy()->addMinutes(max(15, $duration))->toDateTimeString(),
                'timezone' => $calendar->timezone ?: 'Europe/Oslo',
                'all_day' => false,
                'status' => 'confirmed',
                'visibility' => 'default',
                'transparency' => 'busy',
                'source' => 'sales',
                'metadata' => [
                    'opportunity_id' => $lockedOpportunity->id,
                    'opportunity_key' => $lockedOpportunity->opportunity_key,
                    'follow_up_type' => $lockedOpportunity->next_follow_up_type,
                    'follow_up_label' => EnsureSalesDefaults::nextActionLabel($lockedOpportunity->next_follow_up_type),
                ],
            ];

            if ($event) {
                $event = $this->updateCalendarEvent->handle($event, $eventData, $actor);
                $event->forceFill([
                    'metadata' => array_merge($event->metadata ?? [], $eventData['metadata']),
                ])->save();
            } else {
                $event = $this->storeCalendarEvent->handle($eventData, $actor);
            }

            $this->linkCalendarEvent->handle($event, $lockedOpportunity, 'sales_follow_up');

            if ((int) $lockedOpportunity->follow_up_calendar_event_id !== (int) $event->id) {
                $lockedOpportunity->forceFill(['follow_up_calendar_event_id' => $event->id])->save();
            }
        });
    }

    private function pointedGeneratedFollowUp(SalesOpportunity $opportunity): ?CalendarEvent
    {
        if (! $opportunity->follow_up_calendar_event_id) {
            return null;
        }

        return CalendarEvent::query()
            ->whereKey($opportunity->follow_up_calendar_event_id)
            ->where('source', 'sales')
            ->where('metadata->opportunity_id', $opportunity->id)
            ->whereHas('links', fn (Builder $query): Builder => $query
                ->where('linkable_type', $opportunity->getMorphClass())
                ->where('linkable_id', $opportunity->id)
                ->where('relation', 'sales_follow_up'))
            ->lockForUpdate()
            ->first();
    }
}
