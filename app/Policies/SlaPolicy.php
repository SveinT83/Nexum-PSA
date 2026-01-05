<?php

namespace App\Policies;

use App\Models\CS\Sla\Sla;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class SlaPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {

    }

    public function view(User $user, Sla $sla): bool
    {
    }

    public function create(User $user): bool
    {
    }

    public function update(User $user, Sla $sla): bool
    {
    }

    public function delete(User $user, Sla $sla): bool
    {
    }

    public function restore(User $user, Sla $sla): bool
    {
    }

    public function forceDelete(User $user, Sla $sla): bool
    {
    }
}
