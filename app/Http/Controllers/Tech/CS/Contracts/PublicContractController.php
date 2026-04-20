<?php

namespace App\Http\Controllers\Tech\CS\Contracts;

use App\Http\Controllers\Controller;
use App\Models\CS\Contracts\Contracts;
use Illuminate\Http\Request;

class PublicContractController extends Controller
{
    /**
     * Display the contract for the customer.
     */
    public function view($token)
    {
        $contract = Contracts::where('secure_token', $token)->firstOrFail();

        // Audit Logging for View
        $contract->update([
            'viewed_at' => now(),
            'viewed_ip' => request()->ip(),
            'viewed_ua' => request()->userAgent(),
        ]);

        return view('tech.cs.contracts.public.view', [
            'contract' => $contract,
        ]);
    }

    /**
     * Accept the contract.
     */
    public function accept(Request $request, $token)
    {
        $contract = Contracts::where('secure_token', $token)->firstOrFail();

        // Prevent acceptance if not in Sent status or already accepted
        if (!in_array($contract->approval_status, ['sent_quote', 'sent_contract'])) {
             return back()->with('error', 'This contract cannot be accepted in its current status.');
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'confirm' => 'required|accepted',
        ], [
            'confirm.accepted' => 'You must confirm that you accept the contract.',
        ]);

        $contract->update([
            'approval_status' => 'won',
            'accepted_at' => now(),
            'accepted_by_name' => $request->name,
            'accepted_ip' => $request->ip(),
            'accepted_ua' => $request->userAgent(),
        ]);

        return back()->with('success', 'Contract successfully accepted. Thank you!');
    }
}
