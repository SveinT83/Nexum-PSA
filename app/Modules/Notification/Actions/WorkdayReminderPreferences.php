<?php

namespace App\Modules\Notification\Actions;

use App\Models\Core\User;
use App\Modules\Notification\Models\NotificationSetting;
use App\Modules\Notification\Notifications\WorkdayReminderNotification;
use App\Modules\Notification\Support\WebPushReadiness;
use App\Modules\Workday\Support\ReminderEligibility;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Notification owns the single preference row shared by Profile and employee API. */
class WorkdayReminderPreferences
{
    public const TYPE = 'workday_reminder';

    public function read(User $user): array
    {
        $setting = NotificationSetting::getAllForUser($user, [self::TYPE])[self::TYPE];

        return collect(['database_enabled', 'mail_enabled', 'web_push_enabled'])
            ->mapWithKeys(fn ($key) => [$key => (bool) $setting->$key])->all();
    }

    public function readiness(User $user): array
    {
        $snapshot = (new WorkdayReminderNotification('/tech/workdays'))->emailAccountMailSnapshot();

        return ['database' => true,
            'mail' => ! $snapshot['failure_code'] && (bool) $snapshot['account_id'] && (bool) $snapshot['provider_binding_version'],
            'web_push' => app(WebPushReadiness::class)->isReady() && $user->pushSubscriptions()->exists()];
    }

    public function update(User $user, array $input): array
    {
        abort_unless(app(ReminderEligibility::class)->allowed($user), 403);
        Validator::make(['settings' => $input], [
            'settings' => 'required|array:database_enabled,mail_enabled,web_push_enabled,web_push_preview_enabled,nextcloud_talk_enabled',
            'settings.database_enabled' => 'required|boolean',
            'settings.mail_enabled' => 'required|boolean',
            'settings.web_push_enabled' => 'required|boolean',
            'settings.web_push_preview_enabled' => 'sometimes|declined',
            'settings.nextcloud_talk_enabled' => 'sometimes|declined',
        ])->validate();

        return DB::transaction(function () use ($user, $input) {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $before = $this->read($user);
            $ready = $this->readiness($user);
            foreach (['mail', 'web_push'] as $channel) {
                if ($input[$channel.'_enabled'] && ! $before[$channel.'_enabled'] && ! $ready[$channel]) {
                    throw ValidationException::withMessages([$channel.'_enabled' => 'Configure this channel or register a device before enabling it.']);
                }
            }
            NotificationSetting::updateOrCreate(['user_id' => $user->id, 'notification_type' => self::TYPE],
                array_intersect_key($input, $before) + ['web_push_preview_enabled' => false, 'nextcloud_talk_enabled' => false]);

            return $this->read($user);
        });
    }
}
