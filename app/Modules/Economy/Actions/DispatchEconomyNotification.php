<?php

namespace App\Modules\Economy\Actions;

use App\Http\Middleware\EnforceTechRoutePermission;
use App\Models\Core\User;
use App\Modules\Economy\Models\EconomyNotificationIntent;
use App\Modules\Economy\Models\EconomyOrder;
use App\Modules\Notification\Actions\SendCustomerPortalNotification;
use Illuminate\Support\Facades\DB;

/** Claim once after commit; ambiguous partial notification submission is never blindly retried. */
class DispatchEconomyNotification
{
    public function handle(string $id): void
    {
        $intent = DB::transaction(function () use ($id) {
            $intent = EconomyNotificationIntent::query()->lockForUpdate()->findOrFail($id);
            if ($intent->state !== 'pending') {
                return null;
            }
            $intent->update(['state' => 'processing']);

            return $intent;
        });
        if (! $intent) {
            return;
        }
        try {
            $payload = $intent->payload;
            $order = EconomyOrder::query()->find($intent->order_id);
            $actor = User::query()->find($payload['actor_id']);
            if (! $order?->isPortalVisible() || (int) $order->client_id !== (int) $payload['client_id'] || ! $actor?->isActive() || ! app(EnforceTechRoutePermission::class)->allowsActor($actor, 'economy.order_manage')) {
                $intent->update(['state' => 'superseded']);

                return;
            }
            unset($payload['actor_id']);
            $recipients = app(SendCustomerPortalNotification::class)->handle(
                type: $payload['type'], clientId: $payload['client_id'], siteId: null,
                title: $payload['title'], body: $payload['body'],
                url: route('customer-portal.orders.show', $order),
                sourceType: EconomyOrder::class, sourceId: $order->id,
                metadata: ['order_number' => $order->order_number, 'status' => $payload['status']],
            );
            $intent->update(['state' => 'submitted', 'recipients' => $recipients]);
        } catch (\Throwable $error) {
            $intent->update(['state' => 'unknown', 'error_code' => 'notification_submission_uncertain']);
        }
    }
}
