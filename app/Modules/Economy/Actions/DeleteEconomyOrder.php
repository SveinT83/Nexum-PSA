<?php

namespace App\Modules\Economy\Actions;

use App\Modules\Economy\Models\EconomyOrder;
use App\Modules\Economy\Models\EconomyOrderLine;
use App\Modules\Economy\Support\EconomyMutex;

/** Parent, child, source and optional last-line removal commit as one serialized operation. */
class DeleteEconomyOrder
{
    public function handle(int $id, ?int $lineId = null): array
    {
        return app(EconomyMutex::class)->run(function () use ($id, $lineId) {
            $order = EconomyOrder::query()->lockForUpdate()->findOrFail($id);
            if ($lineId !== null) {
                $line = EconomyOrderLine::query()->where('economy_order_id', $id)->lockForUpdate()->findOrFail($lineId);
                app(DeleteOrderLine::class)->handle($order, $line);
            } else {
                abort_unless(in_array($order->status, ['draft', 'ready'], true), 422, 'Only empty draft or ready orders may be deleted.');
                abort_if($order->lines()->exists(), 422, 'Delete or correct draft lines before removing the order.');
            }
            $deleted = ! $order->lines()->exists();
            if ($deleted) {
                $order->delete();
            }

            return ['deleted_order' => $deleted, 'order_id' => $id, 'deleted_line_id' => $lineId];
        });
    }
}
