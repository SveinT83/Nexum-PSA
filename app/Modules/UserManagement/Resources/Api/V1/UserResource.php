<?php

namespace App\Modules\UserManagement\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /*
    |--------------------------------------------------------------------------
    | User management payload
    |--------------------------------------------------------------------------
    |
    | The response includes profile and preference context needed by automation,
    | but never exposes password hashes, two-factor secrets, or invite tokens.
    |
    */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contact_id' => $this->contact_id,
            'name' => $this->name,
            'email' => $this->email,
            'phone_work' => $this->phone_work,
            'phone_private' => $this->phone_private,
            'status' => $this->status,
            'email_verified_at' => optional($this->email_verified_at)->toIso8601String(),
            'two_factor_confirmed' => $this->two_factor_confirmed_at !== null,
            'roles' => $this->whenLoaded('roles', fn () => $this->roles
                ->pluck('name')
                ->sort()
                ->values()),
            'permissions' => $this->whenLoaded('permissions', fn () => $this->permissions
                ->pluck('name')
                ->sort()
                ->values()),
            'profile' => $this->whenLoaded('profile', fn () => [
                'avatar_path' => $this->profile?->avatar_path,
                'avatar_url' => $this->profile?->avatarUrl(),
                'work_phone' => $this->profile?->work_phone,
                'private_phone' => $this->profile?->private_phone,
                'timezone' => $this->profile?->timezone,
                'working_hours' => $this->profile?->working_hours,
                'availability_notes' => $this->profile?->availability_notes,
                'profile_notes' => $this->profile?->profile_notes,
            ]),
            'preferences' => $this->whenLoaded('preferences', fn () => [
                'timezone' => $this->preferences?->timezone,
                'default_calendar_view' => $this->preferences?->default_calendar_view,
                'workday_start' => $this->preferences?->workday_start,
                'workday_end' => $this->preferences?->workday_end,
                'settings' => $this->preferences?->settings,
            ]),
            'latest_invite' => $this->whenLoaded('inviteTokens', fn () => $this->inviteTokens->first() ? [
                'expires_at' => optional($this->inviteTokens->first()->expires_at)->toIso8601String(),
                'used_at' => optional($this->inviteTokens->first()->used_at)->toIso8601String(),
                'is_valid' => $this->inviteTokens->first()->isValid(),
            ] : null),
            'links' => [
                'self' => route('api.v1.users.show', $this->resource),
                'update' => route('api.v1.users.update', $this->resource),
                'status' => route('api.v1.users.status.update', $this->resource),
                'roles' => route('api.v1.users.roles.update', $this->resource),
                'invite' => route('api.v1.users.invite.send', $this->resource),
            ],
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
