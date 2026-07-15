<?php

namespace App\Modules\Intake\Actions;

use App\Modules\Intake\Models\IntakeForm;
use App\Modules\Intake\Models\IntakeFormField;

class EnsureIntakeDefaults
{
    public function handle(): IntakeForm
    {
        $form = IntakeForm::query()->firstOrCreate(
            ['slug' => 'website-inquiry'],
            [
                'name' => 'Website inquiry',
                'description' => 'Default public inquiry form for new customer requests.',
                'status' => IntakeForm::STATUS_ACTIVE,
                'success_message' => 'Thank you. Your request has been received.',
                'target_type' => IntakeForm::TARGET_REVIEW_ONLY,
                'auto_create_client' => false,
                'auto_create_contact' => true,
                'spam_honeypot_field' => 'intake_website',
                'max_files' => 5,
                'max_file_size_kb' => 20480,
                'allowed_mime_types' => IntakeForm::DEFAULT_ALLOWED_MIME_TYPES,
            ],
        );

        foreach ($this->fields() as $index => $field) {
            IntakeFormField::query()->updateOrCreate(
                ['intake_form_id' => $form->id, 'key' => $field['key']],
                array_merge($field, ['sort_order' => $index * 10]),
            );
        }

        return $form->refresh();
    }

    private function fields(): array
    {
        return [
            [
                'key' => 'company_name',
                'label' => 'Company',
                'field_type' => IntakeFormField::TYPE_TEXT,
                'maps_to' => IntakeFormField::MAP_COMPANY_NAME,
                'placeholder' => 'Company name',
                'is_required' => true,
                'is_active' => true,
            ],
            [
                'key' => 'contact_name',
                'label' => 'Contact person',
                'field_type' => IntakeFormField::TYPE_TEXT,
                'maps_to' => IntakeFormField::MAP_CONTACT_NAME,
                'placeholder' => 'Full name',
                'is_required' => true,
                'is_active' => true,
            ],
            [
                'key' => 'contact_email',
                'label' => 'Email',
                'field_type' => IntakeFormField::TYPE_EMAIL,
                'maps_to' => IntakeFormField::MAP_CONTACT_EMAIL,
                'placeholder' => 'name@example.com',
                'is_required' => true,
                'is_active' => true,
            ],
            [
                'key' => 'contact_phone',
                'label' => 'Phone',
                'field_type' => IntakeFormField::TYPE_PHONE,
                'maps_to' => IntakeFormField::MAP_CONTACT_PHONE,
                'placeholder' => '+47 ...',
                'is_required' => false,
                'is_active' => true,
            ],
            [
                'key' => 'subject',
                'label' => 'Subject',
                'field_type' => IntakeFormField::TYPE_TEXT,
                'maps_to' => IntakeFormField::MAP_SUBJECT,
                'placeholder' => 'What do you need help with?',
                'is_required' => true,
                'is_active' => true,
            ],
            [
                'key' => 'message',
                'label' => 'Message',
                'field_type' => IntakeFormField::TYPE_TEXTAREA,
                'maps_to' => IntakeFormField::MAP_MESSAGE,
                'placeholder' => 'Describe the request.',
                'is_required' => true,
                'is_active' => true,
            ],
            [
                'key' => 'attachments',
                'label' => 'Files',
                'field_type' => IntakeFormField::TYPE_FILE,
                'maps_to' => null,
                'help_text' => 'Upload supporting PDFs, images, documents, spreadsheets, or text files.',
                'is_required' => false,
                'is_active' => true,
                'max_files' => 5,
                'max_file_size_kb' => 20480,
                'allowed_mime_types' => IntakeForm::DEFAULT_ALLOWED_MIME_TYPES,
            ],
            [
                'key' => 'consent',
                'label' => 'I confirm that the submitted information can be used to process this request.',
                'field_type' => IntakeFormField::TYPE_CONSENT,
                'maps_to' => IntakeFormField::MAP_CONSENT,
                'is_required' => true,
                'is_active' => true,
            ],
        ];
    }
}
