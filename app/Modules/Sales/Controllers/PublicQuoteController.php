<?php

namespace App\Modules\Sales\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\SalesActivity;
use App\Modules\Sales\Models\SalesQuoteVersion;
use Dompdf\Dompdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PublicQuoteController extends Controller
{
    public function view(string $token)
    {
        $version = $this->version($token);

        $version->forceFill(['viewed_at' => now()])->save();

        return view('sales::Public.quote', [
            'version' => $version,
            'opportunity' => $version->quote->opportunity,
        ]);
    }

    public function pdf(string $token): Response
    {
        $version = $this->version($token);
        $html = view('sales::Public.quote-pdf', [
            'version' => $version,
            'opportunity' => $version->quote->opportunity,
        ])->render();

        $dompdf = new Dompdf(['isRemoteEnabled' => true]);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4');
        $dompdf->render();

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$version->quote->quote_key.'-v'.$version->version_number.'.pdf"',
        ]);
    }

    public function accept(Request $request, string $token)
    {
        $version = $this->version($token);

        if (! in_array($version->status, ['sent'], true)) {
            return back()->with('error', 'This quote cannot be accepted in its current status.');
        }

        if ($version->expires_at && $version->expires_at->isPast()) {
            return back()->with('error', 'This quote has expired. Please ask for an updated quote.');
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'confirm' => 'required|accepted',
        ]);

        $opportunity = $version->quote->opportunity;

        $version->forceFill([
            'status' => 'accepted',
            'accepted_at' => now(),
            'accepted_by_name' => $data['name'],
            'accepted_ip' => $request->ip(),
            'accepted_ua' => $request->userAgent(),
        ])->save();
        $version->quote->forceFill(['status' => 'accepted'])->save();
        $opportunity->forceFill([
            'status' => 'won',
            'probability_percent' => 100,
            'estimated_value_ex_vat' => $version->total_ex_vat,
            'weighted_value_ex_vat' => $version->total_ex_vat,
            'won_quote_version_id' => $version->id,
            'won_at' => now(),
        ])->save();

        SalesActivity::query()->create([
            'opportunity_id' => $opportunity->id,
            'type' => 'quote_accepted',
            'direction' => 'inbound',
            'subject' => 'Quote accepted',
            'body' => $data['name'].' accepted quote '.$version->quote->quote_key.' v'.$version->version_number.'.',
            'metadata' => [
                'quote_version_id' => $version->id,
                'accepted_ip' => $request->ip(),
            ],
        ]);

        return back()->with('success', 'Quote accepted. Thank you.');
    }

    public function question(Request $request, string $token)
    {
        $version = $this->version($token);
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'message' => 'required|string|max:5000',
        ]);
        $opportunity = $version->quote->opportunity;

        SalesActivity::query()->create([
            'opportunity_id' => $opportunity->id,
            'type' => 'email_in',
            'direction' => 'inbound',
            'subject' => 'Question about quote '.$version->quote->quote_key,
            'body' => $data['message'],
            'is_unread' => true,
            'read_at' => null,
            'metadata' => [
                'quote_version_id' => $version->id,
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
            ],
        ]);

        if (! in_array($opportunity->status, ['won', 'lost'], true)) {
            $opportunity->forceFill(['status' => 'negotiation', 'probability_percent' => 70, 'is_unread' => true])->save();
        }

        return back()->with('success', 'Question sent. We will follow up.');
    }

    private function version(string $token): SalesQuoteVersion
    {
        return SalesQuoteVersion::query()
            ->with(['quote.opportunity.client', 'quote.opportunity.primaryContact', 'lines'])
            ->where('secure_token', $token)
            ->firstOrFail();
    }
}
