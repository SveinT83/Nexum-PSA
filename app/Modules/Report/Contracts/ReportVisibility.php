<?php

namespace App\Modules\Report\Contracts;

use App\Models\Core\User;

/** Optional domain policy for reports whose discovery needs more than the legacy hub grant. */
interface ReportVisibility
{
    public function visibleTo(User $user): bool;
}
