<?php

namespace App\Modules\Nextcloud\Actions;

use App\Modules\Nextcloud\Models\NextcloudConnection;

class UpdateNextcloudConnection
{
    public function __construct(private readonly StoreNextcloudConnection $storeConnection) {}

    public function handle(NextcloudConnection $connection, array $data): NextcloudConnection
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($connection, $data) {
            $connection = NextcloudConnection::lockForUpdate()->findOrFail($connection->id);
            CalendarApiAccess::idle($connection);
            $before = $connection->only(['scope', 'mode', 'client_id', 'client_site_id', 'base_url', 'service_username']);
            $credential = $connection->getRawOriginal('service_password');
            $connection->fill($this->storeConnection->payload($data, $connection));
            if ($before !== $connection->only(array_keys($before)) || $credential !== $connection->getAttributes()['service_password']) {
                $connection->settings = array_replace($connection->settings ?? [], ['automatic_calendar_sync_enabled' => false]);
            }
            $connection->save();
            $this->storeConnection->enforceDefault($connection);

            return $connection;
        });
    }
}
