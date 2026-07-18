<?php

namespace App\Modules\Sales\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\CustomerPortal\Actions\RecordCustomerPortalAudit;
use App\Modules\CustomerPortal\Support\CustomerPortalContext;
use App\Modules\Notification\Actions\SendCustomerPortalNotification;
use App\Modules\Sales\Actions\AcceptSalesQuote;
use App\Modules\Sales\Models\SalesActivity;
use App\Modules\Sales\Models\SalesQuoteVersion;
use App\Modules\Sales\Support\PortalSalesQuoteAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    public function show(Request $request, SalesQuoteVersion $quote, PortalSalesQuoteAccess $access): View
    {
        $context = $this->context($request);
        $quote->load(['quote.opportunity.client', 'lines']);
        abort_unless($access->canView($context, $quote), 404);

        if (! $quote->viewed_at) {
            $quote->forceFill(['viewed_at' => now()])->save();
        }

        return view('sales::Portal.quotes.show', [
            'context' => $context,
            'version' => $quote,
            'opportunity' => $quote->quote->opportunity,
            'access' => $access,
        ]);
    }

    public function accept(Request $request, SalesQuoteVersion $quote, PortalSalesQuoteAccess $access, RecordCustomerPortalAudit $audit, SendCustomerPortalNotification $portalNotifications, AcceptSalesQuote $acceptQuote): RedirectResponse
    {
        $context = $this->context($request);
        $quote->load(['quote.opportunity.client']);
        abort_unless($access->canView($context, $quote), 404);

        if (! $access->canAccept($quote)) {
            return back()->with('error', 'This quote cannot be accepted in its current status.');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'confirm' => ['required', 'accepted'],
        ]);

        DB::transaction(function () use ($request, $quote, $context, $audit, $portalNotifications, $data, $acceptQuote): void {
            $opportunity = $quote->quote->opportunity;

            $acceptQuote->handle($quote, [
                'name' => $data['name'],
                'method' => 'customer_portal',
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'portal_account_id' => $context->account->id,
                'portal_membership_id' => $context->membership->id,
                'portal_contact_id' => $context->contact->id,
            ]);

            $audit->handle(
                'portal_sales_quote_accepted',
                $context->account,
                $request->user(),
                $context->contact,
                $context->client,
                $context->site,
                [
                    'sales_quote_version_id' => $quote->id,
                    'sales_quote_id' => $quote->quote_id,
                    'quote_key' => $quote->quote->quote_key,
                    'opportunity_id' => $opportunity->id,
                ],
                $request,
            );

            $portalNotifications->handle(
                type: 'portal_quote_accepted',
                clientId: (int) $context->client->id,
                siteId: null,
                title: 'Quote accepted',
                body: $quote->quote->quote_key.' v'.$quote->version_number.' was accepted by '.$data['name'].'.',
                url: route('customer-portal.quotes.show', $quote),
                sourceType: SalesQuoteVersion::class,
                sourceId: $quote->id,
                metadata: [
                    'quote_key' => $quote->quote->quote_key,
                    'version_number' => $quote->version_number,
                    'accepted_by_name' => $data['name'],
                ],
            );
        });

        return redirect()->route('customer-portal.quotes.show', $quote->refresh())
            ->with('success', 'Quote accepted. Thank you.');
    }

    public function question(Request $request, SalesQuoteVersion $quote, PortalSalesQuoteAccess $access, RecordCustomerPortalAudit $audit): RedirectResponse
    {
        $context = $this->context($request);
        $quote->load(['quote.opportunity.client']);
        abort_unless($access->canView($context, $quote), 404);

        $data = $request->validate([
            'message' => ['required', 'string', 'max:5000'],
        ]);

        DB::transaction(function () use ($request, $quote, $context, $audit, $data): void {
            $opportunity = $quote->quote->opportunity;

            SalesActivity::query()->create([
                'opportunity_id' => $opportunity->id,
                'type' => 'email_in',
                'direction' => 'inbound',
                'subject' => 'Question about quote '.$quote->quote->quote_key,
                'body' => $data['message'],
                'is_unread' => true,
                'read_at' => null,
                'metadata' => [
                    'quote_version_id' => $quote->id,
                    'customer_portal_account_id' => $context->account->id,
                    'customer_portal_membership_id' => $context->membership->id,
                    'contact_id' => $context->contact->id,
                    'name' => $context->contact->display_name,
                ],
            ]);

            if (! in_array($opportunity->status, ['won', 'lost'], true)) {
                $opportunity->forceFill(['status' => 'negotiation', 'probability_percent' => 70, 'is_unread' => true])->save();
            }

            $audit->handle(
                'portal_sales_quote_question_sent',
                $context->account,
                $request->user(),
                $context->contact,
                $context->client,
                $context->site,
                [
                    'sales_quote_version_id' => $quote->id,
                    'sales_quote_id' => $quote->quote_id,
                    'quote_key' => $quote->quote->quote_key,
                    'opportunity_id' => $opportunity->id,
                ],
                $request,
            );
        });

        return redirect()->route('customer-portal.quotes.show', $quote)
            ->with('success', 'Question sent. We will follow up.');
    }

    private function context(Request $request): CustomerPortalContext
    {
        /** @var CustomerPortalContext $context */
        $context = $request->attributes->get('customerPortalContext');

        return $context;
    }
}
