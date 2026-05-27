<?php

namespace Database\Factories\Notification;

use App\Modules\Notification\Models\NotificationChannel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Modules\Notification\Models\NotificationChannel>
 */
class NotificationChannelFactory extends Factory
{
    protected $model = NotificationChannel::class;

    public function definition(): array
    {
        return [
            'name' => 'nextcloud_talk',
            'label' => 'Nextcloud Talk',
            'driver' => 'nextcloud_talk',
            'is_enabled' => true,
            'config' => [],
            'secrets' => [],
        ];
    }

    public function disabled(): static
    {
        return $this->state(['is_enabled' => false]);
    }

    public function webhook(string $url = 'https://nextcloud.example.com/apps/spreed/api/v1/room/support/webhook'): static
    {
        return $this->state(['config' => ['default_webhook_url' => $url]]);
    }
}