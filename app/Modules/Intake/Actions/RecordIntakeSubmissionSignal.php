<?php

namespace App\Modules\Intake\Actions;

use App\Modules\Intake\Models\IntakeSubmission;
use App\Modules\Signal\Actions\RecordSignal;
use App\Modules\Signal\Models\Signal;

class RecordIntakeSubmissionSignal
{
    public function __construct(private readonly RecordSignal $signals)
    {
    }

    public function handle(IntakeSubmission $submission): Signal
    {
        $submission->loadMissing(['form', 'attachments.field']);

        $existing = Signal::query()
            ->where('source_domain', 'intake')
            ->where('source_type', $submission->getMorphClass())
            ->where('source_id', $submission->id)
            ->where('signal_type', 'intake_submission_received')
            ->first();

        if ($existing) {
            return $existing;
        }

        $signal = $this->signals->handle([
            'source_domain' => 'intake',
            'source_type' => $submission->getMorphClass(),
            'source_id' => $submission->id,
            'subject_type' => $submission->form?->getMorphClass(),
            'subject_id' => $submission->form?->id,
            'contact_id' => $submission->matched_contact_id,
            'client_id' => $submission->matched_client_id,
            'signal_type' => 'intake_submission_received',
            'severity' => 'info',
            'confidence' => 100,
            'summary' => 'Intake submission: '.($submission->form?->name ?: 'Unknown form'),
            'payload' => $this->payload($submission),
            'occurred_at' => $submission->submitted_at ?: now(),
        ]);

        $submission->events()->create([
            'type' => 'signal_recorded',
            'message' => 'Recorded Signal automation event.',
            'metadata' => [
                'signal_id' => $signal->id,
                'signal_type' => $signal->signal_type,
            ],
        ]);

        return $signal;
    }

    private function payload(IntakeSubmission $submission): array
    {
        return [
            'intake_form_id' => $submission->intake_form_id,
            'intake_form_slug' => $submission->form?->slug,
            'intake_form_name' => $submission->form?->name,
            'intake_submission_id' => $submission->id,
            'submission_status' => $submission->status,
            'submitted_at' => $submission->submitted_at?->toIso8601String(),
            'target_type' => $submission->target_type,
            'target_id' => $submission->target_id,
            'routing_result' => $submission->routing_result ?: [],
            'matched_client_id' => $submission->matched_client_id,
            'matched_site_id' => $submission->matched_site_id,
            'matched_contact_id' => $submission->matched_contact_id,
            'matched_client_user_id' => $submission->matched_client_user_id,
            'source_url' => $submission->source_url,
            'referrer' => $submission->referrer,
            'fields' => $submission->raw_payload['fields'] ?? [],
            'normalized' => $submission->normalized_payload ?: [],
            'attachments' => $submission->attachments
                ->map(fn ($attachment): array => [
                    'id' => $attachment->id,
                    'field_key' => $attachment->field?->key,
                    'field_label' => $attachment->field?->label,
                    'filename' => $attachment->filename,
                    'original_filename' => $attachment->original_filename,
                    'content_type' => $attachment->content_type,
                    'size_bytes' => $attachment->size_bytes,
                    'checksum_sha1' => $attachment->checksum_sha1,
                ])
                ->values()
                ->all(),
            'attachment_count' => $submission->attachments->count(),
        ];
    }
}
