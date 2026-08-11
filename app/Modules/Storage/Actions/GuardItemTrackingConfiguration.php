<?php

namespace App\Modules\Storage\Actions;

use App\Modules\Storage\Models\Item;
use Illuminate\Validation\ValidationException;

class GuardItemTrackingConfiguration
{
    /** @param array<string, mixed> $attributes */
    public function validateNewItem(array $attributes, int $initialQuantity): void
    {
        if ($initialQuantity > 0 && $this->requestedTracking($attributes)) {
            throw ValidationException::withMessages([
                'initial_quantity' => 'Initial quantity for serial, batch, or expiry-tracked items requires unit details.',
            ]);
        }
    }

    /**
     * Lock the item and reject tracking-flag changes while any stock ledger is active.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function lockAndValidateUpdate(Item $item, array $attributes): Item
    {
        $lockedItem = Item::withTrashed()
            ->lockForUpdate()
            ->findOrFail($item->id);
        $changed = collect(['has_serials', 'track_batch', 'expiry_enabled'])
            ->contains(function (string $field) use ($attributes, $lockedItem): bool {
                return array_key_exists($field, $attributes)
                    && (bool) $attributes[$field] !== (bool) $lockedItem->{$field};
            });

        if ($changed && $lockedItem->trackingConfigurationIsLocked()) {
            throw ValidationException::withMessages([
                'has_serials' => 'Tracking flags cannot change while on-hand or identified stock exists.',
            ]);
        }

        return $lockedItem;
    }

    /** @param array<string, mixed> $attributes */
    private function requestedTracking(array $attributes): bool
    {
        return (bool) ($attributes['has_serials'] ?? false)
            || (bool) ($attributes['track_batch'] ?? false)
            || (bool) ($attributes['expiry_enabled'] ?? false);
    }
}
