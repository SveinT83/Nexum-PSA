<?php

namespace App\Modules\Intake\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Modules\Commercial\Models\Services\Services;
use App\Modules\Intake\Models\IntakeForm;
use App\Modules\Intake\Models\IntakeFormField;
use App\Modules\Intake\Support\IntakeFormFieldInput;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class IntakeFormController extends Controller
{
    public function __construct(private readonly IntakeFormFieldInput $fieldInput) {}

    public function create(): View
    {
        return view('intake::Admin.forms.create', [
            'form' => new IntakeForm([
                'status' => IntakeForm::STATUS_DRAFT,
                'target_type' => IntakeForm::TARGET_REVIEW_ONLY,
                'auto_create_contact' => true,
                'spam_honeypot_field' => 'intake_website',
                'max_files' => 5,
                'max_file_size_kb' => 20480,
                'allowed_mime_types' => IntakeForm::DEFAULT_ALLOWED_MIME_TYPES,
            ]),
            'fieldRows' => [],
            'owners' => $this->owners(),
            'clients' => $this->clients(),
            'services' => $this->services(),
            'mode' => 'create',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatedFormData($request);
        $fields = $this->fieldInput->normalize($request->input('fields', []));

        $form = DB::transaction(function () use ($data, $fields): IntakeForm {
            $form = IntakeForm::query()->create($data);

            foreach ($fields as $field) {
                unset($field['id']);
                $form->fields()->create($field);
            }

            return $form;
        });

        return redirect()
            ->route('tech.admin.system.intake.forms.edit', $form)
            ->with('success', 'Intake form created.');
    }

    public function edit(IntakeForm $form): View
    {
        $form->load('fields');

        return view('intake::Admin.forms.edit', [
            'form' => $form,
            'fieldRows' => $this->rowsFromForm($form),
            'owners' => $this->owners(),
            'clients' => $this->clients(),
            'services' => $this->services(),
            'mode' => 'edit',
        ]);
    }

    public function update(Request $request, IntakeForm $form): RedirectResponse
    {
        $data = $this->validatedFormData($request, $form);
        $fields = $this->fieldInput->normalize($request->input('fields', []));

        DB::transaction(function () use ($form, $data, $fields): void {
            $data['metadata'] = array_replace($form->metadata ?: [], $data['metadata'] ?? []);
            $form->update($data);
            $keptIds = [];

            foreach ($fields as $field) {
                $fieldId = $field['id'];
                unset($field['id']);

                if ($fieldId) {
                    $formField = $form->fields()->whereKey($fieldId)->first();

                    if ($formField) {
                        $incomingMetadata = $field['metadata'] ?? [];
                        $field['metadata'] = array_replace_recursive($formField->metadata ?: [], $incomingMetadata);
                        $field['metadata']['layout'] = $incomingMetadata['layout'];
                        $field['metadata']['visibility'] = $incomingMetadata['visibility'];
                        $formField->update($field);
                        $keptIds[] = $formField->id;
                        continue;
                    }
                }

                $created = $form->fields()->create($field);
                $keptIds[] = $created->id;
            }

            $form->fields()
                ->when($keptIds !== [], fn ($query) => $query->whereNotIn('id', $keptIds))
                ->delete();
        });

        return redirect()
            ->route('tech.admin.system.intake.forms.edit', $form)
            ->with('success', 'Intake form updated.');
    }

    public function toggle(IntakeForm $form): RedirectResponse
    {
        $form->forceFill([
            'status' => $form->isActive() ? IntakeForm::STATUS_PAUSED : IntakeForm::STATUS_PUBLISHED,
        ])->save();

        return back()->with('success', 'Intake form status updated.');
    }

    private function validatedFormData(Request $request, ?IntakeForm $form = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'nullable',
                'string',
                'max:255',
                'alpha_dash',
                Rule::unique('intake_forms', 'slug')->ignore($form?->id),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::in(array_merge(array_keys(IntakeForm::statusLabels()), [IntakeForm::STATUS_LEGACY_ACTIVE]))],
            'success_message' => ['nullable', 'string', 'max:1000'],
            'submit_button_label' => ['nullable', 'string', 'max:120'],
            'purpose' => ['nullable', 'string', 'max:120'],
            'language' => ['nullable', 'string', 'max:20'],
            'scope_type' => ['nullable', Rule::in(array_keys(IntakeForm::scopeLabels()))],
            'scope_client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'scope_service_id' => ['nullable', 'integer', 'exists:services,id'],
            'scope_campaign_key' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/'],
            'target_type' => ['required', Rule::in(array_keys(IntakeForm::targetLabels()))],
            'routing_mode' => ['nullable', Rule::in(array_keys(IntakeForm::routingModeLabels()))],
            'owner_id' => ['nullable', 'integer', 'exists:user_management,id'],
            'spam_honeypot_field' => ['nullable', 'regex:/^[A-Za-z][A-Za-z0-9_]*$/', 'max:80'],
            'max_files' => ['required', 'integer', 'min:0', 'max:20'],
            'max_file_size_kb' => ['required', 'integer', 'min:1', 'max:51200'],
            'allowed_mime_types_text' => ['nullable', 'string', 'max:2000'],
        ]);

        $validated['status'] = $validated['status'] === IntakeForm::STATUS_LEGACY_ACTIVE
            ? IntakeForm::STATUS_PUBLISHED
            : $validated['status'];
        $validated['scope_type'] = $validated['scope_type'] ?? IntakeForm::SCOPE_GLOBAL;
        $validated['routing_mode'] = $validated['routing_mode'] ?? IntakeForm::ROUTING_MODE_MANUAL_REVIEW;

        if ($validated['scope_type'] === IntakeForm::SCOPE_CLIENT && empty($validated['scope_client_id'])) {
            throw ValidationException::withMessages([
                'scope_client_id' => 'Choose the Client this form is scoped to.',
            ]);
        }

        if ($validated['scope_type'] === IntakeForm::SCOPE_SERVICE && empty($validated['scope_service_id'])) {
            throw ValidationException::withMessages([
                'scope_service_id' => 'Choose the Service this form is scoped to.',
            ]);
        }

        if ($validated['scope_type'] === IntakeForm::SCOPE_CAMPAIGN && empty($validated['scope_campaign_key'])) {
            throw ValidationException::withMessages([
                'scope_campaign_key' => 'Enter a campaign key for campaign-scoped forms.',
            ]);
        }

        if (
            $validated['target_type'] === IntakeForm::TARGET_TASK
            && $validated['routing_mode'] !== IntakeForm::ROUTING_MODE_MANUAL_REVIEW
            && empty($validated['owner_id'])
        ) {
            throw ValidationException::withMessages([
                'owner_id' => 'Automatic Task routing requires a form owner.',
            ]);
        }

        $slug = $validated['slug'] ?: Str::slug($validated['name']);
        $slugExists = IntakeForm::query()
            ->where('slug', $slug)
            ->when($form, fn ($query) => $query->whereKeyNot($form->id))
            ->exists();

        if ($slugExists) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'slug' => 'This intake form URL slug is already in use.',
            ]);
        }

        return [
            'name' => $validated['name'],
            'slug' => $slug,
            'description' => $validated['description'] ?? null,
            'status' => $validated['status'],
            'success_message' => $validated['success_message'] ?? null,
            'target_type' => $validated['target_type'],
            'auto_create_client' => $request->boolean('auto_create_client'),
            'auto_create_contact' => $request->boolean('auto_create_contact'),
            'owner_id' => $validated['owner_id'] ?? null,
            'spam_honeypot_field' => $validated['spam_honeypot_field'] ?: 'intake_website',
            'max_files' => (int) $validated['max_files'],
            'max_file_size_kb' => (int) $validated['max_file_size_kb'],
            'allowed_mime_types' => $this->fieldInput->mimeTypes($validated['allowed_mime_types_text'] ?? ''),
            'metadata' => [
                'submit_button_label' => trim((string) ($validated['submit_button_label'] ?? '')) ?: null,
                'purpose' => trim((string) ($validated['purpose'] ?? '')) ?: null,
                'language' => trim((string) ($validated['language'] ?? '')) ?: 'en',
                'scope' => [
                    'type' => $validated['scope_type'],
                    'client_id' => ! empty($validated['scope_client_id']) ? (int) $validated['scope_client_id'] : null,
                    'service_id' => ! empty($validated['scope_service_id']) ? (int) $validated['scope_service_id'] : null,
                    'campaign_key' => trim((string) ($validated['scope_campaign_key'] ?? '')) ?: null,
                ],
                'routing' => [
                    'mode' => $validated['routing_mode'],
                ],
            ],
        ];
    }

    private function rowsFromForm(IntakeForm $form): array
    {
        return $form->fields->map(function (IntakeFormField $field): array {
            $visibility = $field->visibility();

            return [
                'id' => $field->id,
                'key' => $field->key,
                'label' => $field->label,
                'field_type' => $field->field_type,
                'maps_to' => $field->maps_to,
                'help_text' => $field->help_text,
                'placeholder' => $field->placeholder,
                'options_text' => $this->fieldInput->optionsText($field->options),
                'is_required' => $field->is_required,
                'is_active' => $field->is_active,
                'max_files' => $field->max_files,
                'max_file_size_kb' => $field->max_file_size_kb,
                'allowed_mime_types_text' => $this->fieldInput->mimeTypesText($field->allowed_mime_types),
                'layout_width' => $field->layoutWidth(),
                'visibility_mode' => $visibility['mode'],
                'visibility_match' => $visibility['match'],
                'visibility_rules' => $visibility['rules'],
            ];
        })->values()->all();
    }

    private function owners()
    {
        return User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }

    private function clients()
    {
        return Client::query()
            ->orderBy('name')
            ->get(['id', 'name', 'client_number']);
    }

    private function services()
    {
        return Services::query()
            ->orderBy('name')
            ->get(['id', 'name', 'sku']);
    }
}
