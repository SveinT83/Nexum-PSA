<?php

namespace App\Modules\Storage\Actions;

use App\Models\Core\User;
use App\Modules\Storage\Models\Item;
use App\Modules\Storage\Models\Movement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StoreItem
{
    public function handle(array $data, ?User $actor = null): Item
    {
        return DB::transaction(function () use ($data, $actor) {
            $initialQuantity = (int) ($data['initial_quantity'] ?? 0);
            unset($data['initial_quantity']);

            $data['sku'] = Str::upper($data['sku']);
            $data['created_by'] = $actor?->id;
            $data['updated_by'] = $actor?->id;
            $data['qty_on_hand'] = max(0, $initialQuantity);
            $data['qty_reserved'] = 0;

            $item = Item::create($data);

            if ($initialQuantity > 0) {
                Movement::create([
                    'item_id' => $item->id,
                    'actor_id' => $actor?->id,
                    'type' => 'receive',
                    'qty_before' => 0,
                    'qty_delta' => $initialQuantity,
                    'qty_after' => $initialQuantity,
                    'to_warehouse_id' => $item->warehouse_id,
                    'to_box_id' => $item->box_id,
                    'reason' => 'initial_stock',
                    'note' => 'Initial quantity on item creation.',
                ]);
            }

            return $item;
        });
    }
}
