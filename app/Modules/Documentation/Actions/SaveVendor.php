<?php

namespace App\Modules\Documentation\Actions;

use App\Modules\Documentation\Models\Vendor;
use Illuminate\Support\Facades\DB;

/** Row locking keeps browser edits and API stale checks on the same master record. */
class SaveVendor
{
    public function handle(array $data, ?Vendor $vendor = null): Vendor
    {
        return DB::transaction(function () use ($data, $vendor): Vendor {
            $record = $vendor ? Vendor::whereKey($vendor->id)->lockForUpdate()->firstOrFail() : new Vendor;
            $record->fill($data)->save();

            return $record->refresh();
        });
    }
}
