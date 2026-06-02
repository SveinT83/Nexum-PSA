<?php

namespace App\Modules\Notification\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Nextcloud\Models\NextcloudConnection;
use App\Modules\Notification\Models\NotificationChannel;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
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
        $fallbackConnection = $this->defaultNextcloudConnection();
        $wantsEnabled = $request->boolean('is_enabled');

        $validated = $request->validate([
            'is_enabled' => 'nullable|boolean',
            'config' => 'nullable|array',
            'config.nextcloud_connection_id' => [
                'nullable',
                'integer',
                Rule::exists('nextcloud_connections', 'id')->where('is_active', true),
            ],
            'config.default_webhook_url' => [$wantsEnabled && $fallbackConnection ? 'required' : 'nullable', 'url', 'max:500'],
        ]);

        $config = $channel->config ?? [];
        unset($config['base_url']);

        $selectedConnectionId = $validated['config']['nextcloud_connection_id'] ?? $fallbackConnection?->id;

        if ($selectedConnectionId) {
            $config['nextcloud_connection_id'] = (int) $selectedConnectionId;
        } else {
            unset($config['nextcloud_connection_id']);
        }

        if (array_key_exists('default_webhook_url', $validated['config'] ?? [])) {
            $config['default_webhook_url'] = $validated['config']['default_webhook_url'];
        }

        $secrets = $channel->secrets ?? [];
        unset($secrets['api_token']);

        $channel->config = $config;
        $channel->secrets = $secrets ?: null;
        $channel->is_enabled = $selectedConnectionId ? $wantsEnabled : false;
        $channel->save();

        if (! $selectedConnectionId) {
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
            if (! $this->selectedNextcloudConnection($channel)) {
                return ['success' => false, 'message' => 'Nextcloud integration is not configured.'];
            }

            $webhookUrl = $channel->config['default_webhook_url'] ?? null;

            if (empty($webhookUrl)) {
                return ['success' => false, 'message' => 'No default webhook URL configured.'];
            }

            try {
                $response = Http::timeout(10)->post($webhookUrl, [
                    'message' => 'Test notification from '.config('app.name', 'Nexum PSA').' - '.now()->toDateTimeString(),
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
}
