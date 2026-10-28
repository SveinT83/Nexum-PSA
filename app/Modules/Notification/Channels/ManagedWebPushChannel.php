<?php

namespace App\Modules\Notification\Channels;

use App\Modules\Notification\Exceptions\TemporaryWebPushDeliveryException;
use App\Modules\Notification\Services\WebPushManagedRuntime;
use App\Modules\Notification\Support\AuditedWebPushReportHandler;
use App\Modules\Notification\Support\LinuxWebPushInstallationProfile;
use Illuminate\Notifications\Notification;
use Minishlink\WebPush\WebPush;
use NotificationChannels\WebPush\WebPushChannel;

/** Cached channel managers resolve a fresh protected identity/kill switch at the final transport boundary. */
final class ManagedWebPushChannel extends WebPushChannel
{
    public function __construct(private readonly WebPushManagedRuntime $runtime) {}

    public function runtimeContract(): string
    {
        return LinuxWebPushInstallationProfile::CONTRACT;
    }

    public function runtimeIdentityVersion(): ?string
    {
        return $this->runtime->summary()['identity_version'];
    }

    public function runtimeRevision(): string
    {
        return $this->runtime->summary()['revision'];
    }

    public function send(#[\SensitiveParameter] mixed $notifiable, #[\SensitiveParameter] Notification $notification): void
    {
        $identity = $this->runtime->effective();
        if (! $identity['enabled'] || $identity['issues'] !== []) {
            return;
        }
        try {
            $vapid = ['subject' => $identity['subject'], 'publicKey' => $identity['public_key'], 'privateKey' => $identity['private_key']];
            if (! $identity['managed'] && filled(config('webpush.vapid.pem_file'))) {
                $pem = config('webpush.vapid.pem_file');
                $vapid['pemFile'] = str_starts_with($pem, 'storage') ? base_path($pem) : $pem;
            }
            $transport = (new WebPush(['VAPID' => $vapid], [], 30, config('webpush.client_options', [])))
                ->setReuseVAPIDHeaders(true)->setAutomaticPadding(config('webpush.automatic_padding', true));
        } catch (\Throwable) {
            throw new TemporaryWebPushDeliveryException('Web Push identity/transport is unavailable; inspect safe setup evidence.');
        }
        // Keep the current scoped report handler, including any existing inbound aggregate decision.
        $channel = new WebPushChannel($transport, app(AuditedWebPushReportHandler::class));
        try {
            $channel->send($notifiable, $notification);
        } catch (TemporaryWebPushDeliveryException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new TemporaryWebPushDeliveryException('Web Push transport outcome is unavailable.');
        }
    }
}
