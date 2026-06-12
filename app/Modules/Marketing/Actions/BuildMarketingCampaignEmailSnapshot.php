<?php

namespace App\Modules\Marketing\Actions;

use App\Modules\Email\Models\EmailTemplate;

class BuildMarketingCampaignEmailSnapshot
{
    public function fromTemplate(EmailTemplate $template, array $data): array
    {
        $name = $this->filledString($data['name'] ?? null) ?: $template->name;

        return [
            'email_template_id' => $template->id,
            'name' => $name,
            'template_snapshot_name' => $template->name,
            'subject_snapshot' => $this->filledString($data['email_subject'] ?? null) ?: $template->subject,
            'body_html_snapshot' => $this->nullableContent($data['body_html'] ?? null, $template->body_html),
            'body_text_snapshot' => $this->nullableContent($data['body_text'] ?? null, $template->body_text),
            'variables_snapshot' => (array) $template->variables,
        ];
    }

    public function editableContent(array $data): array
    {
        return [
            'name' => $this->filledString($data['name'] ?? null),
            'subject_snapshot' => $this->filledString($data['email_subject'] ?? null),
            'body_html_snapshot' => $this->nullableContent($data['body_html'] ?? null),
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
}
