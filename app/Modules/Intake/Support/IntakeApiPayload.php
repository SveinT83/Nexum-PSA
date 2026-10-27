<?php

namespace App\Modules\Intake\Support;

use App\Modules\Intake\Models\IntakeForm;
use App\Modules\Intake\Models\IntakeFormField;
use App\Modules\Intake\Models\IntakeSubmission;
use App\Modules\Intake\Models\IntakeSubmissionAttachment;
use Illuminate\Http\Request;

/** Explicit projections keep storage paths, honeypot contents and raw exceptions off the API. */
class IntakeApiPayload
{
    public static function form(IntakeForm $form, bool $public = false): array
    {
        $form->loadMissing($public ? 'activeFields' : 'fields');
        $data = [
            'slug' => $form->slug, 'name' => $form->name, 'description' => $form->description,
            'language' => $form->language(), 'submit_button_label' => $form->submitButtonLabel(),
            'success_message' => $form->success_message ?: 'Thank you. Your request has been received.',
            'spam_honeypot_field' => $form->spam_honeypot_field,
            'max_files' => $form->max_files, 'max_file_size_kb' => $form->max_file_size_kb,
            'allowed_mime_types' => $form->allowedMimeTypes(),
            'fields' => ($public ? $form->activeFields : $form->fields)->map(fn ($field) => self::field($field, $public))->values()->all(),
        ];
        if (! $public) {
            $data += ['id' => $form->id, 'status' => $form->status, 'purpose' => $form->purpose(),
                'scope_type' => $form->scopeType(), 'scope_client_id' => $form->scopeClientId(),
                'scope_service_id' => $form->scopeServiceId(), 'scope_campaign_key' => $form->campaignKey(),
                'routing_mode' => $form->routingMode(), 'target_type' => $form->target_type,
                'owner_id' => $form->owner_id, 'auto_create_client' => $form->auto_create_client,
                'auto_create_contact' => $form->auto_create_contact,
                'revision' => IntakeApiInput::revision($form), 'submissions_count' => $form->submissions()->count()];
        }

        return $data;
    }

    public static function field(IntakeFormField $field, bool $public = false): array
    {
        $data = ['key' => $field->key, 'label' => $field->label, 'field_type' => $field->field_type,
            'help_text' => $field->help_text, 'placeholder' => $field->placeholder,
            'options' => $field->options ?: [], 'is_required' => $field->is_required,
            'sort_order' => $field->sort_order, 'layout_width' => $field->layoutWidth(),
            'visibility' => $field->visibility(), 'max_files' => $field->maxFiles(),
            'max_file_size_kb' => $field->maxFileSizeKb(), 'allowed_mime_types' => $field->allowedMimeTypes()];
        if (! $public) {
            $data += ['id' => $field->id, 'maps_to' => $field->maps_to, 'is_active' => $field->is_active];
        }

        return $data;
    }

    public static function attachment(IntakeSubmissionAttachment $file): array
    {
        return ['id' => $file->id, 'submission_id' => $file->intake_submission_id,
            'field_id' => $file->intake_form_field_id, 'filename' => $file->original_filename ?: $file->filename,
            'content_type' => $file->content_type, 'size_bytes' => $file->size_bytes,
            'checksum_sha1' => $file->checksum_sha1, 'field_key' => $file->metadata['field_key'] ?? null,
            'field_label' => $file->metadata['field_label'] ?? null];
    }

    /** Native intake.view owns submission values; related-domain links need their own current rights. */
    public static function submission(IntakeSubmission $submission, Request $request): array
    {
        $submission->refresh()->load(['attachments', 'form']);
        $data = $submission->only(['id', 'intake_form_id', 'status', 'source_url', 'referrer',
            'ip_address', 'user_agent', 'raw_payload', 'normalized_payload', 'submitted_at',
            'reviewed_at', 'reviewed_by']);
        $data['form_slug'] = $submission->form?->slug;
        $data['revision'] = IntakeApiInput::revision($submission);
        $data['attachments'] = $submission->attachments->map(self::attachment(...))->all();
        $data['matched_client_id'] = self::related($request, 'client.view') ? $submission->matched_client_id : null;
        $data['matched_site_id'] = self::related($request, 'client.view') ? $submission->matched_site_id : null;
        $data['matched_contact_id'] = self::related($request, 'contact.view') ? $submission->matched_contact_id : null;
        $data['matched_client_user_id'] = self::related($request, 'contact.view') ? $submission->matched_client_user_id : null;
        $permission = match ($submission->target_type) {
            \App\Modules\Ticket\Models\Ticket::class => 'ticket.view',
            \App\Modules\Task\Models\Task::class => 'task.view',
            \App\Modules\Sales\Models\SalesOpportunity::class => 'sales.view',
            default => null,
        };
        $data['target'] = $permission && self::related($request, $permission)
            ? ['type' => $submission->target_type, 'id' => $submission->target_id] : null;
        // Arbitrary legacy routing metadata can contain exception details or related record IDs.
        $data['routing_result'] = \Illuminate\Support\Arr::only($submission->routing_result ?: [],
            ['action', 'reason', 'routing_mode', 'target_type', 'auto_created_client', 'auto_created_contact']);

        return $data;
    }

    public static function events(IntakeSubmission $submission)
    {
        return $submission->events()->get()->map(fn ($event) => [
            'id' => $event->id, 'type' => $event->type, 'actor_id' => $event->actor_id,
            'message' => in_array($event->type, ['signal_recording_failed', 'routing_failed'])
                ? 'Processing requires staff review.' : $event->message,
            'created_at' => $event->created_at?->toISOString(),
            'before' => \Illuminate\Support\Arr::only($event->before ?: [], ['status', 'reviewed_at', 'reviewed_by']),
            'after' => \Illuminate\Support\Arr::only($event->after ?: [], ['status', 'reviewed_at', 'reviewed_by']),
        ]);
    }

    private static function related(Request $request, string $permission): bool
    {
        $allowed = IntakeApiAccess::permits($request->user(), $permission);
        if ($allowed) {
            $permissions = $request->attributes->get('intake_result_permissions', []);
            $request->attributes->set('intake_result_permissions', array_values(array_unique([...$permissions, $permission])));
        }

        return $allowed;
    }
}
