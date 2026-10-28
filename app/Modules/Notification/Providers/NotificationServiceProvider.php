<?php

namespace App\Modules\Notification\Providers;

use App\Modules\Notification\Channels\ManagedWebPushChannel;
use App\Modules\Notification\Contracts\WebPushInstallationProfile;
use App\Modules\Notification\Support\LinuxWebPushInstallationProfile;
use Illuminate\Support\ServiceProvider;
use NotificationChannels\WebPush\WebPushChannel;

/** Domain-owned installation capability and fresh final Web Push transport. No initialization occurs at boot. */
final class NotificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(WebPushInstallationProfile::class, LinuxWebPushInstallationProfile::class);
        $this->app->bind(\App\Modules\Notification\Contracts\WebPushQueueEvidence::class, \App\Modules\Notification\Support\SystemWebPushQueueEvidence::class);
        $this->app->bind(WebPushChannel::class, ManagedWebPushChannel::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([\App\Modules\Notification\Commands\ApproveWebPushInstallation::class]);
        }
    }
}
