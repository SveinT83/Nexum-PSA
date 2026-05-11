<?php

namespace App\Modules\Storage\Actions;

use App\Modules\Storage\Models\Warehouse;
use Illuminate\Support\Str;

class StoreWarehouse
{
    public function handle(array $data): Warehouse
    {
        $data['code'] = Str::upper(Str::slug($data['code'] ?? $data['name'], '-'));
        $data['is_active'] = $data['is_active'] ?? true;

        return Warehouse::create($data);
    }
}
