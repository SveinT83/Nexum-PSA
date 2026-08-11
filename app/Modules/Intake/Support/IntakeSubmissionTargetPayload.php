<?php

namespace App\Modules\Intake\Support;

use App\Modules\Intake\Models\IntakeForm;
use App\Modules\Intake\Models\IntakeSubmission;

class IntakeSubmissionTargetPayload
{
    public function title(IntakeSubmission $submission): string
    {
        $normalized = $submission->normalized_payload ?: [];
        $subject = $this->stringValue($normalized['subject'] ?? null);

        if ($subject !== '') {
            return $subject;
        }

        $fallback = $this->stringValue($normalized['company_name'] ?? null)
            ?: $this->stringValue($normalized['contact_name'] ?? null)
            ?: $this->stringValue($normalized['contact_email'] ?? null)
            ?: 'Submission #'.$submission->id;

        return 'Intake: '.$fallback;
    }

    public function description(IntakeSubmission $submission): string
    {
        $submission->loadMissing(['form', 'attachments.field', 'matchedClient', 'matchedSite', 'matchedClientUser']);

        $normalized = $submission->normalized_payload ?: [];
        $rawFields = data_get($submission->raw_payload, 'fields', []);
        $lines = [
            'Intake submission #'.$submission->id,
            'Form: '.($submission->form?->name ?? 'Deleted form'),
            'Scope: '.$this->scopeSummary($submission),
            'Submitted: '.($submission->submitted_at?->format('Y-m-d H:i') ?? '-'),
            '',
            'Mapped fields:',
        ];

        foreach ($normalized as $key => $value) {
            $lines[] = ucfirst(str_replace('_', ' ', (string) $key)).': '.$this->stringValue($value);
        }

        if ($normalized === []) {
            $lines[] = '-';
        }

        $unmapped = collect($rawFields)
            ->reject(fn ($value, $key): bool => array_key_exists((string) $key, $normalized))
            ->all();

        if ($unmapped !== []) {
            $lines[] = '';
            $lines[] = 'Submitted fields:';

            foreach ($unmapped as $key => $value) {
                $lines[] = (string) $key.': '.$this->stringValue($value);
            }
        }

        if ($submission->attachments->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Attachments retained in Intake:';

            foreach ($submission->attachments as $attachment) {
                $size = $attachment->size_bytes ? number_format($attachment->size_bytes / 1024, 1).' KB' : 'unknown size';
                $lines[] = '- '.($attachment->original_filename ?: $attachment->filename).' ('.$size.')';
            }
        }

        return implode("\n", $lines);
    }

    public function metadata(IntakeSubmission $submission, string $target): array
    {
        $form = $submission->form;

        return [
            'created_from' => 'intake_submission',
            'intake_submission_id' => $submission->id,
            'intake_form_id' => $submission->intake_form_id,
            'intake_form_slug' => $form?->slug,
            'intake_target' => $target,
            'attachment_count' => $submission->attachments->count(),
            'scope_type' => $form?->scopeType(),
            'scope_client_id' => $form?->scopeClientId(),
            'scope_service_id' => $form?->scopeServiceId(),
            'scope_campaign_key' => $form?->campaignKey(),
            'routing_mode' => $form?->routingMode(),
        ];
    }

    public function scopeSummary(IntakeSubmission $submission): string
    {
        $form = $submission->form;

        if (! $form) {
            return 'Global';
        }

        return match ($form->scopeType()) {
            IntakeForm::SCOPE_CLIENT => 'Client #'.($form->scopeClientId() ?: '-'),
            IntakeForm::SCOPE_SERVICE => 'Service #'.($form->scopeServiceId() ?: '-'),
            IntakeForm::SCOPE_SALES => 'Sales',
            IntakeForm::SCOPE_TICKET => 'Ticket',
            IntakeForm::SCOPE_CAMPAIGN => 'Campaign '.($form->campaignKey() ?: '-'),
            default => 'Global',
        };
    }

    private function stringValue(mixed $value): string
    {
        if (is_array($value)) {
            return trim(implode(', ', array_filter(array_map('strval', $value))));
        }

        return trim((string) $value) ?: '-';
    }
}
