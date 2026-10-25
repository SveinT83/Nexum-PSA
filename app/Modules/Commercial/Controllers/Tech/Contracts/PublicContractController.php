<?php

namespace App\Modules\Commercial\Controllers\Tech\Contracts;

use App\Http\Controllers\Controller;
use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Commercial\Support\ContractCustomerDocument;
use DomainException;
use Illuminate\Http\Request;
use UnexpectedValueException;

class PublicContractController extends Controller
{
    /**
     * Display the contract for the customer.
     */
    public function view($token)
    {
        $contract = Contracts::with(['client', 'sla', 'items.slaPolicy', 'items.timeRates', 'termSnapshots.termVersion'])
            ->where('secure_token', $token)
            ->firstOrFail();
        abort_unless(
            in_array($contract->approval_status, ['sent_quote', 'sent_contract', 'approved', 'won'], true),
            404,
        );
        $documents = app(ContractCustomerDocument::class);

        try {
            $customerDocument = $documents->resolve($contract);
        } catch (DomainException) {
            abort(409, 'Kundedokumentet er sperret i påvente av manuell verifisering.');
        } catch (UnexpectedValueException) {
            abort(409, 'Kundedokumentets lagrede format kan ikke behandles.');
        }

        app(\App\Modules\Commercial\Actions\ContractCustomerWorkflow::class)->viewed(request(), $contract, $token);

        return view('commercial::Tech.cs.contracts.public.view', [
            'contract' => $contract,
            'customerDocument' => $customerDocument,
        ]);
    }

    /**
     * Accept the contract.
     */
    public function accept(Request $request, $token)
    {
        Contracts::query()->where('secure_token', $token)->firstOrFail();

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'confirm' => 'required|accepted',
        ], [
            'name.required' => 'Navn må fylles ut.',
            'name.string' => 'Navnet må være tekst.',
            'name.max' => 'Navnet kan ikke være lengre enn 255 tegn.',
            'confirm.required' => 'Du må bekrefte at du godtar dokumentet.',
            'confirm.accepted' => 'Du må bekrefte at du godtar dokumentet.',
        ]);

        try {
            $accepted = app(\App\Modules\Commercial\Actions\ContractCustomerWorkflow::class)->acceptPublic($request, $token, $data);
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        } catch (UnexpectedValueException) {
            return back()->with('error', 'Dokumentversjonen kan ikke behandles automatisk. Kontakt leverandøren.');
        }

        if (! $accepted) {
            return back()->with('error', 'Dokumentet kan ikke godkjennes i nåværende status.');
        }

        return back()->with('success', 'Dokumentet er godkjent. Takk!');
    }
}
