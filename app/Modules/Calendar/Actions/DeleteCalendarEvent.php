<?php

namespace App\Modules\Calendar\Actions;

use App\Models\Core\User;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Calendar\Models\CalendarEventException;
use App\Modules\Calendar\Services\CalendarRecurrenceExpander;
use App\Modules\Calendar\Services\CalendarVisibility;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Shared browser/API deletion; a series occurrence must match a real expanded occurrence. */
class DeleteCalendarEvent
{
    public function handle(User $actor, CalendarEvent $event, string $scope = 'record', ?string $originalStartsAt = null): CalendarEvent
    {
        return DB::transaction(function () use ($actor, $event, $scope, $originalStartsAt) {
            $event = CalendarEvent::query()->lockForUpdate()->findOrFail($event->id);
            abort_unless(app(CalendarVisibility::class)->canManageCalendar($actor, $event->calendar), 403);
            abort_if($event->source === 'work_plan', 422, 'Edit this block from Profile > Work plan.');
            ProjectWorkdayAbsence::assertCalendarEditable($event);
            if ($scope === 'event' && $event->series_id) {
                $at = Carbon::parse($originalStartsAt ?? $event->starts_at, $event->timezone)->utc();
                $series = $event->series()->lockForUpdate()->firstOrFail();
                $matches = app(CalendarRecurrenceExpander::class)->expand($series->load(['events', 'exceptions']), $at->copy()->subSecond(), $at->copy()->addSecond())
                    ->contains(fn ($row) => $row['starts_at']->equalTo($at));
                if (! $matches) {
                    throw ValidationException::withMessages(['original_starts_at' => 'Select an existing uncancelled occurrence.']);
                }
                CalendarEventException::query()->updateOrCreate(['series_id' => $series->id, 'original_starts_at' => $at], ['exception_type' => 'cancelled']);
            } elseif ($scope === 'series' && $event->series_id) {
                $series = $event->series()->lockForUpdate()->firstOrFail();
                foreach ($series->events()->lockForUpdate()->get() as $member) {
                    ProjectWorkdayAbsence::assertCalendarEditable($member);
                    abort_if($member->source === 'work_plan', 422);
                    abort_unless(app(CalendarVisibility::class)->canManageCalendar($actor, $member->calendar), 403);
                    $member->delete();
                }
                $series->delete();
            } else {
                abort_if($scope !== 'record' && $event->series_id === null, 422, 'This event is not a series.');
                $event->delete();
            }

            return $event;
        });
    }
}
