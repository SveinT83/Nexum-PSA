<?php

namespace App\Modules\UserManagement\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserRoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'guard_name' => $this->guard_name,
            'permissions_count' => $this->whenCounted('permissions'),
            'users_count' => $this->users_count === null ? null : (int) $this->users_count,
            'permissions' => $this->whenLoaded('permissions', fn () => $this->permissions
                ->pluck('name')
                ->sort()
                ->values()),
        ];
    }
}
