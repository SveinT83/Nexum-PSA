<?php

namespace App\Http\Controllers\Tech\Admin\Settings\Email;

use App\Domain\Email\Models\EmailAccount;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Crypt;
use App\Domain\Email\Services\EmailTestService;
use Illuminate\Support\Facades\Redirect;

class AccountsController extends Controller
{
    public function index()
    {
        $accounts = EmailAccount::orderBy('address')->get();
        return view('tech.admin.settings.email.accounts.index', compact('accounts'));
    }

    public function create()
    {
        return view('tech.admin.settings.email.accounts.create');
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $data['imap_secret'] = Crypt::encryptString($data['imap_secret']);
        $data['smtp_secret'] = Crypt::encryptString($data['smtp_secret']);
        EmailAccount::create($data);
        return redirect()->route('tech.admin.settings.email.accounts');
    }

    public function edit(EmailAccount $account)
    {
        return view('tech.admin.settings.email.accounts.create', compact('account'));
    }

    public function update(EmailAccount $account, Request $request)
    {
        $data = $this->validateData($request, $account->id);
        if (isset($data['imap_secret'])) {
            $data['imap_secret'] = Crypt::encryptString($data['imap_secret']);
        }
        if (isset($data['smtp_secret'])) {
            $data['smtp_secret'] = Crypt::encryptString($data['smtp_secret']);
        }
        $account->update($data);
        return redirect()->route('tech.admin.settings.email.accounts');
    }

    public function toggleActive(EmailAccount $account)
    {
        $account->is_active = !$account->is_active;
        $account->save();
        return back();
    }

    protected function validateData(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'address' => 'required|email|unique:email_accounts,address,' . ($id ?? 'NULL') . ',id',
            'description' => 'nullable|string',
            'from_name' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
            'is_global_default' => 'sometimes|boolean',
            'defaults_for' => 'nullable|array',
            // IMAP
            'imap_host' => 'required|string',
            'imap_port' => 'required|integer',
            'imap_encryption' => 'required|string',
            'imap_username' => 'required|string',
            'imap_secret' => 'required|string',
            'imap_auth_type' => 'required|string',
            // SMTP
            'smtp_host' => 'required|string',
            'smtp_port' => 'required|integer',
            'smtp_encryption' => 'required|string',
            'smtp_username' => 'required|string',
            'smtp_secret' => 'required|string',
            'smtp_auth_type' => 'required|string',
        ]);
    }

    public function test(EmailAccount $account, EmailTestService $tester)
    {
        $result = $tester->run($account);
        return Redirect::back()->with('email_test', [
            'overall' => $result->overall(),
            'imap_ok' => $result->imap_ok,
            'imap_ms' => round($result->imap_ms, 1),
            'imap_error' => $result->imap_error_message,
            'smtp_ok' => $result->smtp_ok,
            'smtp_ms' => round($result->smtp_ms, 1),
            'smtp_error' => $result->smtp_error_message,
        ]);
    }
}
