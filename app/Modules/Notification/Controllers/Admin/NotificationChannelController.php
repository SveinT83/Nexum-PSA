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
 * Configure external integrations like Nextcloud Talk conversations,
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
        $talkConnections = $channel->driver === 'nextcloud_talk'
            ? app(\App\Modules\Nextcloud\Services\TalkDestinationRegistry::class)->globalChoices() : [];
        $talkReady = collect($talkConnections)->flatMap(fn ($connection) => $connection['destinations'])
            ->contains(fn ($destination) => $destination['eligible']);

        return view('notification::Admin.channels.edit', [
            'channel' => $channel,
            'talkConnections' => $talkConnections,
            'nextcloudReady' => $talkReady,
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
            ->with('success',
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
