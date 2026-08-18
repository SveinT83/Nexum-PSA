<?php

namespace App\Modules\Sales\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Actions\AcceptSalesQuote;
use App\Modules\Sales\Actions\DeclineSalesQuote;
use App\Modules\Sales\Actions\ExpireSalesQuote;
use App\Modules\Sales\Models\SalesActivity;
use App\Modules\Sales\Models\SalesQuoteVersion;
use App\Modules\Sales\Support\SalesQuotePresentation;
use Dompdf\Dompdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class PublicQuoteController extends Controller
{
    public function __construct(
        private readonly SalesQuotePresentation $quotePresentation,
        private readonly ExpireSalesQuote $expireSalesQuote,
    ) {}

    public function view(string $token)
    {
        $version = $this->version($token);
        $this->expireSalesQuote->handle($version);
        $version->refresh();

        if (! $version->viewed_at) {
            $version->forceFill(['viewed_at' => now()])->save();
            SalesActivity::query()->create([
                'opportunity_id' => $version->quote->opportunity->id,
                'type' => 'quote_viewed',
                'direction' => 'inbound',
                'subject' => 'Quote viewed',
                'body' => 'Public quote '.$version->quote->quote_key.' v'.$version->version_number.' was viewed.',
                'metadata' => ['quote_version_id' => $version->id, 'method' => 'public_link'],
            ]);
        }

        return view('sales::Public.quote', [
            'version' => $version,
            'opportunity' => $version->quote->opportunity,
            'quotePresentation' => $this->quotePresentation->forVersion($version),
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
            'quotePresentation' => $this->quotePresentation->forVersion($version),
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
        $this->expireSalesQuote->handle($version);
        $version->refresh();

        if (! in_array($version->status, ['sent'], true)) {
            return back()->with('error', 'This quote cannot be accepted in its current status.');
        }

        if ($version->expires_at && $version->expires_at->isPast()) {
            return back()->with('error', 'This quote has expired. Please ask for an updated quote.');
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'confirm' => 'required|accepted',
            'selected_line_ids' => 'nullable|array',
            'selected_line_ids.*' => 'integer',
            'quantities' => 'nullable|array',
            'quantities.*' => 'numeric|min:0.01|max:100000',
            'acknowledgement_ids' => 'nullable|array',
            'acknowledgement_ids.*' => 'integer',
        ]);

        $acceptQuote->handle($version, [
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'method' => 'public_link',
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'selected_line_ids' => $data['selected_line_ids'] ?? null,
            'quantities' => $data['quantities'] ?? [],
            'acknowledgement_ids' => $data['acknowledgement_ids'] ?? [],
        ]);

        return back()->with('success', 'Quote accepted. Thank you.');
    }

    public function decline(Request $request, string $token, DeclineSalesQuote $declineQuote)
    {
        $version = $this->version($token);
        $this->expireSalesQuote->handle($version);
        $version->refresh();

        if (! in_array($version->status, ['sent'], true)) {
            return back()->with('error', 'This quote cannot be declined in its current status.');
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'reason' => 'nullable|string|max:4000',
        ]);

        $declineQuote->handle($version, [
            'name' => $data['name'],
            'reason' => $data['reason'] ?? null,
            'method' => 'public_link',
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return back()->with('success', 'Quote declined. We will follow up if needed.');
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
            ->with(['lines.optionGroup', 'optionGroups.lines', 'acknowledgements', 'acceptanceSnapshot'])
            ->where('secure_token', $token)
            ->firstOrFail();
    }
}
