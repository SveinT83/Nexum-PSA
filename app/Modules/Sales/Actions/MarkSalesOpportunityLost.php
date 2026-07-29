<?php

namespace App\Modules\Sales\Actions;

use App\Models\Core\User;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Sales\Models\SalesActivity;
use App\Modules\Sales\Models\SalesOpportunity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MarkSalesOpportunityLost
{
    public function handle(SalesOpportunity $opportunity, string $reason, ?string $note, User $actor): SalesOpportunity
    {
        if ($opportunity->status === 'lost') {
            throw ValidationException::withMessages([
                'lost_reason' => 'This opportunity is already marked as lost.',
            ]);
        }

        return DB::transaction(function () use ($opportunity, $reason, $note, $actor): SalesOpportunity {
            $opportunity->refresh();
            $calendarEvent = $opportunity->follow_up_calendar_event_id
                ? CalendarEvent::query()->lockForUpdate()->find($opportunity->follow_up_calendar_event_id)
                : null;

            if ($this->isGeneratedFutureFollowUp($calendarEvent, $opportunity)) {
                $calendarEvent->delete();
            }

            $opportunity->forceFill([
                'status' => 'lost',
                'probability_percent' => 0,
                'weighted_value_ex_vat' => 0,
                'lost_at' => now(),
                'lost_reason' => $reason,
                'won_at' => null,
                'next_follow_up_at' => null,
                'next_follow_up_type' => null,
                'next_follow_up_note' => null,
                'follow_up_calendar_event_id' => null,
                'updated_by' => $actor->id,
            ])->save();

            SalesActivity::query()->create([
                'opportunity_id' => $opportunity->id,
                'actor_id' => $actor->id,
                'type' => 'opportunity_lost',
                'subject' => 'Opportunity marked as lost',
                'body' => $note ? $reason."\n\nInternal note: ".$note : $reason,
                'is_unread' => false,
                'read_at' => now(),
                'metadata' => [
                    'lost_reason' => $reason,
                    'internal_note' => $note,
                    'calendar_event_removed' => $calendarEvent?->trashed() ?? false,
                ],
            ]);

            return $opportunity->refresh();
        });
    }

    private function isGeneratedFutureFollowUp(?CalendarEvent $event, SalesOpportunity $opportunity): bool
    {
        if (! $event || ! $event->starts_at?->isFuture()) {
            return false;
        }

        if ($event->source !== 'sales' || (int) data_get($event->metadata, 'opportunity_id') !== (int) $opportunity->id) {
            return false;
        }

        return $event->links()
            ->where('linkable_type', $opportunity->getMorphClass())
            ->where('linkable_id', $opportunity->id)
            ->where('relation', 'sales_follow_up')
            ->exists();
    }
}
