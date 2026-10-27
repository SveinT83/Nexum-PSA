<?php

namespace App\Modules\Notification\Actions;

use App\Models\Core\User;
use App\Modules\Notification\Models\NotificationChannel;
use App\Modules\Notification\Models\NotificationSetting;
use App\Modules\Notification\Support\NotificationTypeRegistry as Types;
use App\Modules\Notification\Support\WebPushReadiness;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** The profile and portal keep one preference authority and atomic batch semantics. */
class ManageNotificationPreferences
{
    public function context(User $user, bool $portal = false): array
    {
        $types = Types::labels($portal ? Types::AUDIENCE_CUSTOMER_PORTAL : Types::AUDIENCE_INTERNAL);
        if (! $portal && (! app(\App\Modules\Workday\Support\ReminderEligibility::class)->allowed($user)
            || ($user->currentAccessToken() && ! $user->tokenCan('workday-reminders.read')))) {
            unset($types['workday_reminder']);
        }
        $settings = NotificationSetting::getAllForUser($user, array_keys($types));
        $rows = [];
        foreach ($types as $type => $label) {
            $definition = Types::definition($type);
            $setting = (array) (is_object($settings[$type]) && method_exists($settings[$type], 'toArray') ? $settings[$type]->toArray() : $settings[$type]);
            $values = [];
            foreach (['mail_enabled', 'database_enabled', 'web_push_enabled', 'web_push_preview_enabled', 'nextcloud_talk_enabled'] as $field) {
                $values[$field] = (! $portal || in_array($field, ['mail_enabled', 'database_enabled'], true))
                    && (bool) ($setting[$field] ?? false);
            }
            $rows[] = ['notification_type' => $type, 'label' => $label,
                'description' => $definition['description'], 'group' => $definition['group'],
                'channels' => $definition['channels'], 'defaults' => $definition['defaults'],
                'preferences' => $values,
                'nextcloud_talk_webhook_configured' => ! $portal && filled($setting['nextcloud_talk_webhook_url'] ?? null)];
        }

        return ['types' => $rows, 'groups' => $portal ? array_intersect_key(Types::GROUPS, ['customer_portal' => true]) : Types::GROUPS,
            'web_push' => $portal ? ['enabled' => false, 'ready' => false, 'state' => 'unsupported',
                'message' => 'Web Push is not supported for portal notifications.', 'public_key' => null] : app(WebPushReadiness::class)->toArray(),
            'talk_enabled' => ! $portal && (bool) NotificationChannel::getByDriver('nextcloud_talk')?->is_enabled,
            'workday_readiness' => isset($types['workday_reminder']) ? app(WorkdayReminderPreferences::class)->readiness($user) : null];
    }

    public function update(Request $request, bool $portal = false): array
    {
        $user = $request->user();
        $types = Types::labels($portal ? Types::AUDIENCE_CUSTOMER_PORTAL : Types::AUDIENCE_INTERNAL);
        $fields = ['notification_type', 'mail_enabled', 'database_enabled'];
        if (! $portal) {
            $fields = array_merge($fields, ['web_push_enabled', 'web_push_preview_enabled', 'nextcloud_talk_enabled', 'nextcloud_talk_webhook_url']);
        }
        $rules = ['settings' => 'required|array|min:1|max:100',
            'settings.*' => 'required|array:'.implode(',', $fields),
            'settings.*.notification_type' => ['required', 'string', 'distinct', Rule::in(array_keys($types))]];
        foreach (array_diff($fields, ['notification_type', 'nextcloud_talk_webhook_url']) as $field) {
            $rules['settings.*.'.$field] = 'nullable|boolean';
        }
        if (! $portal) {
            $rules['settings.*.nextcloud_talk_webhook_url'] = 'nullable|url:https|max:500';
        }
        $validated = $request->validate($rules);
        foreach ($validated['settings'] as $input) {
            if ($input['notification_type'] === 'workday_reminder') {
                abort_unless(app(\App\Modules\Workday\Support\ReminderEligibility::class)->allowed($user), 403);
                abort_if($user->currentAccessToken() && ! $user->tokenCan('workday-reminders.write'), 403);
                abort_if(filled($input['nextcloud_talk_webhook_url'] ?? null), 422);
            }
        }
        DB::transaction(function () use ($validated, $user, $portal) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            foreach ($validated['settings'] as $input) {
                $type = $input['notification_type'];
                if ($type === 'workday_reminder') {
                    unset($input['notification_type'], $input['nextcloud_talk_webhook_url']);
                    app(WorkdayReminderPreferences::class)->update($user, $input + ['database_enabled' => false, 'mail_enabled' => false, 'web_push_enabled' => false]);

                    continue;
                }
                $values = [];
                foreach (['mail', 'database', 'web_push', 'web_push_preview', 'nextcloud_talk'] as $channel) {
                    $values[$channel.'_enabled'] = (! $portal || in_array($channel, ['mail', 'database'], true))
                        && Types::supports($type, $channel) && (bool) ($input[$channel.'_enabled'] ?? false);
                }
                $existing = NotificationSetting::where('user_id', $user->id)->where('notification_type', $type)->first();
                $values['nextcloud_talk_webhook_url'] = $values['nextcloud_talk_enabled']
                    ? ($input['nextcloud_talk_webhook_url'] ?? $existing?->nextcloud_talk_webhook_url) : null;
                if (array_key_exists('nextcloud_talk_webhook_url', $input) && blank($input['nextcloud_talk_webhook_url'])) {
                    $values['nextcloud_talk_webhook_url'] = null;
                }
                NotificationSetting::updateOrCreate(['user_id' => $user->id, 'notification_type' => $type], $values);
            }
        });

        return $this->context($user, $portal);
    }
}
