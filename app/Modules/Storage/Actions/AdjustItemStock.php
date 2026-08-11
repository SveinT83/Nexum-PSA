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
        return DB::transaction(function () use ($item, $delta, $reason, $note, $actor): Item {
            $lockedItem = $this->lockItem($item);

            return $this->recordLockedAdjustment($lockedItem, $delta, $reason, $note, $actor);
        });
    }

    /**
     * Set an absolute balance while holding the same row lock used for the write.
     *
     * Calculating this delta before the lock would let a concurrent receipt make
     * a physical-count correction overwrite or misstate the latest balance.
     */
    public function setOnHand(
        Item $item,
        int $quantity,
        string $reason,
        ?string $note = null,
        ?User $actor = null
    ): Item {
        if ($quantity < 0) {
            throw new InvalidArgumentException('On-hand quantity cannot be negative.');
        }

        return DB::transaction(function () use ($item, $quantity, $reason, $note, $actor): Item {
            $lockedItem = $this->lockItem($item);
            $delta = $quantity - (int) $lockedItem->qty_on_hand;

            return $this->recordLockedAdjustment($lockedItem, $delta, $reason, $note, $actor);
        });
    }

    private function lockItem(Item $item): Item
    {
        // Serialize every cached-balance writer so receipt, reversal, and
        // adjustment movements form one contiguous authoritative sequence.
        return Item::query()
            ->lockForUpdate()
            ->findOrFail($item->id);
    }

    private function recordLockedAdjustment(
        Item $item,
        int $delta,
        string $reason,
        ?string $note,
        ?User $actor
    ): Item {
        if ($item->requiresUnitAwareInventoryMutation()) {
            throw new InvalidArgumentException(
                'Identified stock must be adjusted through a serial or batch-aware workflow.'
            );
        }
        if ($delta === 0) {
            throw new InvalidArgumentException('Stock adjustment must change the on-hand quantity.');
        }

        $before = (int) $item->qty_on_hand;
        $after = $before + $delta;
        if ($after < 0) {
            throw new InvalidArgumentException('Stock adjustment cannot make on-hand quantity negative.');
        }

        $item->forceFill([
            'qty_on_hand' => $after,
            'updated_by' => $actor?->id,
        ])->save();

        Movement::query()->create([
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
    }
}
