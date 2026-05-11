<?php

namespace App\Modules\Storage\Actions;

use App\Models\Core\User;
use App\Modules\Storage\Models\Item;
use App\Modules\Storage\Models\Movement;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AdjustItemStock
{
    public function handle(Item $item, int $delta, string $reason, ?string $note = null, ?User $actor = null): Item
    {
        return DB::transaction(function () use ($item, $delta, $reason, $note, $actor) {
            $item->refresh();
            $before = $item->qty_on_hand;
            $after = $before + $delta;

            if ($after < 0) {
                throw new InvalidArgumentException('Stock adjustment cannot make on-hand quantity negative.');
            }

            $item->qty_on_hand = $after;
            $item->updated_by = $actor?->id;
            $item->save();

            Movement::create([
                'item_id' => $item->id,
                'actor_id' => $actor?->id,
                'type' => 'adjust',
                'qty_before' => $before,
                'qty_delta' => $delta,
                'qty_after' => $after,
                'from_warehouse_id' => $item->warehouse_id,
                'to_warehouse_id' => $item->warehouse_id,
                'from_box_id' => $item->box_id,
                'to_box_id' => $item->box_id,
                'reason' => $reason,
                'note' => $note,
            ]);

            return $item;
        });
    }
}
