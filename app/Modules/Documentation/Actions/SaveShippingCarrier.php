<?php

namespace App\Modules\Documentation\Actions;

use App\Models\Core\User;
use App\Modules\Documentation\Models\ShippingCarrier;
use Illuminate\Support\Facades\DB;

/** Edit current master data only; existing Storage shipment snapshots are immutable. */
class SaveShippingCarrier
{
    public function handle(array $data, User $actor, ?ShippingCarrier $carrier = null): ShippingCarrier
    {
        return DB::transaction(function () use ($data, $actor, $carrier): ShippingCarrier {
            $record = $carrier ? ShippingCarrier::whereKey($carrier->id)->lockForUpdate()->firstOrFail() : new ShippingCarrier;
            $record->fill($data);
            if (! $record->exists) {
                $record->created_by = $actor->id;
            }
            $record->updated_by = $actor->id;
            $record->save();

            return $record->refresh();
        });
    }
}
