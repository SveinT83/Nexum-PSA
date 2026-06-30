<?php

namespace App\Modules\Storage\Actions;

use App\Models\Core\User;
use App\Modules\Storage\Models\Item;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DeleteItem
{
    public function handle(Item $item, ?User $actor = null): void
    {
        DB::transaction(function () use ($item, $actor): void {
            $item->refresh();

            if ($item->qty_on_hand > 0) {
                throw new InvalidArgumentException('Storage item cannot be deleted while on-hand quantity is greater than 0.');
            }

            if ($item->qty_reserved > 0 || $item->reservations()->where('status', 'active')->exists()) {
                throw new InvalidArgumentException('Storage item cannot be deleted while stock is reserved.');
            }

            if ($item->stockUnits()->where('current_qty', '>', 0)->exists()) {
                throw new InvalidArgumentException('Storage item cannot be deleted while stock units still have quantity.');
            }

            $item->forceFill([
                'status' => 'inactive',
                'updated_by' => $actor?->id,
            ])->save();

            $item->delete();
        });
    }
}
