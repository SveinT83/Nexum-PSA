<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Models\EmailTemplate;
use App\Modules\System\Support\CompanyProfileSettings;

class EmailTemplateRenderer
{
    /*
    |--------------------------------------------------------------------------
    | Email template renderer
    |--------------------------------------------------------------------------
    |
    | Content and document layout are intentionally separate. Branding-managed
    | templates materialize the current organization profile on every render;
    | custom templates preserve their stored layout until explicitly reset.
    |
    */
    public function __construct(
        private readonly CompanyProfileSettings $companyProfile,
        private readonly OutboundEmailHtmlPolicy $htmlPolicy,
    ) {}

    public function render(EmailTemplate $template, array $variables): array
    {
        $variables = array_merge($this->brandingVariables(), $variables);
        unset($variables['email_body']);

        $this->htmlPolicy->assertBody($template->body_html);
        $body = $this->replace($template->body_html ?? '', $variables);
        $layout = $this->replace($this->materializeLayout($template), $variables);
        $html = preg_replace_callback(
            OutboundEmailHtmlPolicy::BODY_SLOT_PATTERN,
            static fn (): string => $body,
            $layout,
            1,
        ) ?? $layout;

        return [
            'subject' => $this->replace($template->subject, $variables),
            'html' => $html,
            'text' => $this->replace($template->body_text ?? '', $variables),
        ];
    }

    /**
     * Return the exact outer document used for this template, with the body slot intact.
     */
    public function materializeLayout(EmailTemplate $template): string
    {
        $layout = $template->usesCustomLayout()
            ? (string) $template->layout_html
            : $this->brandingLayout();

        $this->htmlPolicy->assertLayout($layout);

        return $layout;
    }

    /**
     * Produce the current light-theme email layout from organization branding.
     */
    public function brandingLayout(): string
    {
        $profile = $this->companyProfile->get();
        $companyName = e($profile['company_name'] ?? config('app.name', 'Nexum PSA'));
        $headerBackground = e($profile['light_header_background'] ?? '#333333');
        $headerColor = e($profile['light_header_color'] ?? '#ffffff');
        $footerBackground = e($profile['light_footer_background'] ?? '#333333');
        $footerColor = e($profile['light_footer_color'] ?? '#ffffff');
        $pageBackground = e($profile['light_main_background'] ?? '#f3f4f6');
        $contentBackground = e($profile['light_content_background'] ?? '#ffffff');
        $contentColor = e($profile['light_left_sidebar_color'] ?? '#212529');
        $linkColor = e($profile['light_primary_button_background'] ?? $profile['primary_color'] ?? '#FF6D1F');
        $accentColor = e($profile['accent_color'] ?? '#e5e7eb');
        $logoUrl = $profile['logo_light_url'] ?? $profile['logo_url'] ?? null;
        $website = $profile['website'] ?? null;
        $supportEmail = $profile['support_email'] ?? null;
        $logo = filled($logoUrl)
            ? '<img src="'.e($logoUrl).'" alt="'.$companyName.'" style="display:block;max-height:52px;max-width:240px;width:auto;height:auto;">'
            : '<strong style="font-size:20px;color:'.$headerColor.';">'.$companyName.'</strong>';
        $footerParts = array_filter([
            $website ? '<a href="'.e($website).'" style="color:'.$footerColor.';">'.e($website).'</a>' : null,
            $supportEmail ? '<a href="mailto:'.e($supportEmail).'" style="color:'.$footerColor.';">'.e($supportEmail).'</a>' : null,
        ]);
        $footerLinks = $footerParts !== []
            ? '<div style="margin-top:8px;">'.implode(' &middot; ', $footerParts).'</div>'
            : '';

        return '<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>'.$companyName.'</title>
  <style>
    .email-content a { color: '.$linkColor.'; }
    .email-content img { max-width: 100%; height: auto; }
  </style>
</head>
<body style="margin:0;padding:0;background:'.$pageBackground.';font-family:Arial,Helvetica,sans-serif;color:'.$contentColor.';">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="width:100%;background:'.$pageBackground.';padding:24px 12px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="width:100%;max-width:680px;background:'.$contentBackground.';border:1px solid '.$accentColor.';border-radius:8px;overflow:hidden;">
          <tr>
            <td style="background:'.$headerBackground.';color:'.$headerColor.';padding:20px 24px;">'.$logo.'</td>
          </tr>
          <tr>
            <td class="email-content" style="background:'.$contentBackground.';color:'.$contentColor.';padding:32px 24px;font-size:15px;line-height:1.6;">'.OutboundEmailHtmlPolicy::BODY_SLOT.'</td>
          </tr>
          <tr>
            <td style="background:'.$footerBackground.';color:'.$footerColor.';padding:18px 24px;font-size:12px;line-height:1.5;">
              <div>'.$companyName.'</div>
              '.$footerLinks.'
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>';
    }

    public function sampleVariables(EmailTemplate $template): array
    {
        $branding = $this->brandingVariables();
        $samples = [
            'app_name' => config('app.name', 'Nexum PSA'),
            'author_name' => 'Admin User',
            'campaign_email_name' => 'First touch',
            'campaign_name' => 'Example marketing campaign',
            'client_name' => 'Example Client AS',
            'contact_email' => 'contact@example.test',
            'contact_name' => 'Ola Nordmann',
            'current_status' => 'In Progress',
            'expires_at' => now()->addDays(14)->format('Y-m-d'),
            'expires_hours' => '48',
            'invite_url' => url('/invite/example'),
            'message_body' => 'This is a sample message body.',
            'message_subject' => 'Sample message',
            'note_body' => 'This is an internal sample note.',
            'notification_body' => 'This is a sample notification.',
            'notification_subject' => 'Sample notification',
            'opportunity_key' => 'OPP-2026-0001',
            'opportunity_title' => 'Managed services proposal',
            'portal_invite_url' => url('/portal/invitations/example'),
            'previous_status' => 'New',
            'quote_key' => 'Q-2026-0001',
            'quote_url' => url('/quote/view/example'),
            'quote_summary_html' => 'One-time: 5 200,00 NOK ex VAT<br>Recurring monthly: 551,00 NOK/month ex VAT',
            'quote_summary_text' => "One-time: 5 200,00 NOK ex VAT\nRecurring monthly: 551,00 NOK/month ex VAT",
            'quote_customer_copy_html' => '<p><strong>Introduction</strong><br>Example solution description.</p>',
            'quote_customer_copy_text' => "Introduction\nExample solution description.",
            'seller_name' => 'Sales User',
            'site_name' => 'Main Office',
            'status_message' => 'We have started working on your Ticket.',
            'support_email' => $branding['support_email'] ?: 'support@example.test',
            'technician_name' => 'Technician User',
            'ticket_key' => 'TD-2026-0001',
            'ticket_subject' => 'Sample ticket',
            'total_ex_vat' => '10 000 NOK',
            'total_inc_vat' => '12 500 NOK',
            'unsubscribe_url' => url('/marketing/unsubscribe/example'),
            'user_email' => 'user@example.test',
            'user_name' => 'Example User',
            'website' => $branding['website'] ?: url('/'),
        ];
        $base = array_merge($branding, $samples);

        return array_merge(
            $base,
            collect((array) $template->variables)
                ->mapWithKeys(fn (string $variable): array => [$variable => $base[$variable] ?? '{{ '.$variable.' }}'])
                ->all(),
        );
    }

    private function replace(string $content, array $variables): string
    {
        foreach ($variables as $key => $value) {
            $content = str_replace('{{ '.$key.' }}', (string) $value, $content);
            $content = str_replace('{{'.$key.'}}', (string) $value, $content);
        }

        return $content;
    }

    private function brandingVariables(): array
    {
        $profile = $this->companyProfile->get();

        return [
            'company_name' => $profile['company_name'] ?? config('app.name', 'Nexum PSA'),
            'company_legal_name' => $profile['legal_name'] ?? null,
            'company_logo_url' => $profile['logo_url'] ?? null,
            'company_logo_light_url' => $profile['logo_light_url'] ?? null,
            'company_logo_dark_url' => $profile['logo_dark_url'] ?? null,
            'brand_primary' => $profile['primary_color'] ?? '#FF6D1F',
            'brand_secondary' => $profile['secondary_color'] ?? '#fc7730',
            'brand_accent' => $profile['accent_color'] ?? '#faba98',
            'brand_header_background' => $profile['light_header_background'] ?? '#333333',
            'brand_header_color' => $profile['light_header_color'] ?? '#ffffff',
            'brand_footer_background' => $profile['light_footer_background'] ?? '#333333',
            'brand_footer_color' => $profile['light_footer_color'] ?? '#ffffff',
            'brand_page_background' => $profile['light_main_background'] ?? '#f3f4f6',
            'brand_content_background' => $profile['light_content_background'] ?? '#ffffff',
            'brand_content_color' => $profile['light_left_sidebar_color'] ?? '#212529',
            'brand_action_background' => $profile['light_primary_button_background'] ?? '#FF6D1F',
            'brand_action_color' => $profile['light_primary_button_color'] ?? '#ffffff',
            'support_email' => $profile['support_email'] ?? null,
            'website' => $profile['website'] ?? null,
        ];
    }
}
