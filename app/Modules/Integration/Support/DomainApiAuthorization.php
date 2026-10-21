<?php

namespace App\Modules\Integration\Support;

use App\Http\Middleware\EnforceTechRoutePermission;
use App\Models\Core\User;
use Illuminate\Http\Request;

/** Actor rights remain independent from token grants and are rechecked on every request. */
final class DomainApiAuthorization
{
    public function permits(Request $request, string $permissions): bool
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->isActive()) {
            return false;
        }

        foreach (explode('|', $permissions) as $permission) {
            if (app(EnforceTechRoutePermission::class)->allowsPermission($request, $permission)) {
                return true;
            }
        }

        return false;
    }

    public function authorize(Request $request, string $permissions): void
    {
        abort_unless($this->permits($request, $permissions), 403, 'The active user does not have the required domain permission.');
    }
}
