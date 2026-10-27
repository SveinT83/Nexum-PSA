<?php

namespace App\Modules\Notification\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Contact\Models\Contact;
use App\Modules\Nextcloud\Models\NextcloudConnection;
use App\Modules\Notification\Models\NotificationChannel;
use App\Modules\Notification\Models\NotificationSmsTemplate;
use App\Modules\Notification\Models\WebPushSubscription;
use App\Modules\Notification\Support\WebPushReadiness;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * Admin controller for managing system-wide notification channels.
 *
 * Configure external integrations like Nextcloud Talk webhooks,
 * test connectivity, and enable/disable channels.
 */
class NotificationChannelController extends Controller
{
    /**
     * Show all notification channels and their status.
     */
    public function index(WebPushReadiness $webPushReadiness): View
    {
        $channels = NotificationChannel::orderBy('name')->get();
        $webPushTableExists = Schema::connection(config('webpush.database_connection'))
            ->hasTable(config('webpush.table_name'));

        return view('notification::Admin.channels.index', [
            'channels' => $channels,
            'webPushReadiness' => $webPushReadiness->toArray(),
            'webPushDeviceCount' => $webPushTableExists
                ? WebPushSubscription::query()->count()
                : 0,
        ]);
    }

    /**
     * Show edit form for a notification channel.
     */
    public function edit(NotificationChannel $channel): View
    {
        $nextcloudConnections = $channel->driver === 'nextcloud_talk'
            ? $this->activeNextcloudConnections()
            : collect();
        $nextcloudConnection = $channel->driver === 'nextcloud_talk'
            ? $this->selectedNextcloudConnection($channel)
            : null;

        return view('notification::Admin.channels.edit', [
            'channel' => $channel,
            'nextcloudConnections' => $nextcloudConnections,
            'nextcloudConnection' => $nextcloudConnection,
            'nextcloudReady' => (bool) $nextcloudConnection,
            'smsTemplates' => $channel->driver === 'sms' ? $this->activeSmsTemplates() : collect(),
            'smsTestContacts' => $channel->driver === 'sms' ? $this->smsTestContacts() : collect(),
        ]);
    }

    /**
     * Update a notification channel's configuration.
     */
    public function update(Request $request, NotificationChannel $channel): RedirectResponse
    {
        app(\App\Modules\Notification\Actions\ManageNotificationChannels::class)->update($request, $channel);

        return redirect()->route('tech.admin.notification-channels.edit', $channel)
            ->with($channel->driver === 'nextcloud_talk' && ! $channel->is_enabled && ! NextcloudConnection::where('is_active', true)->exists() ? 'warning' : 'success',
                'Notification channel settings saved.');
    }

    public function test(Request $request, NotificationChannel $channel): RedirectResponse
    {
        $result = app(\App\Modules\Notification\Actions\ManageNotificationChannels::class)->test($request, $channel);

        return redirect()->route('tech.admin.notification-channels.edit', $channel)
            ->with($result['success'] ? 'success' : 'warning', $result['message']);
    }

    /**
     * List active Nextcloud connections that can be used by notification delivery.
     */
    private function activeNextcloudConnections()
    {
        return NextcloudConnection::query()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderByRaw("case when scope = 'global' then 1 else 0 end desc")
            ->orderBy('name')
            ->get();
    }

    /**
     * Resolve the configured Nextcloud connection, falling back to global default.
     */
    private function selectedNextcloudConnection(NotificationChannel $channel): ?NextcloudConnection
    {
        $connectionId = $channel->config['nextcloud_connection_id'] ?? null;

        if ($connectionId) {
            $connection = NextcloudConnection::query()
                ->where('is_active', true)
                ->find($connectionId);

            if ($connection) {
                return $connection;
            }
        }

        return $this->defaultNextcloudConnection();
    }

    /**
     * Prefer the active global default Nextcloud connection when no explicit choice exists.
     */
    private function defaultNextcloudConnection(): ?NextcloudConnection
    {
        return NextcloudConnection::query()
            ->where('is_active', true)
            ->orderByRaw("case when scope = 'global' and is_default = 1 then 1 else 0 end desc")
            ->orderByDesc('is_default')
            ->orderByRaw("case when scope = 'global' then 1 else 0 end desc")
            ->orderBy('name')
            ->first();
    }

    private function activeSmsTemplates()
    {
        return NotificationSmsTemplate::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    private function smsTestContacts()
    {
        return Contact::query()
            ->with('phones')
            ->where('status', 'active')
            ->whereHas('phones')
            ->orderBy('display_name')
            ->limit(100)
            ->get();
    }
}
