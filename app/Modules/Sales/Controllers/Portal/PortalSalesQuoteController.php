<?php

namespace App\Modules\Sales\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\CustomerPortal\Actions\RecordCustomerPortalAudit;
use App\Modules\CustomerPortal\Support\CustomerPortalContext;
use App\Modules\Notification\Actions\SendCustomerPortalNotification;
use App\Modules\Sales\Actions\AcceptSalesQuote;
use App\Modules\Sales\Actions\DeclineSalesQuote;
use App\Modules\Sales\Actions\ExpireSalesQuote;
use App\Modules\Sales\Models\SalesQuoteVersion;
use App\Modules\Sales\Support\PortalSalesQuoteAccess;
use App\Modules\Sales\Support\SalesQuotePresentation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PortalSalesQuoteController extends Controller
{
    public function index(Request $request, PortalSalesQuoteAccess $access): View
    {
        $context = $this->context($request);

        $versions = $access->visibleQuoteVersions($context)
            ->with(['quote.opportunity.client'])
            ->latest('sent_at')
            ->paginate(15);

        return view('sales::Portal.quotes.index', [
            'context' => $context,
            'versions' => $versions,
            'access' => $access,
        ]);
    }

    public function show(
        Request $request,
        SalesQuoteVersion $quote,
        PortalSalesQuoteAccess $access,
        SalesQuotePresentation $quotePresentation,
        ExpireSalesQuote $expireSalesQuote
    ): View {
        $context = $this->context($request);
        $quote->load(['quote.opportunity.client', 'lines.optionGroup', 'optionGroups.lines', 'acknowledgements', 'acceptanceSnapshot']);
        abort_unless($access->canView($context, $quote), 404);
        $expireSalesQuote->handle($quote);
        $quote->refresh()->load(['quote.opportunity.client', 'lines.optionGroup', 'optionGroups.lines', 'acknowledgements', 'acceptanceSnapshot']);

        $quote = app(\App\Modules\Sales\Actions\RecordSalesQuoteView::class)->handle($quote, $context);

        return view('sales::Portal.quotes.show', [
            'context' => $context,
            'version' => $quote,
            'opportunity' => $quote->quote->opportunity,
            'access' => $access,
            'quotePresentation' => $quotePresentation->forVersion($quote),
        ]);
    }

    public function accept(Request $request, SalesQuoteVersion $quote, PortalSalesQuoteAccess $access, RecordCustomerPortalAudit $audit, SendCustomerPortalNotification $portalNotifications, AcceptSalesQuote $acceptQuote, ExpireSalesQuote $expireSalesQuote): RedirectResponse
    {
        $result = app(\App\Modules\Sales\Actions\SalesPortalWorkflow::class)->accept($request, $quote, $access, $audit, $portalNotifications, $acceptQuote, $expireSalesQuote);

        return redirect()->route('customer-portal.quotes.show', $quote->refresh())->with($result->level, $result->message);
    }

    public function decline(Request $request, SalesQuoteVersion $quote, PortalSalesQuoteAccess $access, RecordCustomerPortalAudit $audit, DeclineSalesQuote $declineQuote, ExpireSalesQuote $expireSalesQuote): RedirectResponse
    {
        $result = app(\App\Modules\Sales\Actions\SalesPortalWorkflow::class)->decline($request, $quote, $access, $audit, $declineQuote, $expireSalesQuote);

        return redirect()->route('customer-portal.quotes.show', $quote->refresh())->with($result->level, $result->message);
    }

    public function question(Request $request, SalesQuoteVersion $quote, PortalSalesQuoteAccess $access, RecordCustomerPortalAudit $audit): RedirectResponse
    {
        $result = app(\App\Modules\Sales\Actions\SalesPortalWorkflow::class)->question($request, $quote, $access, $audit);

        return redirect()->route('customer-portal.quotes.show', $quote->refresh())->with($result->level, $result->message);
    }

    private function context(Request $request): CustomerPortalContext
    {
        /** @var CustomerPortalContext $context */
        $context = $request->attributes->get('customerPortalContext');

        return $context;
    }
}
