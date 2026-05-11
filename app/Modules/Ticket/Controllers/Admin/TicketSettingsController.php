<?php

namespace App\Modules\Ticket\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Ticket\Actions\UpdateDefaultTicketEmailAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TicketSettingsController extends Controller
{
    public function index(): View
    {
        $emailAccounts = EmailAccount::query()
            ->where('is_active', true)
            ->orderBy('address')
            ->get();

        return view('ticket::Admin.Settings.index', [
            'emailAccounts' => $emailAccounts,
            'defaultTicketEmailAccount' => $emailAccounts->first(
                fn (EmailAccount $account) => in_array('tickets', (array) $account->defaults_for, true)
            ),
        ]);
    }

    public function updateDefaultEmailAccount(
        Request $request,
        UpdateDefaultTicketEmailAccount $updateDefaultTicketEmailAccount
    ): RedirectResponse {
        $data = $request->validate([
            'email_account_id' => 'nullable|exists:email_accounts,id',
        ]);

        $selectedAccount = isset($data['email_account_id'])
            ? EmailAccount::where('is_active', true)->findOrFail($data['email_account_id'])
            : null;

        $updateDefaultTicketEmailAccount->handle($selectedAccount);

        return back()->with('success', 'Default ticket email account updated.');
    }

    public function rules(): View
    {
        return view()->exists('ticket::Admin.Settings.rules.index')
            ? view('ticket::Admin.Settings.rules.index')
            : view('ticket::Admin.Settings.index');
    }

    public function workflows(): View
    {
        return view()->exists('ticket::Admin.Settings.workflows.index')
            ? view('ticket::Admin.Settings.workflows.index')
            : view('ticket::Admin.Settings.index');
    }
}
