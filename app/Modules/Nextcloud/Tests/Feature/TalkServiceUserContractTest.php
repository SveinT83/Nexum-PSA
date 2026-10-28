<?php

namespace App\Modules\Nextcloud\Tests\Feature;

use App\Modules\Nextcloud\Models\NextcloudConnection;
use App\Modules\Nextcloud\Services\NextcloudTalkClient;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Provider contract probes use synthetic unsaved models and never send to real services. */
class TalkServiceUserContractTest extends TestCase
{
    private function connection(): NextcloudConnection
    {
        return new NextcloudConnection(['name' => 'Contract fixture', 'base_url' => 'https://talk.example.test',
            'service_username' => 'nexum-fixture', 'service_password' => 'fixture-not-a-live-secret',
            'scope' => 'global', 'mode' => 'managed', 'is_active' => true]);
    }

    #[Test]
    public function service_user_discovery_uses_room_v4_with_current_types_and_no_presence_update(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://talk.example.test/ocs/v2.php/apps/spreed/api/v4/room*' => Http::response([
            'ocs' => ['meta' => ['statuscode' => 100], 'data' => [
                ['token' => 'group123', 'name' => 'Internal', 'type' => 2, 'readOnly' => 0],
                ['token' => 'direct123', 'name' => 'Direct', 'type' => 1, 'readOnly' => 0],
            ]],
        ])]);
        $rooms = app(NextcloudTalkClient::class)->listConversations($this->connection());
        $this->assertSame('group', $rooms[0]['typeLabel']);
        $this->assertSame('one-to-one', $rooms[1]['typeLabel']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v4/room')
            && (int) $request['noStatusUpdate'] === 1);
    }

    #[Test]
    public function html_success_page_is_not_a_successful_talk_response(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('<html>Ordinary page</html>', 200, ['Content-Type' => 'text/html'])]);
        $this->expectException(\RuntimeException::class);
        app(NextcloudTalkClient::class)->listConversations($this->connection());
    }

    #[Test]
    public function creating_a_private_group_uses_current_type_and_endpoint(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://talk.example.test/ocs/v2.php/apps/spreed/api/v4/room' => Http::response([
            'ocs' => ['meta' => ['statuscode' => 100], 'data' => ['token' => 'newgroup', 'type' => 2]],
        ], 201)]);
        $room = app(NextcloudTalkClient::class)->createConversation($this->connection(), 'Nexum test group');
        $this->assertSame('newgroup', $room['token']);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['roomType'] === 2);
    }

    #[Test]
    public function exact_message_readback_does_not_mark_chat_or_notifications_read(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['ocs' => ['meta' => ['statuscode' => 100],
            'data' => [['id' => 42, 'token' => 'group123', 'message' => 'PRIVATE-BODY-CANARY', 'referenceId' => 'ref123']]]])]);
        $result = app(NextcloudTalkClient::class)->readMessageReceipt($this->connection(), 'group123', 42);
        $this->assertSame(['message_id' => 42, 'conversation_token' => 'group123', 'reference_id' => 'ref123'], $result);
        $this->assertStringNotContainsString('PRIVATE-BODY-CANARY', json_encode($result));
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && (int) $request['setReadMarker'] === 0 && (int) $request['noStatusUpdate'] === 1
            && (int) $request['markNotificationsAsRead'] === 0 && (int) $request['includeLastKnown'] === 1);
    }
}
