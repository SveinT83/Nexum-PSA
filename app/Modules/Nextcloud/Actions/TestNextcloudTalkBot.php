<?php

namespace App\Modules\Nextcloud\Actions;

use App\Modules\Nextcloud\Models\NextcloudConnection;
use App\Modules\Nextcloud\Services\NextcloudTalkClient;

/** A provider acknowledgement identifies the message; it does not prove human receipt. */
class TestNextcloudTalkBot
{
    public function handle(NextcloudConnection $connection, string $reference): array
    {
        abort_unless($connection->hasTalkBot() && filled($connection->talk_default_conversation_token), 422, 'Configure the Talk bot and exact conversation first.');
        $result = app(NextcloudTalkClient::class)->sendBotMessage($connection, $connection->talk_default_conversation_token,
            'Test message from Nexum - Talk bot integration test.', ['referenceId' => $reference]);
        if (empty($result['id'])) {
            throw new \RuntimeException('Talk returned no message identity; reconcile before retry.');
        }

        return ['connection_id' => $connection->id, 'conversation_token' => $connection->talk_default_conversation_token,
            'message_id' => (int) $result['id'], 'reference_id' => $reference, 'state' => 'acknowledged', 'delivery_verified' => false];
    }
}
