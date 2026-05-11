<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Models\EmailAccount;

class DefaultEmailAccountResolver
{
    /*
    |--------------------------------------------------------------------------
    | Default outbound account lookup
    |--------------------------------------------------------------------------
    |
    | Per-scope defaults live on EmailAccount.defaults_for. If no scope-specific
    | ticket account exists, fall back to the active global default account.
    |
    */
    public function forScope(string $scope): ?EmailAccount
    {
        return EmailAccount::query()
            ->where('is_active', true)
            ->get()
            ->first(fn (EmailAccount $account) => in_array($scope, (array) $account->defaults_for, true))
            ?: EmailAccount::query()
                ->where('is_active', true)
                ->where('is_global_default', true)
                ->first();
    }
}
