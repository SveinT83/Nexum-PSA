<?php

namespace App\Modules\Sales\Jobs;

use App\Modules\Email\Models\EmailLog;
use App\Modules\Email\Models\EmailTemplate;
use App\Modules\Email\Services\DefaultEmailAccountResolver;
use App\Modules\Email\Services\EmailTemplateRenderer;
use App\Modules\Email\Services\SmtpAccountMailer;
use App\Modules\Sales\Models\SalesActivity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendSalesActivityEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const REPLY_ABOVE_LINE = '--- Please reply above this line ---';

    public int $timeout = 120;

    public function __construct(public int $salesActivityId) {}

    public function handle(
        DefaultEmailAccountResolver $accountResolver,
        EmailTemplateRenderer $renderer,
        SmtpAccountMailer $mailer,
    ): void {
        $activity = SalesActivity::with(['opportunity.client', 'opportunity.currentQuoteVersion.quote', 'actor'])->find($this->salesActivityId);

        if (! $activity || $activity->type !== 'email_out') {
            return;
        }

        $metadata = $activity->metadata ?? [];
        $toEmail = $metadata['to_email'] ?? null;

        if (! $toEmail || ! filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            $this->log(null, $activity->id, 'error', 'SALES_EMAIL_NO_RECIPIENT', 'Sales email has no valid recipient.');
            return;
        }

        $account = $accountResolver->forScope('sales');

        if (! $account) {
            $this->log(null, $activity->id, 'error', 'SALES_EMAIL_NO_ACCOUNT', 'No active sales outbound email account is configured.');
            return;
        }

        $template = EmailTemplate::query()
            ->where('scope', 'sales')
            ->where('key', 'sales_activity_email')
            ->where('is_active', true)
            ->first();

        if (! $template) {
            $this->log($account->id, $activity->id, 'error', 'SALES_EMAIL_NO_TEMPLATE', 'No active sales_activity_email template exists.');
            return;
        }

        $opportunity = $activity->opportunity;
        $toName = $metadata['to_name'] ?? '';

        try {
            $rendered = $renderer->render($template, [
                'opportunity_key' => $opportunity->opportunity_key,
                'opportunity_title' => $opportunity->title,
                'client_name' => $opportunity->client?->name ?? '',
                'contact_name' => $toName ?: 'there',
                'message_subject' => $activity->subject ?: $opportunity->title,
                'message_body' => $activity->body,
                'quote_key' => $opportunity->currentQuoteVersion?->quote?->quote_key ?? '',
                'quote_url' => $this->activeQuoteUrl($opportunity),
                'seller_name' => $activity->actor?->name ?? 'Sales',
            ]);

            $rendered['html'] = $this->appendActiveQuoteLinkToHtml($rendered['html'], $opportunity);
            $rendered['text'] = $this->appendActiveQuoteLinkToText($rendered['text'], $opportunity);

            $messageId = $mailer->send(
                $account,
                $toEmail,
                $toName,
                $rendered['subject'],
                $this->appendReplyBoundaryToHtml($rendered['html']),
                $this->appendReplyBoundaryToText($rendered['text']),
                [],
                $this->ccRecipients($metadata['cc'] ?? [])
            );

            $this->log($account->id, $activity->id, 'info', 'SALES_EMAIL_SENT', 'Sales email sent.', [
                'opportunity_id' => $opportunity->id,
                'opportunity_key' => $opportunity->opportunity_key,
                'to' => $toEmail,
                'cc' => collect($metadata['cc'] ?? [])->pluck('email')->all(),
                'rfc_message_id' => $messageId,
            ], $messageId);
        } catch (\Throwable $e) {
            $account->forceFill([
                'last_error_code' => 'SMTP_SEND',
                'last_error_message' => $e->getMessage(),
            ])->save();

            $this->log($account->id, $activity->id, 'error', 'SALES_EMAIL_SEND_FAILED', $e->getMessage(), [
                'opportunity_id' => $opportunity->id,
                'opportunity_key' => $opportunity->opportunity_key,
                'to' => $toEmail,
            ]);

            throw $e;
        }
    }

    private function ccRecipients(array $cc): array
    {
        return collect($cc)
            ->map(fn ($recipient) => is_array($recipient) ? $recipient : ['email' => $recipient, 'name' => ''])
            ->filter(fn ($recipient) => filter_var($recipient['email'] ?? null, FILTER_VALIDATE_EMAIL))
            ->values()
            ->all();
    }

    private function appendReplyBoundaryToHtml(string $html): string
    {
        $boundary = '<p style="margin-top:24px;color:#6c757d;font-size:12px;">'.e(self::REPLY_ABOVE_LINE).'</p>';

        return str_contains($html, self::REPLY_ABOVE_LINE) ? $html : rtrim($html).$boundary;
    }

    private function appendActiveQuoteLinkToHtml(string $html, $opportunity): string
    {
        $url = $this->activeQuoteUrl($opportunity);

        if (! $url || str_contains($html, $url)) {
            return $html;
        }

        return rtrim($html)
            .'<p style="margin-top:16px;">You can view the active quote here:<br><a href="'.e($url).'">'.e($url).'</a></p>';
    }

    private function appendActiveQuoteLinkToText(string $text, $opportunity): string
    {
        $url = $this->activeQuoteUrl($opportunity);

        if (! $url || str_contains($text, $url)) {
            return $text;
        }

        return rtrim($text)."\n\nYou can view the active quote here:\n".$url;
    }

    private function activeQuoteUrl($opportunity): ?string
    {
        $version = $opportunity->currentQuoteVersion;

        if (! $version || ! in_array($version->status, ['draft', 'sent'], true)) {
            return null;
        }

        return route('sales.quotes.public.view', $version->secure_token);
    }

    private function appendReplyBoundaryToText(string $text): string
    {
        return str_contains($text, self::REPLY_ABOVE_LINE)
            ? $text
            : rtrim($text)."\n\n".self::REPLY_ABOVE_LINE;
    }

    private function log(?int $accountId, int $activityId, string $level, string $code, string $message, array $context = [], ?string $rfcMessageId = null): void
    {
        EmailLog::create([
            'direction' => 'outbound',
            'account_id' => $accountId,
            'scope' => 'sales',
            'level' => $level,
            'code' => $code,
            'message' => $message,
            'context_json' => array_merge(['sales_activity_id' => $activityId], $context),
            'rfc_message_id' => $rfcMessageId,
        ]);
    }
}
