<?php

namespace App\Modules\Workday\Queries;

use App\Models\Core\User;
use App\Modules\UserManagement\Actions\UserWorkPlan;
use App\Modules\Workday\Models\Workday;
use App\Modules\Workday\Models\WorkdayRevision;
use Carbon\CarbonImmutable;
use DateTimeZone;

class WorkdayEditor
{
    /** Opening a date never creates a day or copies planned time into the ledger. */
    public function forDate(User $user, ?string $date = null, ?string $month = null): array
    {
        $timezone = app(UserWorkPlan::class)->read($user)['timezone'];
        $date ??= now($timezone)->toDateString();
        $record = Workday::query()->where('user_id', $user->id)->whereDate('work_date', $date)
            ->where('expires_at', '>', now())->first();
        if ($record) {
            return $this->forRecord($record, $month);
        }

        $day = ['id' => null, 'work_date' => $date, 'timezone' => $timezone, 'version' => 0,
            'current' => null, 'confirmed' => null];
        $timeline = app(WorkdayTimeline::class)->handle($user, $date, $timezone);
        $snapshot = ['description' => 'Work - unspecified', 'breaks' => [],
            'intervals' => [($timeline['selection'] ?? ['start' => '', 'end' => '']) + ['description' => '']]];

        return ['day' => $day, 'history' => null, 'initialSnapshot' => $snapshot,
            'planState' => $timeline['plan_state'], 'timeline' => $timeline,
            'calendar' => app(WorkdayTimeline::class)->calendar($date, $timezone, $month),
            'clockChanges' => $this->clockChanges($date, $timezone)];
    }

    public function forRecord(Workday $record, ?string $month = null): array
    {
        $reader = app(ReadWorkday::class);
        $day = $reader->serialize($record);
        $history = WorkdayRevision::query()->where('workday_id', $record->id)->orderByDesc('version')
            ->paginate(20, ['*'], 'history_page')->withQueryString();
        $history->setCollection($history->getCollection()->map(fn ($r) => $reader->revision($r)));
        $timeline = app(WorkdayTimeline::class)->handle(User::findOrFail($record->user_id), $day['work_date'], $day['timezone'],
            $day['current']['snapshot'], ! array_key_exists('durations', $day['current']['snapshot']));

        return ['day' => $day, 'history' => $history, 'initialSnapshot' => null, 'planState' => 'saved',
            'timeline' => $timeline,
            'calendar' => app(WorkdayTimeline::class)->calendar($day['work_date'], $day['timezone'], $month),
            'clockChanges' => $this->clockChanges($day['work_date'], $day['timezone'])];
    }

    /** Native pickers need an explicit occurrence choice only during a repeated local clock hour. */
    private function clockChanges(string $date, string $timezone): array
    {
        $from = CarbonImmutable::parse($date, $timezone)->startOfDay()->subDay();
        $transitions = (new DateTimeZone($timezone))->getTransitions($from->getTimestamp(), $from->addDays(4)->getTimestamp()) ?: [];
        $result = [];
        $previous = null;
        foreach ($transitions as $transition) {
            $offset = $transition['offset'];
            if ($previous !== null && $offset < $previous) {
                $formatOffset = fn ($seconds) => sprintf('%s%02d:%02d', $seconds < 0 ? '-' : '+',
                    intdiv(abs($seconds), 3600), intdiv(abs($seconds) % 3600, 60));
                $result[] = ['from' => gmdate('Y-m-d\TH:i', $transition['ts'] + $offset),
                    'until' => gmdate('Y-m-d\TH:i', $transition['ts'] + $previous),
                    'offsets' => [$formatOffset($previous), $formatOffset($offset)]];
            }
            $previous = $offset;
        }

        return $result;
    }
}
