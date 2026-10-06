<?php

namespace App\Modules\Workday\Jobs;

use App\Models\Core\User;
use App\Modules\Notification\Actions\WorkdayReminderPreferences;
use App\Modules\Notification\Channels\EmailAccountMailChannel;
use App\Modules\Notification\Notifications\QueuedInternalWebPushNotification;
use App\Modules\Notification\Notifications\WorkdayReminderNotification;
use App\Modules\Notification\Support\InternalWebPushTargetAuthorizer;
use App\Modules\Notification\Support\WebPushReadiness;
use App\Modules\Workday\Actions\WorkdayReminders;
use App\Modules\Workday\Models\WorkdayReminder;
use App\Modules\Workday\Models\WorkdayReminderDelivery;
use App\Modules\Workday\Support\WorkdaySettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use NotificationChannels\WebPush\WebPushChannel;

class DeliverWorkdayReminder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public readonly int $deliveryId)
    {
        $this->onQueue('default');
    }

    /** The queue carries only an internal receipt ID, never employee/time/absence text. */
    public function handle(WorkdayReminders $reminders, WorkdayReminderPreferences $preferences): void
    {
        try {
            $this->deliver($reminders, $preferences);
        } catch (\Throwable) {
            // Failed-job storage must not receive employee/date SQL bindings or provider response text.
            throw new \RuntimeException('Workday reminder delivery failed.');
        }
    }

    private function deliver(WorkdayReminders $reminders, WorkdayReminderPreferences $preferences): void
    {
        if (! app(WorkdaySettings::class)->enabled()) {
            return;
        }
        $delivery = WorkdayReminderDelivery::find($this->deliveryId);
        $reminder = $delivery ? WorkdayReminder::find($delivery->reminder_id) : null;
        if (! $reminder) {
            return;
        }

        $claimed = DB::transaction(function () use ($reminder, $reminders, $preferences) {
            $user = User::whereKey($reminder->user_id)->lockForUpdate()->first();
            $receipt = WorkdayReminder::whereKey($reminder->id)->lockForUpdate()->first();
            $delivery = WorkdayReminderDelivery::whereKey($this->deliveryId)->lockForUpdate()->first();
            if (! $delivery || $delivery->state !== 'pending') {
                return false;
            }
            // A rescheduled day or snooze is still pending, not a permanently cancelled delivery.
            if ($user && $receipt && $receipt->generation === $delivery->generation
                && $receipt->expires_at->gt(now())
                && app(\App\Modules\Workday\Support\ReminderEligibility::class)->allowed($user)) {
                $future = app(\App\Modules\Workday\Support\ReminderEligibility::class)
                    ->plan($user, $receipt->work_date, $receipt->timezone);
                if ($receipt->snoozed_until?->isFuture() || ($future && $future['due']->isFuture())) {
                    return false;
                }
            }
            $plan = $user && $receipt ? $reminders->current($user, $receipt) : null;
            if (! $plan || $receipt->generation !== $delivery->generation
                || ! ($preferences->read($user)[$delivery->channel.'_enabled'] ?? false)) {
                $delivery->update(['state' => 'suppressed', 'finished_at' => now()]);

                return false;
            }
            if ($delivery->channel === 'database') {
                $notice = new WorkdayReminderNotification($reminders->path($receipt), [], $receipt->uuid, $receipt->expires_at->utc()->format('Y-m-d\TH:i:s\Z'));
                $user->notifications()->firstOrCreate(['id' => $receipt->notification_id], [
                    'type' => WorkdayReminderNotification::class, 'data' => $notice->toDatabase($user), 'read_at' => null,
                ]);
                $delivery->update(['state' => 'delivered', 'attempted_at' => now(), 'finished_at' => now()]);

                return false;
            }
            // Late catch-up belongs in-app; never send yesterday's old external nudge.
            if ($plan['end']->addHours(12)->lt(now())) {
                $delivery->update(['state' => 'suppressed', 'finished_at' => now()]);

                return false;
            }
            $delivery->update(['state' => 'attempting', 'attempted_at' => now()]);

            return true;
        });
        if (! $claimed) {
            return;
        }

        // Durable claim commits BEFORE provider I/O. A lost/ambiguous attempt is not automatically resent.
        DB::transaction(function () use ($reminder, $reminders, $preferences) {
            $user = User::whereKey($reminder->user_id)->lockForUpdate()->first();
            $receipt = WorkdayReminder::find($reminder->id);
            $delivery = WorkdayReminderDelivery::whereKey($this->deliveryId)->lockForUpdate()->first();
            // Retention may have purged the receipt after the durable claim committed.
            if (! $delivery || ! $receipt) {
                return;
            }
            $plan = $user ? $reminders->current($user, $receipt) : null;
            if (! $plan || $receipt->generation !== $delivery->generation
                || ! ($preferences->read($user)[$delivery->channel.'_enabled'] ?? false)
                || $plan['end']->addHours(12)->lt(now())) {
                $delivery->update(['state' => 'suppressed', 'finished_at' => now()]);

                return;
            }
            $state = 'blocked';
            try {
                if ($delivery->channel === 'mail') {
                    $result = app(EmailAccountMailChannel::class)->send($user,
                        new WorkdayReminderNotification($reminders->path($receipt), $delivery->mail_snapshot));
                    $state = $result['status'] === 'delivered' ? 'submitted' : $result['status'];
                } elseif ($delivery->channel === 'web_push' && app(WebPushReadiness::class)->isReady()
                    && $user->pushSubscriptions()->exists()
                    && ($path = app(InternalWebPushTargetAuthorizer::class)->authorizedPath($user, 'workday_reminder', $receipt->uuid))) {
                    app(WebPushChannel::class)->send($user, new QueuedInternalWebPushNotification(
                        'workday_reminder', 'Review your workday', 'Open Nexum to review your actual working time.',
                        $path, 'workday-'.$receipt->uuid.'-'.$receipt->generation, 1800, 'normal'));
                    $state = 'submitted';
                }
            } catch (\Throwable) {
                // Do not persist exception/provider content or retry an uncertain external send.
                $state = 'unresolved';
            }
            $delivery->update(['state' => $state, 'finished_at' => now()]);
        }, 1);
    }
}
