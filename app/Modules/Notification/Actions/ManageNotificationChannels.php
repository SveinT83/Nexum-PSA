<?php

namespace App\Modules\Notification\Actions;

use App\Modules\Contact\Models\Contact;
use App\Modules\Nextcloud\Models\NextcloudConnection;
use App\Modules\Notification\Models\NotificationChannel;
use App\Modules\Notification\Models\NotificationSmsMessage;
use App\Modules\Notification\Models\NotificationSmsTemplate;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;

/** Driver-specific native settings shared by browser and official API. */
class ManageNotificationChannels
{
    public function update(Request $request, NotificationChannel $channel): array
    {
        $configFields = match ($channel->driver) {
            'sms' => 'provider,sender_name,default_country_code',
            'nextcloud_talk' => 'nextcloud_connection_id,default_webhook_url',
            default => 'default_webhook_url,base_url',
        };
        $request->validate(['config' => 'nullable|array:'.$configFields, 'secrets' => $request->routeIs('api.*') && in_array($channel->driver, ['sms', 'nextcloud_talk'], true) ? 'prohibited' : 'nullable|array:api_token']);
        // An absent API credential/config field means retain, never expose and resubmit.
        if ($request->routeIs('api.*') && $channel->driver === 'nextcloud_talk') {
            $input = $request->input('config', []);
            if (! array_key_exists('default_webhook_url', $input)) {
                $input['default_webhook_url'] = $channel->config['default_webhook_url'] ?? null;
            }
            if (! array_key_exists('nextcloud_connection_id', $input)) {
                $input['nextcloud_connection_id'] = $channel->config['nextcloud_connection_id'] ?? null;
            }
            $request->merge(['config' => $input]);
        }

        if ($channel->driver === 'nextcloud_talk') {
            return $this->updateNextcloudTalkChannel($request, $channel);
        }

        if ($channel->driver === 'sms') {
            return $this->updateSmsChannel($request, $channel);
        }

        $validated = $request->validate([
            'is_enabled' => 'nullable|boolean',
            'config' => 'nullable|array',
            'config.default_webhook_url' => 'nullable|url:https|max:500',
            'config.base_url' => 'nullable|url:https|max:500',
            'secrets' => 'nullable|array',
            'secrets.api_token' => 'nullable|string|max:200',
        ]);

        $channel->is_enabled = $validated['is_enabled'] ?? false;

        if (isset($validated['config'])) {
            $channel->config = array_merge($channel->config ?? [], $validated['config']);
        }

        if (isset($validated['secrets']['api_token']) && ! empty($validated['secrets']['api_token'])) {
            $channel->setSecret('api_token', $validated['secrets']['api_token']);
        }

        $channel->save();

        return $this->summary($channel);
    }

    /**
     * Update dry-run SMS settings.
     */
    private function updateSmsChannel(Request $request, NotificationChannel $channel): array
    {
        $validated = $request->validate([
            'is_enabled' => ['nullable', 'boolean'],
            'config' => ['nullable', 'array'],
            'config.provider' => ['required', 'string', Rule::in(['dry_run'])],
            'config.sender_name' => ['nullable', 'string', 'max:40'],
            'config.default_country_code' => ['required', 'string', 'max:8', 'regex:/^\+[0-9]{1,7}$/'],
        ]);

        $config = array_merge($channel->config ?? [], [
            'provider' => $validated['config']['provider'],
            'sender_name' => trim((string) ($validated['config']['sender_name'] ?? '')) ?: 'Nexum',
            'default_country_code' => $validated['config']['default_country_code'],
        ]);

        $channel->forceFill([
            'is_enabled' => $request->boolean('is_enabled'),
            'config' => $config,
            'secrets' => null,
        ])->save();

        return $this->summary($channel);
    }

    /**
     * Update Nextcloud Talk settings while keeping Nextcloud connection data in the Nextcloud domain.
     */
    private function updateNextcloudTalkChannel(Request $request, NotificationChannel $channel): array
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
            'config.default_webhook_url' => [$wantsEnabled && $fallbackConnection ? 'required' : 'nullable', 'url:https', 'max:500'],
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
            return $this->summary($channel);
        }

        return $this->summary($channel);
    }

    /**
     * Test the connection to a notification channel.
     */
    public function test(Request $request, NotificationChannel $channel): array
    {
        if ($request->routeIs('api.*') && $channel->driver !== 'sms') {
            abort_unless(config('notification-api.external_tests_enabled'), 503, 'Real Notification API tests are disabled.');
        }
        $result = $this->testChannelConnection($channel, $request);
        $result['state'] ??= $result['success'] ? ($channel->driver === 'sms' ? 'dry_run' : 'provider_accepted') : 'blocked';
        $result['delivery_confirmed'] = false;
        $channel->forceFill(['last_tested_at' => now(), 'last_test_result' => $result['message']])->save();

        return $result;
    }

    /** Shared connection test returns evidence, never a raw provider response body. */
    protected function testChannelConnection(NotificationChannel $channel, Request $request): array
    {
        if ($channel->driver === 'sms') {
            return $this->testSmsChannel($request);
        }

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
                    return ['success' => true, 'state' => 'provider_accepted', 'message' => 'Webhook accepted the request; Talk delivery is unverified.'];
                }

                return ['success' => false, 'state' => 'failed', 'message' => 'Provider rejected the notification request.'];
            } catch (Exception $e) {
                return ['success' => false, 'state' => 'unknown', 'message' => 'Provider outcome is unknown; reconcile before sending again.'];
            }
        }

        return ['success' => false, 'message' => 'No test available for this channel driver.'];
    }

    /**
     * Log a dry-run SMS test send to a selected Contact.
     */
    private function testSmsChannel(Request $request): array
    {
        $validated = $request->validate([
            'test_contact_id' => ['required', 'integer', Rule::exists('contacts', 'id')],
            'test_template_key' => ['required', 'string', Rule::exists('notification_sms_templates', 'key')->where('is_active', true)],
        ]);

        $contact = Contact::query()->with('phones')->findOrFail($validated['test_contact_id']);
        $message = app(SendTransactionalSms::class)->handle(
            contact: $contact,
            templateKey: $validated['test_template_key'],
            actor: $request->user(),
            sourceType: 'notification_channel_test',
        );

        if ($message->status === NotificationSmsMessage::STATUS_DRY_RUN) {
            return ['success' => true, 'message' => 'Dry-run SMS logged.'];
        }

        return ['success' => false, 'message' => $message->failure_reason ?: 'SMS test was blocked.'];
    }

    /**
     * List active Nextcloud connections that can be used by notification delivery.
     */
    public function activeNextcloudConnections()
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

            return $connection;
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

    public function activeSmsTemplates()
    {
        return NotificationSmsTemplate::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    public function smsTestContacts()
    {
        return Contact::query()
            ->with('phones')
            ->where('status', 'active')
            ->whereHas('phones')
            ->orderBy('display_name')
            ->limit(100)
            ->get();
    }

    public function summary(NotificationChannel $channel): array
    {
        $config = $channel->config ?? [];

        return ['id' => $channel->id, 'name' => $channel->name, 'label' => $channel->label,
            'driver' => $channel->driver, 'is_enabled' => (bool) $channel->is_enabled,
            'config' => ['provider' => $config['provider'] ?? null, 'sender_name' => $config['sender_name'] ?? null,
                'default_country_code' => $config['default_country_code'] ?? null,
                'nextcloud_connection_id' => isset($config['nextcloud_connection_id']) ? (int) $config['nextcloud_connection_id'] : null],
            'webhook_configured' => filled($config['default_webhook_url'] ?? null),
            'base_url_configured' => filled($config['base_url'] ?? null),
            'api_token_configured' => filled($channel->secrets['api_token'] ?? null),
            'last_tested_at' => $channel->last_tested_at?->toISOString(),
            // Historical free-text provider errors cannot be exported as safe diagnostics.
            'last_test_recorded' => filled($channel->last_test_result),
            'etag' => ExecuteNotificationApiCommand::etag($channel)];
    }

    public function context(NotificationChannel $channel): array
    {
        return ['channel' => $this->summary($channel),
            'nextcloud_connections' => $channel->driver === 'nextcloud_talk'
                ? $this->activeNextcloudConnections()->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'is_default' => (bool) $c->is_default])->all() : [],
            'sms_templates' => $channel->driver === 'sms'
                ? $this->activeSmsTemplates()->map(fn ($t) => ['key' => $t->key, 'name' => $t->name])->all() : [],
            'sms_contacts' => $channel->driver === 'sms'
                ? $this->smsTestContacts()->map(fn ($c) => ['id' => $c->id, 'display_name' => $c->display_name])->all() : []];
    }
}
