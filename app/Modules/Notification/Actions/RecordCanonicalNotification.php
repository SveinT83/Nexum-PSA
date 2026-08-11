<?php

namespace App\Modules\Notification\Actions;

use App\Models\Core\User;
use Illuminate\Database\QueryException;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RecordCanonicalNotification
{
    /**
     * Store one canonical Laravel database notification for a stable delivery identity.
     *
     * Notifications with in-app disabled are retained as already-read records so a Web Push or
     * email click still has one authoritative source without adding unread bell noise.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(
        User $user,
        string $notificationClass,
        string $deliveryIdentity,
        array $data,
        bool $unread = true,
    ): DatabaseNotification {
        $existing = $this->find($user, $deliveryIdentity);

        if ($existing) {
            return $existing;
        }

        try {
            DB::table('notifications')->insert([
                'id' => (string) Str::uuid(),
                'type' => $notificationClass,
                'delivery_identity' => $deliveryIdentity,
                'notifiable_type' => $user::class,
                'notifiable_id' => $user->id,
                'data' => json_encode($data, JSON_THROW_ON_ERROR),
                'read_at' => $unread ? null : now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $exception) {
            $existing = $this->find($user, $deliveryIdentity);

            if ($existing) {
                return $existing;
            }

            throw $exception;
        }

        return $this->find($user, $deliveryIdentity) ?? throw new \RuntimeException('Canonical notification was not stored.');
    }

    private function find(User $user, string $deliveryIdentity): ?DatabaseNotification
    {
        return $user->notifications()
            ->where('delivery_identity', $deliveryIdentity)
            ->first();
    }
}
