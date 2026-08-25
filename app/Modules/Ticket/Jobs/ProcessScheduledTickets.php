<?php

namespace App\Modules\Ticket\Jobs;

use App\Modules\Ticket\Actions\StoreScheduledTicketOccurrence;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketSchedule;
use App\Modules\Ticket\Models\TicketStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ProcessScheduledTickets implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(StoreScheduledTicketOccurrence $storeOccurrence): void
    {
        $now = Carbon::now();

        // 1. Activate one-time tickets that have reached their planned start time
        $this->activateDueTickets($now);

        // 2. Generate occurrences for recurring tickets
        $this->generateRecurringOccurrences($now, $storeOccurrence);
    }

    private function activateDueTickets(Carbon $now): void
    {
        $dueSchedules = TicketSchedule::query()
            ->where('status', 'scheduled')
            ->where('schedule_type', 'one_time')
            ->where('planned_start_at', '<=', $now)
            ->with('ticket')
            ->get();

        foreach ($dueSchedules as $schedule) {
            DB::transaction(function () use ($schedule) {
                $ticket = $schedule->ticket;

                // Change status from 'scheduled' (usually hidden/pending) to initial 'new' status
                $initialStatus = TicketStatus::query()->where('is_default', true)->first()
                    ?? TicketStatus::query()->orderBy('sort_order')->first();

                if ($initialStatus) {
                    // We use forceFill to bypass workflow guards during automation
                    $ticket->forceFill([
                        'status_id' => $initialStatus->id,
                        'workflow_state_key' => 'new',
                    ])->save();
                }

                $schedule->update(['status' => 'active']);
            });
        }
    }

    private function generateRecurringOccurrences(Carbon $now, StoreScheduledTicketOccurrence $storeOccurrence): void
    {
        $recurringSchedules = TicketSchedule::query()
            ->where('schedule_type', 'recurring')
            ->where(function ($query) use ($now) {
                $query->whereNull('recurrence_ends_at')
                    ->orWhere('recurrence_ends_at', '>', $now);
            })
            ->with('ticket')
            ->get();

        foreach ($recurringSchedules as $schedule) {
            $this->processRecurringSchedule($schedule, $now, $storeOccurrence);
        }
    }

    private function processRecurringSchedule(TicketSchedule $schedule, Carbon $now, StoreScheduledTicketOccurrence $storeOccurrence): void
    {
        $timezone = $schedule->timezone ?: 'UTC';
        $plannedStart = $schedule->planned_start_at->copy()->timezone($timezone);
        $frequency = strtolower(str_replace('FREQ=', '', $schedule->recurrence_rule ?? 'WEEKLY'));

        // Look ahead window (e.g., generate occurrences for the next 7 days)
        $lookAhead = $now->copy()->addDays(7);

        // Find the next occurrence date
        $cursor = $plannedStart->copy();

        // If the schedule is brand new, the first occurrence might be the planned_start_at itself
        // if it's within the lookahead window and hasn't been generated yet.

        while ($cursor->lte($lookAhead)) {
            if ($cursor->isPast()) {
                $this->advanceCursor($cursor, $frequency);
                continue;
            }

            // Check if we already generated an occurrence for this specific time
            $alreadyExists = Ticket::query()
                ->where('metadata->parent_ticket_id', $schedule->ticket_id)
                ->where('metadata->occurrence_planned_start', $cursor->toISOString())
                ->exists();

            if (! $alreadyExists) {
                $storeOccurrence->handle($schedule->ticket, $cursor->copy());
            }

            $this->advanceCursor($cursor, $frequency);

            // Safety break to prevent infinite loops if RRULE is weird
            if ($cursor->gt($lookAhead->copy()->addYears(1))) break;
        }
    }

    private function advanceCursor(Carbon $cursor, string $frequency): void
    {
        match ($frequency) {
            'daily' => $cursor->addDay(),
            'monthly' => $cursor->addMonthNoOverflow(),
            default => $cursor->addWeek(),
        };
    }
}
