<?php

namespace App\Modules\Sales\Actions;

use App\Models\Core\User;
use App\Modules\Calendar\Actions\EnsureCalendarDefaults;
use App\Modules\Calendar\Actions\LinkCalendarEvent;
use App\Modules\Calendar\Actions\StoreCalendarEvent;
use App\Modules\Sales\Models\SalesOpportunity;
use App\Modules\Sales\Models\SalesSetting;
use Illuminate\Support\Carbon;

class SyncOpportunityFollowUpCalendar
{
    public function __construct(
        private readonly EnsureCalendarDefaults $calendarDefaults,
        private readonly StoreCalendarEvent $storeCalendarEvent,
        private readonly LinkCalendarEvent $linkCalendarEvent,
    ) {
    }

    public function handle(SalesOpportunity $opportunity, User $actor): void
    {
        if (! SalesSetting::get('create_calendar_followups', true) || ! $opportunity->next_follow_up_at) {
            return;
        }

        $owner = $opportunity->owner ?: $actor;
        $calendar = $this->calendarDefaults->ensurePersonalCalendar($owner);
        $startsAt = Carbon::parse($opportunity->next_follow_up_at);
        $duration = (int) SalesSetting::get('default_followup_duration_minutes', 30);

        $event = $this->storeCalendarEvent->handle([
            'calendar_id' => $calendar->id,
            'title' => 'Sales follow-up: '.$opportunity->title,
            'description' => trim(($opportunity->next_follow_up_note ?: '')."\n\nOpportunity: ".$opportunity->opportunity_key),
            'starts_at' => $startsAt->toDateTimeString(),
            'ends_at' => $startsAt->copy()->addMinutes(max(15, $duration))->toDateTimeString(),
            'timezone' => $calendar->timezone ?: 'Europe/Oslo',
            'visibility' => 'default',
            'transparency' => 'busy',
            'source' => 'sales',
            'metadata' => [
                'opportunity_id' => $opportunity->id,
                'opportunity_key' => $opportunity->opportunity_key,
                'follow_up_type' => $opportunity->next_follow_up_type,
                'follow_up_label' => EnsureSalesDefaults::nextActionLabel($opportunity->next_follow_up_type),
            ],
        ], $actor);

        $this->linkCalendarEvent->handle($event, $opportunity, 'sales_follow_up');
        $opportunity->forceFill(['follow_up_calendar_event_id' => $event->id])->save();
    }
}
