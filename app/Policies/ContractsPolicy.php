<?php

namespace App\Policies;

use App\Models\CS\Contracts\Contracts;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ContractsPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {

    }

    public function view(User $user, Contracts $contracts): bool
    {
    }

    public function create(User $user): bool
    {
    }

    public function update(User $user, Contracts $contracts): bool
    {
    }

    public function delete(User $user, Contracts $contracts): bool
    {
    }

    public function restore(User $user, Contracts $contracts): bool
    {
    }

    public function forceDelete(User $user, Contracts $contracts): bool
    {
    }
}
