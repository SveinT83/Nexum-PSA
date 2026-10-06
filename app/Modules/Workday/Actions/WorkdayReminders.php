<?php

namespace App\Modules\Workday\Actions;

use App\Models\Core\User;
use App\Modules\Notification\Actions\WorkdayReminderPreferences;
use App\Modules\Notification\Notifications\WorkdayReminderNotification;
use App\Modules\Workday\Jobs\DeliverWorkdayReminder;
use App\Modules\Workday\Models\WorkdayReminder;
use App\Modules\Workday\Models\WorkdayReminderDelivery;
use App\Modules\Workday\Support\ReminderEligibility;
use App\Modules\Workday\Support\WorkdaySettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkdayReminders
{
    public function __construct(private ReminderEligibility $eligibility, private WorkdayReminderPreferences $preferences) {}

    /** Bounded durable cursor. Receipt creation is independent of broker availability. */
    public function scan(int $limit = 200): array
    {
        if (! app(WorkdaySettings::class)->enabled()) {
            return ['scanned' => 0, 'queued' => 0, 'enabled' => false];
        }
        $limit = max(1, min(200, $limit));
        DB::table('workday_reminder_cursors')->insertOrIgnore(['id' => 1, 'last_user_id' => 0]);
        $ids = DB::transaction(function () use ($limit) {
            $cursor = DB::table('workday_reminder_cursors')->where('id', 1)->lockForUpdate()->first();

            return User::query()->where('id', '>', $cursor->last_user_id)->orderBy('id')->limit($limit)->pluck('id')->all();
        });
        foreach ($ids as $id) {
            DB::transaction(function () use ($id) {
                $user = User::query()->whereKey($id)->lockForUpdate()->first();
                if (! $user || ! $this->eligibility->allowed($user) || ! in_array(true, $this->preferences->read($user), true)) {
                    return;
                }
                $zone = $this->eligibility->timezone($user);
                $today = CarbonImmutable::now($zone)->startOfDay();
                foreach ([$today->subDay(), $today] as $date) {
                    $workDate = $date->toDateString();
                    $plan = $this->eligibility->due($user, $workDate, $zone);
                    if (! $plan || WorkdayReminder::where('user_id', $id)->where('work_date', $workDate)->exists()) {
                        continue;
                    }
                    $reminder = WorkdayReminder::create(['uuid' => (string) Str::uuid(), 'user_id' => $id,
                        'work_date' => $workDate, 'timezone' => $zone, 'generation' => 1, 'due_at' => $plan['due'],
                        'expires_at' => $date->addYearsNoOverflow(3)->addDay()->startOfDay()->utc(),
                        'notification_id' => (string) Str::uuid()]);
                    $this->newGeneration($user, $reminder);
                }
            });
        }
        // Advance only after completed processing; a crash safely replays idempotent discovery.
        DB::table('workday_reminder_cursors')->where('id', 1)->update([
            'last_user_id' => count($ids) < $limit ? 0 : max($ids), 'last_scanned_at' => now(),
        ]);
        $deliveries = WorkdayReminderDelivery::query()->where('state', 'pending')
            ->whereIn('reminder_id', WorkdayReminder::query()->where('expires_at', '>', now())
                ->where(fn ($q) => $q->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now()))->select('id'))
            ->orderBy('id')->limit($limit)->pluck('id');
        foreach ($deliveries as $id) {
            DeliverWorkdayReminder::dispatch($id);
        }

        return ['scanned' => count($ids), 'queued' => $deliveries->count(), 'enabled' => true];
    }

    private function newGeneration(User $user, WorkdayReminder $reminder): void
    {
        foreach ($this->preferences->read($user) as $field => $enabled) {
            if (! $enabled) {
                continue;
            }
            $channel = str_replace('_enabled', '', $field);
            WorkdayReminderDelivery::create(['reminder_id' => $reminder->id, 'generation' => $reminder->generation,
                'channel' => $channel, 'state' => 'pending',
                'mail_snapshot' => $channel === 'mail'
                    ? (new WorkdayReminderNotification($this->path($reminder)))->emailAccountMailSnapshot() : null]);
        }
    }

    public function path(WorkdayReminder $reminder): string
    {
        return route('tech.workday-reminders.open', $reminder->uuid, false);
    }

    public function current(User $user, WorkdayReminder $reminder): ?array
    {
        if ((int) $reminder->user_id !== (int) $user->id || $reminder->expires_at->lte(now())
            || $reminder->snoozed_until?->isFuture()) {
            return null;
        }

        return $this->eligibility->due($user, $reminder->work_date, $reminder->timezone);
    }

    /** Only unread, currently eligible in-app receipts appear, including on the next visit. */
    public function pending(User $user): array
    {
        if (! $this->eligibility->allowed($user) || ! $this->preferences->read($user)['database_enabled']) {
            return [];
        }
        $rows = WorkdayReminder::query()->where('user_id', $user->id)->where('expires_at', '>', now())
            ->whereIn('notification_id', $user->unreadNotifications()->select('id'))
            ->orderByDesc('work_date')->limit(50)->get();

        return $rows->filter(fn ($r) => $this->current($user, $r) !== null)->take(10)
            ->map(fn ($r) => $this->serialize($r))->values()->all();
    }

    /** Filter only Workday notifications; other domains keep their existing semantics. */
    public function filterNotifications($query, User $user): void
    {
        $ids = array_column($this->pending($user), 'notification_id');
        $query->where(fn ($q) => $q->where('type', '!=', WorkdayReminderNotification::class)->orWhereIn('id', $ids));
    }

    public function snooze(User $user, string $uuid, int $generation): array
    {
        abort_unless($this->eligibility->allowed($user), 403);

        return DB::transaction(function () use ($user, $uuid, $generation) {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $reminder = WorkdayReminder::where('uuid', $uuid)->where('user_id', $user->id)
                ->where('expires_at', '>', now())->lockForUpdate()->firstOrFail();
            // Exact prior generation retries return current state without extending the snooze.
            if ($generation === $reminder->generation - 1 && $reminder->snoozed_until !== null) {
                return $this->serialize($reminder);
            }
            abort_unless($generation === $reminder->generation, 409, 'The reminder changed. Reload before snoozing.');
            abort_unless($this->current($user, $reminder) && in_array(true, $this->preferences->read($user), true), 409,
                'This day no longer needs a reminder.');
            $user->notifications()->whereKey($reminder->notification_id)->update(['read_at' => now()]);
            WorkdayReminderDelivery::where('reminder_id', $reminder->id)->where('state', 'pending')
                ->update(['state' => 'suppressed', 'finished_at' => now()]);
            $reminder->update(['generation' => $reminder->generation + 1, 'snoozed_until' => now()->addMinutes(30),
                'notification_id' => (string) Str::uuid()]);
            $this->newGeneration($user, $reminder);

            return $this->serialize($reminder->fresh());
        });
    }

    public function serialize(WorkdayReminder $reminder): array
    {
        return ['id' => $reminder->uuid, 'work_date' => $reminder->work_date, 'generation' => $reminder->generation,
            'notification_id' => $reminder->notification_id, 'snoozed_until' => $reminder->snoozed_until?->toIso8601String(),
            'url' => $this->path($reminder)];
    }
}
