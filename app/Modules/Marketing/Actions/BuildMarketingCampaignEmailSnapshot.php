<?php

namespace App\Modules\Marketing\Actions;

use App\Modules\Email\Models\EmailTemplate;
use App\Modules\Email\Services\EmailTemplateRenderer;
use App\Modules\Email\Services\OutboundEmailHtmlPolicy;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class BuildMarketingCampaignEmailSnapshot
{
    public function __construct(
        private readonly EmailTemplateRenderer $renderer,
        private readonly OutboundEmailHtmlPolicy $htmlPolicy,
    ) {}

    public function fromTemplate(EmailTemplate $template, array $data): array
    {
        $name = $this->filledString($data['name'] ?? null) ?: $template->name;
        $bodyHtml = $this->nullableContent($data['body_html'] ?? null, $template->body_html);
        $this->assertBody($bodyHtml);

        return [
            'email_template_id' => $template->id,
            'name' => $name,
            'template_snapshot_name' => $template->name,
            'subject_snapshot' => $this->filledString($data['email_subject'] ?? null) ?: $template->subject,
            'body_html_snapshot' => $bodyHtml,
            'body_text_snapshot' => $this->nullableContent($data['body_text'] ?? null, $template->body_text),
            'layout_html_snapshot' => $this->renderer->materializeLayout($template),
            'variables_snapshot' => (array) $template->variables,
        ];
    }

    public function editableContent(array $data): array
    {
        $bodyHtml = $this->nullableContent($data['body_html'] ?? null);
        $this->assertBody($bodyHtml);

        return [
            'name' => $this->filledString($data['name'] ?? null),
            'subject_snapshot' => $this->filledString($data['email_subject'] ?? null),
            'body_html_snapshot' => $bodyHtml,
            'body_text_snapshot' => $this->nullableContent($data['body_text'] ?? null),
        ];
    }

    private function filledString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function nullableContent(mixed $value, ?string $fallback = null): ?string
    {
        if ($value === null) {
            return $fallback;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : $fallback;
    }

    private function assertBody(?string $html): void
    {
        try {
            $this->htmlPolicy->assertBody($html);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['body_html' => $exception->getMessage()]);
        }
    }
}
