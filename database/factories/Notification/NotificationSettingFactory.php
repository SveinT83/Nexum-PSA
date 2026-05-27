<?php

namespace Database\Factories\Notification;

use App\Models\Core\User;
use App\Modules\Notification\Models\NotificationSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Modules\Notification\Models\NotificationSetting>
 */
class NotificationSettingFactory extends Factory
{
    protected $model = NotificationSetting::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'notification_type' => 'ticket_assigned',
            'mail_enabled' => false,
            'database_enabled' => true,
            'nextcloud_talk_enabled' => true,
            'nextcloud_talk_webhook_url' => null,
        ];
    }
}