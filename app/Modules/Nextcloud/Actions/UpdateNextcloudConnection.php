<?php

namespace App\Modules\Nextcloud\Actions;

use App\Modules\Nextcloud\Models\NextcloudConnection;

class UpdateNextcloudConnection
{
    public function __construct(private readonly StoreNextcloudConnection $storeConnection)
    {
    }

    public function handle(NextcloudConnection $connection, array $data): NextcloudConnection
    {
        $connection->fill($this->storeConnection->payload($data, $connection));
        $connection->save();

        $this->storeConnection->enforceDefault($connection);

        return $connection;
    }
}
