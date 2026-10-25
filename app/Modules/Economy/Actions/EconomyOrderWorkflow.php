<?php

namespace App\Modules\Economy\Actions;

use App\Models\Core\User;
use App\Modules\CustomerPortal\Actions\RecordCustomerPortalAudit;
use App\Modules\Economy\Models\EconomyNotificationIntent;
use App\Modules\Economy\Models\EconomyOrder;
use App\Modules\Economy\Support\EconomyMutex;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** The web and API use the same state, publication, audit and notification operation. */
class EconomyOrderWorkflow
{
    public function handle(int $id, string $action, User $actor, ?bool $visible = null, ?Request $request = null): array
    {
        return app(EconomyMutex::class)->run(function () use ($id, $action, $actor, $visible, $request) {
            $order = EconomyOrder::query()->lockForUpdate()->findOrFail($id);
            $intent = null;
            if ($action === 'visibility') {
                abort_unless($visible !== null, 422, 'Choose whether the order is visible.');
                abort_if($visible && ! $order->client_id, 422, 'Only client-scoped orders can be shown in the customer portal.');
                if ($order->isPortalVisible() === $visible) {
                    return ['order_id' => $order->id, 'notification_intents' => []];
                }
                $order->forceFill(['portal_visible_at' => $visible ? now() : null, 'portal_visible_by' => $visible ? $actor->id : null, 'updated_by' => $actor->id])->save();
                app(RecordCustomerPortalAudit::class)->handle(
                    $visible ? 'portal_economy_order_visibility_enabled' : 'portal_economy_order_visibility_disabled',
                    null, $actor, null, $order->client, null,
                    ['economy_order_id' => $order->id, 'order_number' => $order->order_number, 'status' => $order->status], $request,
                );
                if ($visible) {
                    $intent = $this->notification($order, $actor, 'portal_order_published', 'New order available', $order->order_number ?? 'Order');
                }
            } else {
                $allowed = ['ready' => ['draft'], 'draft' => ['ready'], 'manual_invoiced' => ['ready', 'approved']];
                abort_unless(isset($allowed[$action]) && in_array($order->status, $allowed[$action], true), 422, 'This state transition is not available for this order.');
                $changes = ['status' => $action, 'updated_by' => $actor->id];
                if ($action === 'ready') {
                    $changes['ready_at'] = now();
                } elseif ($action === 'draft') {
                    $changes['ready_at'] = null;
                }
                $order->forceFill($changes)->save();
                if ($order->isPortalVisible() && $order->client_id) {
                    $label = ['ready' => 'Ready for billing', 'draft' => 'Moved back to draft', 'manual_invoiced' => 'Marked as invoiced'][$action];
                    $intent = $this->notification($order, $actor, 'portal_order_status_changed', 'Order '.$order->order_number.' status changed', $label);
                }
            }

            return ['order_id' => $order->id, 'notification_intents' => $intent ? [$intent->id] : []];
        });
    }

    private function notification(EconomyOrder $order, User $actor, string $type, string $title, string $body): EconomyNotificationIntent
    {
        $intent = EconomyNotificationIntent::query()->create([
            'id' => (string) Str::uuid(), 'order_id' => $order->id, 'state' => 'pending',
            'payload' => ['actor_id' => $actor->id, 'client_id' => $order->client_id, 'status' => $order->status, 'type' => $type, 'title' => $title, 'body' => $body],
        ]);
        DB::afterCommit(fn () => app(DispatchEconomyNotification::class)->handle($intent->id));

        return $intent;
    }
}
