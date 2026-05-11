<?php

namespace App\Modules\Ticket\Actions;

use App\Modules\Email\Models\EmailAccount;
use Illuminate\Support\Facades\DB;

class UpdateDefaultTicketEmailAccount
{
    /*
    |--------------------------------------------------------------------------
    | Ticket outbound account selection
    |--------------------------------------------------------------------------
    |
    | EmailAccount.defaults_for is the existing source of truth for per-domain
    | defaults. Ticket settings edit that same field rather than introducing a
    | second ticket-specific setting that could drift out of sync.
    |
    */
    public function handle(?EmailAccount $selectedAccount): void
    {
        DB::transaction(function () use ($selectedAccount) {
            EmailAccount::query()->get()->each(function (EmailAccount $account) use ($selectedAccount) {
                $defaults = array_values(array_filter(
                    (array) $account->defaults_for,
                    fn (string $scope) => $scope !== 'tickets'
                ));

                if ($selectedAccount && $account->is($selectedAccount)) {
                    $defaults[] = 'tickets';
                }

                $account->forceFill([
                    'defaults_for' => array_values(array_unique($defaults)),
                ])->save();
            });
        });
    }
}
