<?php

namespace App\Modules\Email\Controllers\Admin;

use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Services\EmailAccountAdministration;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Browser presentation delegates account validation/persistence to the same application service as API. */
class AccountsController extends Controller
{
    public function __construct(private readonly EmailAccountAdministration $accounts) {}

    public function index(Request $request)
    {
        return view('email::Admin.Accounts.index', $this->accounts->index($request));
    }

    public function create(Request $request)
    {
        return view('email::Admin.Accounts.create', $this->accounts->create($request));
    }

    public function edit(EmailAccount $account, Request $request)
    {
        return view('email::Admin.Accounts.create', $this->accounts->edit($account, $request));
    }

    public function store(Request $request)
    {
        $account = $this->accounts->store($request);

        return redirect()->route('tech.admin.settings.email.accounts.edit', $account)
            ->with('status', 'Saved. Nexum is testing incoming and outgoing mail now.');
    }

    public function update(EmailAccount $account, Request $request)
    {
        $account = $this->accounts->update($account, $request);

        return redirect()->route('tech.admin.settings.email.accounts.edit', $account)
            ->with('status', 'Saved. Nexum is testing incoming and outgoing mail now.');
    }

    public function toggleActive(EmailAccount $account, Request $request)
    {
        $this->accounts->setActive($account, $request, ! $account->is_active);

        return back();
    }

    public function test(EmailAccount $account, Request $request)
    {
        $this->accounts->test($account, $request);

        return redirect()->route('tech.admin.settings.email.accounts.edit', $account)
            ->with('status', 'Nexum is testing incoming and outgoing mail now.');
    }
}
