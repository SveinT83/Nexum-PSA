<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Models\EmailTemplate;
use App\Modules\System\Support\CompanyProfileSettings;

class EmailTemplateRenderer
{
    /*
    |--------------------------------------------------------------------------
    | Simple variable renderer
    |--------------------------------------------------------------------------
    |
    | Version 1 supports direct {{ variable }} replacement. Future versions can
    | add branding, language fallback, partials, and stricter variable checks
    | without changing outbound jobs.
    |
    */
    public function __construct(private readonly CompanyProfileSettings $companyProfile)
    {
    }

    public function render(EmailTemplate $template, array $variables): array
    {
        $variables = array_merge($this->brandingVariables(), $variables);
        $html = $this->replace($template->body_html ?? '', $variables);

        return [
            'subject' => $this->replace($template->subject, $variables),
            'html' => $html !== '' ? $this->wrapHtml($html, $variables) : '',
            'text' => $this->replace($template->body_text ?? '', $variables),
        ];
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
            'contact_name' => 'Ola Nordmann',
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
            'quote_key' => 'Q-2026-0001',
            'quote_url' => url('/quote/view/example'),
            'seller_name' => 'Sales User',
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
            $content = str_replace('{{ ' . $key . ' }}', (string) $value, $content);
            $content = str_replace('{{' . $key . '}}', (string) $value, $content);
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
            'support_email' => $profile['support_email'] ?? null,
            'website' => $profile['website'] ?? null,
        ];
    }

    private function wrapHtml(string $body, array $variables): string
    {
        if (str_contains(strtolower($body), '<html')) {
            return $body;
        }

        $companyName = e($variables['company_name'] ?? config('app.name', 'Nexum PSA'));
        $primary = e($variables['brand_primary'] ?? '#FF6D1F');
        $secondary = e($variables['brand_secondary'] ?? '#fc7730');
        $logoUrl = $variables['company_logo_url'] ?? null;
        $website = $variables['website'] ?? null;
        $supportEmail = $variables['support_email'] ?? null;
        $logo = filled($logoUrl)
            ? '<img src="'.e($logoUrl).'" alt="'.$companyName.'" style="max-height:48px;max-width:220px;">'
            : '<strong style="font-size:20px;color:#ffffff;">'.$companyName.'</strong>';

        $footerParts = array_filter([
            $website ? '<a href="'.e($website).'" style="color:'.$primary.';">'.e($website).'</a>' : null,
            $supportEmail ? '<a href="mailto:'.e($supportEmail).'" style="color:'.$primary.';">'.e($supportEmail).'</a>' : null,
        ]);
        $footer = $footerParts !== []
            ? '<div style="margin-top:8px;">'.implode(' &middot; ', $footerParts).'</div>'
            : '';

        return '<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>'.$companyName.'</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f3f4f6;padding:24px 0;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:680px;background:#ffffff;border:1px solid #e5e7eb;">
          <tr>
            <td style="background:'.$secondary.';padding:18px 24px;">'.$logo.'</td>
          </tr>
          <tr>
            <td style="padding:28px 24px;font-size:15px;line-height:1.55;">'.$body.'</td>
          </tr>
          <tr>
            <td style="padding:18px 24px;border-top:1px solid #e5e7eb;color:#6b7280;font-size:12px;">
              <div>'.$companyName.'</div>
              '.$footer.'
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>';
    }
}
