<?php

namespace App\Modules\Nextcloud\Actions;

use App\Modules\Nextcloud\Models\NextcloudConnection;
use App\Modules\Nextcloud\Models\NextcloudTalkDestination as Destination;
use App\Modules\Nextcloud\Services\TalkConversationCreation;
use App\Modules\Nextcloud\Services\TalkDestinationRegistry;
use App\Modules\Nextcloud\Services\TalkNotificationDelivery;
use App\Modules\Nextcloud\Support\NextcloudApiAccess;
use Illuminate\Http\Request;

/** One native operation boundary supplies browser and official API behavior. */
class ManageTalkNotifications
{
    public function __construct(private readonly TalkDestinationRegistry $registry,
        private readonly TalkNotificationDelivery $delivery, private readonly TalkConversationCreation $creation) {}

    public function authorize(Request $request): \App\Models\Core\User
    {
        $actor = NextcloudApiAccess::actor();
        $request->setUserResolver(fn () => $actor);
        NextcloudApiAccess::authorize($actor, ['nextcloud.connection_manage', 'nextcloud.talk_manage']);
        foreach ($request->route()?->defaults['nextcloud_abilities'] ?? [] as $ability) {
            abort_unless($actor->tokenCan($ability), 403, 'Current Talk API scopes are required.');
        }

        return $actor;
    }

    public function context(Request $request, NextcloudConnection $connection): array
    {
        $this->authorize($request);

        return $this->registry->context($connection);
    }

    public function refresh(Request $request, NextcloudConnection $connection): array
    {
        $this->authorize($request);
        $data = $request->validate(['revision' => 'required|string|max:80']);
        $result = $this->registry->refresh($connection, $data['revision']);
        $this->authorize($request);

        return $result;
    }

    public function update(Request $request, NextcloudConnection $connection): array
    {
        $this->authorize($request);
        // Browser unchecked boxes form an explicit empty replacement set; APIs send an array.
        if (! $request->routeIs('api.*') && ! $request->exists('destination_ids')) {
            $request->merge(['destination_ids' => []]);
        }
        $data = $request->validate(['revision' => 'required|string|max:80', 'enabled' => 'nullable|boolean',
            'destination_ids' => 'present|array|max:2000', 'destination_ids.*' => 'required|integer|min:1|distinct',
            'default_destination_id' => 'nullable|integer|min:1']);

        return $this->registry->update($connection, $request->boolean('enabled'), $data['destination_ids'],
            isset($data['default_destination_id']) ? (int) $data['default_destination_id'] : null, $data['revision']);
    }

    public function create(Request $request, NextcloudConnection $connection): array
    {
        $actor = $this->authorize($request);
        $data = $request->validate(['revision' => 'required|string|max:80', 'name' => 'required|string|max:255',
            'confirm' => 'required|accepted']);
        $identity = $request->header('Idempotency-Key') ?? $request->input('request_key');
        abort_unless(is_string($identity), 428, 'A Talk action identity is required.');
        $result = $this->creation->create($connection, $data['name'], $data['revision'], $identity, $actor->id);
        $this->authorize($request);

        return $result;
    }

    public function test(Request $request, NextcloudConnection $connection, Destination $destination): array
    {
        $actor = $this->authorize($request);
        abort_unless((int) $destination->connection_id === (int) $connection->id, 404);
        $data = $request->validate(['revision' => 'required|string|max:80', 'confirm' => 'required|accepted']);
        $identity = $request->header('Idempotency-Key') ?? $request->input('request_key');
        abort_unless(is_string($identity) && strlen($identity) >= 8 && strlen($identity) <= 200, 428, 'A Talk action identity is required.');
        $message = config('app.name', 'Nexum PSA').' Talk notification test. Reference: '.substr(hash('sha256', $identity), 0, 12);
        $result = $this->delivery->send($destination, $message, $identity, 'test', $actor->id, $data['revision']);
        $this->authorize($request);

        return $result;
    }
}
