<?php

namespace App\Modules\Sales\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Actions\AcceptSalesQuote;
use App\Modules\Sales\Models\SalesActivity;
use App\Modules\Sales\Models\SalesQuoteVersion;
use Dompdf\Dompdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

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

        if ($version->pdf_snapshot_disk && $version->pdf_snapshot_path
            && Storage::disk($version->pdf_snapshot_disk)->exists($version->pdf_snapshot_path)) {
            return response(Storage::disk($version->pdf_snapshot_disk)->get($version->pdf_snapshot_path), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.$version->quote->quote_key.'-v'.$version->version_number.'.pdf"',
                'ETag' => '"'.$version->pdf_snapshot_sha256.'"',
            ]);
        }

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

    public function accept(Request $request, string $token, AcceptSalesQuote $acceptQuote)
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

        $acceptQuote->handle($version, [
            'name' => $data['name'],
            'method' => 'public_link',
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
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
