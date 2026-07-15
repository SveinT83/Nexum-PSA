<?php

namespace App\Modules\Intake\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Core\User;
use App\Modules\Intake\Models\IntakeForm;
use App\Modules\Intake\Models\IntakeFormField;
use App\Modules\Intake\Support\IntakeFormFieldInput;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
            'status' => $form->isActive() ? IntakeForm::STATUS_DRAFT : IntakeForm::STATUS_ACTIVE,
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
            'status' => ['required', Rule::in([IntakeForm::STATUS_DRAFT, IntakeForm::STATUS_ACTIVE, IntakeForm::STATUS_ARCHIVED])],
            'success_message' => ['nullable', 'string', 'max:1000'],
            'submit_button_label' => ['nullable', 'string', 'max:120'],
            'target_type' => ['required', Rule::in([IntakeForm::TARGET_REVIEW_ONLY, IntakeForm::TARGET_SALES_LEAD])],
            'owner_id' => ['nullable', 'integer', 'exists:user_management,id'],
            'spam_honeypot_field' => ['nullable', 'regex:/^[A-Za-z][A-Za-z0-9_]*$/', 'max:80'],
            'max_files' => ['required', 'integer', 'min:0', 'max:20'],
            'max_file_size_kb' => ['required', 'integer', 'min:1', 'max:51200'],
            'allowed_mime_types_text' => ['nullable', 'string', 'max:2000'],
        ]);

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
}
