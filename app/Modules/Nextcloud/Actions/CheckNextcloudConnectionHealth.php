<?php

namespace App\Modules\Nextcloud\Actions;

use App\Modules\Nextcloud\Models\NextcloudConnection;
use App\Modules\Nextcloud\Services\NextcloudReadClient;
use Throwable;

class CheckNextcloudConnectionHealth
{
    public function __construct(private readonly NextcloudReadClient $client)
    {
    }

    public function handle(NextcloudConnection $connection): array
    {
        $connection->forceFill([
            'last_health_check_at' => now(),
        ]);

        if (! $connection->service_username || ! $connection->service_password) {
            return $this->finish($connection, false, 'Missing service username or password.');
        }

        try {
            $connection->forceFill([
                'capabilities' => $this->client->capabilities($connection),
            ]);

            return $this->finish($connection, true, null);
        } catch (Throwable $exception) {
            return $this->finish($connection, false, $exception->getMessage());
        }
    }

    private function finish(NextcloudConnection $connection, bool $success, ?string $message): array
    {
        $connection->forceFill([
            'health_status' => $success ? 'healthy' : 'error',
            'last_error' => $message,
        ])->save();

        return [
            'success' => $success,
            'message' => $message,
        ];
    }
}
