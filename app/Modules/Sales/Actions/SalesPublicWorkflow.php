<?php

namespace App\Modules\Sales\Actions;

use App\Modules\Integration\Support\DomainApiInput;
use App\Modules\Sales\DTOs\SalesWorkflowResult;
use App\Modules\Sales\Models\SalesActivity;
use App\Modules\Sales\Models\SalesQuoteVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Current customer response rules and side effects shared across HTML and typed APIs. */
class SalesPublicWorkflow
{
    public function __construct(private readonly ExpireSalesQuote $expireSalesQuote) {}

    public function accept(Request $request, string $token, AcceptSalesQuote $acceptQuote)
    {
        $version = $this->version($token);
        $this->expireSalesQuote->handle($version);
        $version->refresh();

        if (! in_array($version->status, ['sent'], true)) {
            return new SalesWorkflowResult('error', 'This quote cannot be accepted in its current status.', $version->id);
        }

        if ($version->expires_at && $version->expires_at->isPast()) {
            return new SalesWorkflowResult('error', 'This quote has expired. Please ask for an updated quote.', $version->id);
        }

        $data = DomainApiInput::validate($request, [
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

        app(ValidateSalesQuoteSelection::class)->handle($version, $data);
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

        return new SalesWorkflowResult('success', 'Quote accepted. Thank you.', $version->id);
    }

    public function decline(Request $request, string $token, DeclineSalesQuote $declineQuote)
    {
        $version = $this->version($token);
        $this->expireSalesQuote->handle($version);
        $version->refresh();

        if (! in_array($version->status, ['sent'], true)) {
            return new SalesWorkflowResult('error', 'This quote cannot be declined in its current status.', $version->id);
        }

        $data = DomainApiInput::validate($request, [
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

        return new SalesWorkflowResult('success', 'Quote declined. We will follow up if needed.', $version->id);
    }

    public function question(Request $request, string $token)
    {
        $version = $this->version($token);
        $data = DomainApiInput::validate($request, [
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

        return new SalesWorkflowResult('success', 'Question sent. We will follow up.', $version->id);
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
