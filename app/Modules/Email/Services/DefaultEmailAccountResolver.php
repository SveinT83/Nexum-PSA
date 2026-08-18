<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Models\EmailAccount;
use Illuminate\Support\Facades\Schema;

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
        $query = EmailAccount::query()
            ->where('is_active', true);

        if (Schema::hasColumn('email_accounts', 'account_kind')) {
            $query->where('account_kind', '!=', EmailAccount::KIND_PERSONAL);
        }

        $runtime = app(EmailAccountProviderRuntimeResolver::class);
        $accounts = (clone $query)->get()
            ->filter(fn (EmailAccount $account): bool => $runtime->databaseReady($account));

        return $accounts
            ->first(fn (EmailAccount $account) => in_array($scope, (array) $account->defaults_for, true))
            ?: $accounts->first(fn (EmailAccount $account): bool => (bool) $account->is_global_default);
    }
}
