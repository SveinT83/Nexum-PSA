<?php

namespace App\Modules\Sales\Jobs;

use App\Modules\Email\Models\EmailLog;
use App\Modules\Email\Models\EmailTemplate;
use App\Modules\Email\Services\DefaultEmailAccountResolver;
use App\Modules\Email\Services\EmailTemplateRenderer;
use App\Modules\Email\Services\SmtpAccountMailer;
use App\Modules\Sales\Models\SalesQuoteVersion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendSalesQuoteEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /*
    |--------------------------------------------------------------------------
    | Queued quote delivery
    |--------------------------------------------------------------------------
    |
    | Quote sending is deliberately queued so slow or failing SMTP delivery does
    | not block the seller from locking the quote version and continuing work.
    |
    */
    public int $timeout = 120;

    public function __construct(public int $salesQuoteVersionId) {}

    public function handle(
        DefaultEmailAccountResolver $accountResolver,
        EmailTemplateRenderer $renderer,
        SmtpAccountMailer $mailer,
    ): void {
        $version = SalesQuoteVersion::query()
            ->with(['quote.opportunity.client', 'quote.opportunity.primaryContact', 'quote.opportunity.owner'])
            ->find($this->salesQuoteVersionId);

        if (! $version) {
            return;
        }

        $opportunity = $version->quote->opportunity;
        $recipient = $opportunity->primaryContact;

        if (! $recipient?->email || ! filter_var($recipient->email, FILTER_VALIDATE_EMAIL)) {
            $this->log(null, $version->id, 'error', 'SALES_QUOTE_NO_RECIPIENT', 'Sales quote has no valid primary contact recipient.');
            return;
        }

        $account = $accountResolver->forScope('sales');

        if (! $account) {
            $this->log(null, $version->id, 'error', 'SALES_QUOTE_NO_ACCOUNT', 'No active sales outbound email account is configured.');
            return;
        }

        $template = EmailTemplate::query()
            ->where('scope', 'sales')
            ->where('key', 'sales_quote_send')
            ->where('is_active', true)
            ->first();

        if (! $template) {
            $this->log($account->id, $version->id, 'error', 'SALES_QUOTE_NO_TEMPLATE', 'No active sales_quote_send template exists.');
            return;
        }

        try {
            $rendered = $renderer->render($template, [
                'opportunity_key' => $opportunity->opportunity_key,
                'opportunity_title' => $opportunity->title,
                'client_name' => $opportunity->client?->name ?? '',
                'contact_name' => $recipient->name ?: 'there',
                'quote_key' => $version->quote->quote_key,
                'quote_url' => route('sales.quotes.public.view', $version->secure_token),
                'total_ex_vat' => number_format((float) $version->total_ex_vat, 2, '.', ' '),
                'total_inc_vat' => number_format((float) $version->total_inc_vat, 2, '.', ' '),
                'expires_at' => $version->expires_at?->toDateString() ?? '',
                'seller_name' => $opportunity->owner?->name ?? 'Sales',
            ]);

            $messageId = $mailer->send(
                $account,
                $recipient->email,
                $recipient->name,
                $rendered['subject'],
                $rendered['html'],
                $rendered['text']
            );

            $this->log($account->id, $version->id, 'info', 'SALES_QUOTE_SENT', 'Sales quote email sent.', [
                'opportunity_id' => $opportunity->id,
                'opportunity_key' => $opportunity->opportunity_key,
                'quote_id' => $version->quote_id,
                'to' => $recipient->email,
                'rfc_message_id' => $messageId,
            ], $messageId);
        } catch (\Throwable $e) {
            $account->forceFill([
                'last_error_code' => 'SMTP_SEND',
                'last_error_message' => $e->getMessage(),
            ])->save();

            $this->log($account->id, $version->id, 'error', 'SALES_QUOTE_SEND_FAILED', $e->getMessage(), [
                'opportunity_id' => $opportunity->id,
                'opportunity_key' => $opportunity->opportunity_key,
                'quote_id' => $version->quote_id,
                'to' => $recipient->email,
            ]);

            throw $e;
        }
    }

    private function log(?int $accountId, int $quoteVersionId, string $level, string $code, string $message, array $context = [], ?string $rfcMessageId = null): void
    {
        EmailLog::create([
            'direction' => 'outbound',
            'account_id' => $accountId,
            'scope' => 'sales',
            'level' => $level,
            'code' => $code,
            'message' => $message,
            'context_json' => array_merge(['sales_quote_version_id' => $quoteVersionId], $context),
            'rfc_message_id' => $rfcMessageId,
        ]);
    }
}
