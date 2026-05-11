<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Models\EmailTemplate;

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
    public function render(EmailTemplate $template, array $variables): array
    {
        return [
            'subject' => $this->replace($template->subject, $variables),
            'html' => $this->replace($template->body_html ?? '', $variables),
            'text' => $this->replace($template->body_text ?? '', $variables),
        ];
    }

    private function replace(string $content, array $variables): string
    {
        foreach ($variables as $key => $value) {
            $content = str_replace('{{ ' . $key . ' }}', (string) $value, $content);
            $content = str_replace('{{' . $key . '}}', (string) $value, $content);
        }

        return $content;
    }
}
