<?php

namespace App\Modules\Notification\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Nextcloud\Models\NextcloudConnection;
use App\Modules\Notification\Models\NotificationChannel;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    public function index(): View
    {
        $channels = NotificationChannel::orderBy('name')->get();

        return view('notification::Admin.channels.index', [
            'channels' => $channels,
        ]);
    }

    /**
     * Show edit form for a notification channel.
     */
    public function edit(NotificationChannel $channel): View
    {
        $nextcloudConnection = $channel->driver === 'nextcloud_talk'
            ? $this->activeNextcloudConnection()
            : null;

        return view('notification::Admin.channels.edit', [
            'channel' => $channel,
            'nextcloudConnection' => $nextcloudConnection,
            'nextcloudReady' => (bool) $nextcloudConnection,
        ]);
    }

    /**
     * Update a notification channel's configuration.
     */
    public function update(Request $request, NotificationChannel $channel): RedirectResponse
    {
        if ($channel->driver === 'nextcloud_talk') {
            return $this->updateNextcloudTalkChannel($request, $channel);
        }

        $validated = $request->validate([
            'is_enabled' => 'nullable|boolean',
            'config' => 'nullable|array',
            'config.default_webhook_url' => 'nullable|url|max:500',
            'config.base_url' => 'nullable|url|max:500',
            'secrets' => 'nullable|array',
            'secrets.api_token' => 'nullable|string|max:200',
        ]);

        $channel->is_enabled = $validated['is_enabled'] ?? false;

        if (isset($validated['config'])) {
            $channel->config = array_merge($channel->config ?? [], $validated['config']);
        }

        if (isset($validated['secrets']['api_token']) && !empty($validated['secrets']['api_token'])) {
            $channel->setSecret('api_token', $validated['secrets']['api_token']);
        }

        $channel->save();

        return redirect()->route('tech.admin.notification-channels.edit', $channel)
            ->with('success', "Notification channel '{$channel->label}' updated.");
    }

    /**
     * Update Nextcloud Talk settings while keeping Nextcloud connection data in the Nextcloud domain.
     */
    private function updateNextcloudTalkChannel(Request $request, NotificationChannel $channel): RedirectResponse
    {
        $nextcloudConnection = $this->activeNextcloudConnection();
        $wantsEnabled = $request->boolean('is_enabled');

        $validated = $request->validate([
            'is_enabled' => 'nullable|boolean',
            'config' => 'nullable|array',
            'config.default_webhook_url' => [$wantsEnabled && $nextcloudConnection ? 'required' : 'nullable', 'url', 'max:500'],
        ]);

        $config = $channel->config ?? [];
        unset($config['base_url']);

        if (array_key_exists('default_webhook_url', $validated['config'] ?? [])) {
            $config['default_webhook_url'] = $validated['config']['default_webhook_url'];
        }

        $secrets = $channel->secrets ?? [];
        unset($secrets['api_token']);

        $channel->config = $config;
        $channel->secrets = $secrets ?: null;
        $channel->is_enabled = $nextcloudConnection ? $wantsEnabled : false;
        $channel->save();

        if (! $nextcloudConnection) {
            return redirect()->route('tech.admin.notification-channels.edit', $channel)
                ->with('warning', 'Nextcloud Talk was saved, but the channel is disabled until a Nextcloud integration is configured.');
        }

        return redirect()->route('tech.admin.notification-channels.edit', $channel)
            ->with('success', "Notification channel '{$channel->label}' updated.");
    }

    /**
     * Test the connection to a notification channel.
     */
    public function test(NotificationChannel $channel): RedirectResponse
    {
        $result = $this->testChannelConnection($channel);

        $channel->last_tested_at = now();
        $channel->last_test_result = $result['success'] ? 'OK' : $result['message'];
        $channel->save();

        if ($result['success']) {
            return redirect()->route('tech.admin.notification-channels.edit', $channel)
                ->with('success', "Test successful: {$result['message']}");
        }

        return redirect()->route('tech.admin.notification-channels.edit', $channel)
            ->with('warning', "Test failed: {$result['message']}");
    }

    /**
     * Send a test notification to the channel.
     */
    protected function testChannelConnection(NotificationChannel $channel): array
    {
        if ($channel->driver === 'nextcloud_talk') {
            if (! $this->activeNextcloudConnection()) {
                return ['success' => false, 'message' => 'Nextcloud integration is not configured.'];
            }

            $webhookUrl = $channel->config['default_webhook_url'] ?? null;

            if (empty($webhookUrl)) {
                return ['success' => false, 'message' => 'No default webhook URL configured.'];
            }

            try {
                $response = Http::timeout(10)->post($webhookUrl, [
                    'message' => 'Test notification from Nexum-PSA - ' . now()->toDateTimeString(),
                ]);

                if ($response->successful()) {
                    return ['success' => true, 'message' => 'Webhook delivered successfully.'];
                }

                return ['success' => false, 'message' => "HTTP {$response->status()}: {$response->body()}"];
            } catch (Exception $e) {
                return ['success' => false, 'message' => $e->getMessage()];
            }
        }

        return ['success' => false, 'message' => 'No test available for this channel driver.'];
    }

    /**
     * Resolve the active Nextcloud connection used by notification delivery setup.
     */
    private function activeNextcloudConnection(): ?NextcloudConnection
    {
        return NextcloudConnection::query()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->first();
    }
}
