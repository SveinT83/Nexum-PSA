<?php

namespace App\Modules\Sales\Services;

use App\Modules\Email\Models\EmailTemplate;
use App\Modules\Email\Services\EmailTemplateRenderer;
use App\Modules\Sales\Models\SalesActivity;
use App\Modules\Sales\Models\SalesQuoteVersion;
use App\Modules\Sales\Support\SalesQuotePresentation;

/** Renders reviewed outbound intent once using the same templates/branding as existing Sales jobs. */
class SalesEmailContent
{
    private const REPLY_ABOVE_LINE = '--- Please reply above this line ---';

    private function template(string $key): ?EmailTemplate
    {
        return EmailTemplate::query()->where('scope', 'sales')->where('key', $key)->where('is_active', true)->first();
    }

    public function quote(SalesQuoteVersion $version): array
    {
        $version->loadMissing(['quote.opportunity.client', 'quote.opportunity.primaryContact', 'quote.opportunity.owner', 'lines']);
        $opportunity = $version->quote->opportunity;
        $recipient = $opportunity->primaryContact;
        $template = $this->template('sales_quote_send');
        if (! $recipient?->email) {
            return ['error_code' => 'no_recipient', 'to_email' => null];
        }
        if (! $template) {
            return ['error_code' => 'no_template', 'to_email' => $recipient->email, 'to_name' => $recipient->name];
        }
        $renderer = app(EmailTemplateRenderer::class);
        $quotePresentation = app(SalesQuotePresentation::class);
        $presentation = $quotePresentation->forVersion($version);
        $rendered = $renderer->render($template, [
            'opportunity_key' => $opportunity->opportunity_key,
            'opportunity_title' => $opportunity->title,
            'client_name' => $opportunity->client?->name ?? '',
            'contact_name' => $recipient->name ?: 'there',
            'quote_key' => $version->quote->quote_key,
            'quote_url' => route('sales.quotes.public.view', $version->secure_token),
            'total_ex_vat' => number_format((float) $version->total_ex_vat, 2, '.', ' '),
            'total_inc_vat' => number_format((float) $version->total_inc_vat, 2, '.', ' '),
            'quote_summary_html' => $presentation['summary_html'],
            'quote_summary_text' => $presentation['summary_text'],
            'quote_customer_copy_html' => $presentation['customer_copy_html'],
            'quote_customer_copy_text' => $presentation['customer_copy_text'],
            'expires_at' => $version->expires_at?->toDateString() ?? '',
            'seller_name' => $opportunity->owner?->name ?? 'Sales',
        ]);

        return $rendered + ['to_email' => $recipient->email, 'to_name' => $recipient->name, 'cc' => [], 'error_code' => null];
    }

    public function activity(SalesActivity $activity): array
    {
        $opportunity = $activity->opportunity;
        $metadata = $activity->metadata ?? [];
        $toName = $metadata['to_name'] ?? '';
        $template = $this->template('sales_activity_email');
        if (! $template) {
            return ['error_code' => 'no_template', 'to_email' => $metadata['to_email'] ?? null];
        }
        $renderer = app(EmailTemplateRenderer::class);
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

        $rendered['html'] = $this->appendReplyBoundaryToHtml($rendered['html']);
        $rendered['text'] = $this->appendReplyBoundaryToText($rendered['text']);

        return $rendered + ['to_email' => $metadata['to_email'] ?? null, 'to_name' => $toName, 'cc' => $this->ccRecipients($metadata['cc'] ?? []), 'error_code' => null];
    }

    public function internal(SalesActivity $activity): array
    {
        $notifyId = $activity->metadata['notify_user_id'] ?? null;
        $recipient = $notifyId ? \App\Models\Core\User::query()->whereKey($notifyId)->where('status', \App\Models\Core\User::STATUS_ACTIVE)->first() : null;
        $template = $this->template('sales_internal_note');
        if (! $recipient?->email) {
            return ['error_code' => 'no_recipient', 'to_email' => null];
        }
        if (! $template) {
            return ['error_code' => 'no_template', 'to_email' => $recipient->email];
        }
        $renderer = app(EmailTemplateRenderer::class);
        $opportunity = $activity->opportunity;
        $author = $activity->actor?->name ?? 'A seller';
        $rendered = $renderer->render($template, [
            'opportunity_key' => $opportunity->opportunity_key,
            'opportunity_title' => $opportunity->title,
            'client_name' => $opportunity->client?->name ?? '',
            'author_name' => $author,
            'note_body' => $activity->body,
        ]);

        return $rendered + ['to_email' => $recipient->email, 'to_name' => $recipient->name, 'cc' => [], 'error_code' => null];
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
}
