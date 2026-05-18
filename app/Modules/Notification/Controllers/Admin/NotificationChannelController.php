<?php

namespace App\Modules\Notification\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Notification\Models\NotificationChannel;
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
        return view('notification::Admin.channels.edit', [
            'channel' => $channel,
        ]);
    }

    /**
     * Update a notification channel's configuration.
     */
    public function update(Request $request, NotificationChannel $channel): RedirectResponse
    {
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
            $webhookUrl = $channel->config['default_webhook_url'] ?? null;

            if (empty($webhookUrl)) {
                return ['success' => false, 'message' => 'No default webhook URL configured.'];
            }

            try {
                $response = \Http::timeout(10)->post($webhookUrl, [
                    'message' => '🔔 Test notification from Nexum-PSA — ' . now()->toDateTimeString(),
                ]);

                if ($response->successful()) {
                    return ['success' => true, 'message' => 'Webhook delivered successfully.'];
                }

                return ['success' => false, 'message' => "HTTP {$response->status()}: {$response->body()}"];
            } catch (\Exception $e) {
                return ['success' => false, 'message' => $e->getMessage()];
            }
        }

        return ['success' => false, 'message' => 'No test available for this channel driver.'];
    }
}