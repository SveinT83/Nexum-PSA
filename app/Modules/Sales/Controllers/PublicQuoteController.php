<?php

namespace App\Modules\Sales\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Actions\AcceptSalesQuote;
use App\Modules\Sales\Actions\DeclineSalesQuote;
use App\Modules\Sales\Actions\ExpireSalesQuote;
use App\Modules\Sales\Models\SalesQuoteVersion;
use App\Modules\Sales\Support\SalesQuotePresentation;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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

        $version = app(\App\Modules\Sales\Actions\RecordSalesQuoteView::class)->handle($version);

        return view('sales::Public.quote', [
            'version' => $version,
            'opportunity' => $version->quote->opportunity,
            'quotePresentation' => $this->quotePresentation->forVersion($version),
        ]);
    }

    public function pdf(string $token): Response
    {
        $result = app(\App\Modules\Sales\Queries\SalesQuotePdf::class)->handle($this->version($token));

        return response($result['content'], 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$result['filename'].'"'] + ($result['etag'] ? ['ETag' => '"'.$result['etag'].'"'] : []));
    }

    public function accept(Request $request, string $token, AcceptSalesQuote $acceptQuote)
    {
        $result = app(\App\Modules\Sales\Actions\SalesPublicWorkflow::class)->accept($request, $token, $acceptQuote);

        return back()->with($result->level, $result->message);
    }

    public function decline(Request $request, string $token, DeclineSalesQuote $declineQuote)
    {
        $result = app(\App\Modules\Sales\Actions\SalesPublicWorkflow::class)->decline($request, $token, $declineQuote);

        return back()->with($result->level, $result->message);
    }

    public function question(Request $request, string $token)
    {
        $result = app(\App\Modules\Sales\Actions\SalesPublicWorkflow::class)->question($request, $token);

        return back()->with($result->level, $result->message);
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
