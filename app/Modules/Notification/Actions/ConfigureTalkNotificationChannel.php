<?php

namespace App\Modules\Notification\Actions;

use App\Modules\Nextcloud\Models\NextcloudConnection;
use App\Modules\Nextcloud\Models\NextcloudTalkDestination as Destination;
use App\Modules\Nextcloud\Services\TalkDestinationRegistry;
use App\Modules\Nextcloud\Services\TalkNotificationDelivery;
use App\Modules\Notification\Models\NotificationChannel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Global notification switch/default only; Nextcloud retains connection credentials and destinations. */
class ConfigureTalkNotificationChannel
{
    public function __construct(private readonly TalkDestinationRegistry $registry, private readonly TalkNotificationDelivery $delivery) {}

    public function update(Request $request, NotificationChannel $channel): NotificationChannel
    {
        // Obsolete credential URLs are neither accepted nor flashed back into browser sessions.
        if ($request->exists('config.default_webhook_url') || $request->exists('secrets')) {
            $config = is_array($request->input('config')) ? $request->input('config') : [];
            unset($config['default_webhook_url']);
            $request->merge(['config' => $config]);
            $request->request->remove('secrets');
            throw ValidationException::withMessages(['config' => 'Select a registered Talk conversation; webhook URLs are no longer normal setup.']);
        }
        $data = $request->validate(['is_enabled' => 'nullable|boolean', 'revision' => 'nullable|string|max:80',
            'config' => 'nullable|array:talk_destination_id,nextcloud_connection_id',
            'config.talk_destination_id' => 'nullable|integer|min:1',
            'config.nextcloud_connection_id' => 'nullable|integer|min:1']);
        $expected = $request->header('If-Match') ?? $request->input('revision');
        DB::transaction(function () use ($request, $channel, $data, $expected) {
            $fresh = NotificationChannel::lockForUpdate()->findOrFail($channel->id);
            abort_unless(is_string($expected) && hash_equals(ExecuteNotificationApiCommand::etag($fresh), $expected), 412, 'Notification channel changed; reload before saving.');
            $enabled = $request->boolean('is_enabled');
            $input = $data['config'] ?? [];
            $provided = array_key_exists('talk_destination_id', $input);
            $target = $provided
                ? ($this->registry->installed() ? Destination::find($input['talk_destination_id'] ?? 0) : null)
                : $this->delivery->globalDestination($fresh);
            if ($enabled || $target) {
                $this->registry->requireInstalled();
            }
            $connection = $target ? NextcloudConnection::lockForUpdate()->find($target->connection_id) : null;
            $target = $target ? Destination::lockForUpdate()->find($target->id) : null;
            if ($enabled && (! $target || ! $connection || ! $this->registry->eligible($connection, $target))) {
                throw ValidationException::withMessages(['config.talk_destination_id' => 'Choose an enabled, available destination on an active write-capable Talk connection.']);
            }
            if (isset($input['nextcloud_connection_id']) && (! $target
                || (int) $input['nextcloud_connection_id'] !== (int) $target->connection_id)) {
                throw ValidationException::withMessages(['config.nextcloud_connection_id' => 'The selected conversation belongs to another connection.']);
            }
            $config = $fresh->config ?? [];
            if ($target && $connection) {
                $config = array_replace($config, ['delivery_mode' => 'conversation',
                    'talk_destination_id' => $target->id, 'nextcloud_connection_id' => $connection->id,
                    'talk_conversation_token' => $target->conversation_token]);
            } elseif ($provided) {
                unset($config['talk_destination_id'], $config['nextcloud_connection_id'], $config['talk_conversation_token']);
                $config['delivery_mode'] = 'conversation';
            }
            $fresh->forceFill(['config' => $config, 'is_enabled' => $enabled])->save();
            activity('notification')->causedBy($request->user())->performedOn($fresh)
                ->withProperties(['driver' => 'nextcloud_talk', 'enabled' => $enabled,
                    'connection_id' => $target?->connection_id, 'destination_id' => $target?->id])
                ->log('Talk notification channel configured');
        }, 3);

        return $channel->fresh();
    }

    public function test(Request $request, NotificationChannel $channel): array
    {
        $channel = $channel->fresh();
        $expected = $request->header('If-Match') ?? $request->input('revision');
        abort_unless(is_string($expected), 428, 'Read the Talk channel before testing; its revision is required.');
        abort_unless(hash_equals(ExecuteNotificationApiCommand::etag($channel), $expected), 412, 'Talk channel changed; reload before testing.');
        $target = $this->delivery->globalDestination($channel);
        if (! $channel->is_enabled || ! $target) {
            return ['success' => false, 'state' => 'blocked', 'delivery_confirmed' => false,
                'message' => 'Enable Talk with a registered conversation before testing.'];
        }
        $identity = $request->header('Idempotency-Key') ?? $request->input('request_key');
        if (! is_string($identity) || strlen($identity) < 8 || strlen($identity) > 200) {
            throw ValidationException::withMessages(['request_key' => 'Reload the test form before sending.']);
        }
        $message = config('app.name', 'Nexum PSA').' Talk notification test. Reference: '.substr(hash('sha256', $identity), 0, 12);
        $result = $this->delivery->send($target, $message, $identity, 'test', $request->user()->id);
        $result['state'] = $result['state'] === 'confirmed' ? 'delivery_confirmed' : $result['state'];
        $result['message'] = match ($result['state']) {
            'delivery_confirmed' => 'Test message is stored in the selected Talk conversation (provider read-back). Human receipt is not verified.',
            'provider_accepted' => 'Talk accepted the message but read-back is unverified. Reconcile before sending again.',
            'unknown' => 'The Talk outcome is unknown. Reconcile before sending again.',
            'failed' => 'The selected Talk conversation rejected the test or could not be verified.',
            default => 'Talk delivery is blocked by the current connection, destination or channel settings.',
        };

        return $result;
    }
}
