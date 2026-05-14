<?php

namespace App\Modules\Storage\Actions;

use App\Models\Core\User;
use App\Modules\Storage\Models\Box;
use App\Modules\Storage\Models\BoxEvent;
use Illuminate\Support\Str;

class StoreBox
{
    public function handle(array $data, ?User $actor = null): Box
    {
        if (!empty($data['code_human'])) {
            $data['code_human'] = Str::upper(Str::slug($data['code_human'], '-'));
        }

        $data['created_by'] = $actor?->id;
        $data['updated_by'] = $actor?->id;
        $data['is_active'] = $data['is_active'] ?? true;

        $box = Box::create($data);

        BoxEvent::create([
            'box_id' => $box->id,
            'actor_id' => $actor?->id,
            'type' => 'created',
            'to_warehouse_id' => $box->warehouse_id,
            'details' => [
                'name' => $box->name,
                'code_human' => $box->code_human,
            ],
        ]);

        return $box;
    }
}
