<?php

namespace App\Modules\Intake\Actions;

use App\Models\Core\User;
use App\Modules\Intake\Models\IntakeForm;
use App\Modules\Intake\Support\IntakeFormFieldInput;
use App\Modules\Intake\Support\IntakeWorkflowLock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Browser and API share form validation and persistence, including historical field ownership. */
class SaveIntakeForm
{
    public function __construct(private readonly IntakeFormFieldInput $fieldInput) {}

    public function handle(Request $request, ?IntakeForm $form = null): IntakeForm
    {
        return IntakeWorkflowLock::run(function () use ($request, $form): IntakeForm {
            [$data, $fields] = $this->prepare($request, $form);

            return DB::transaction(function () use ($form, $data, $fields): IntakeForm {
                if ($form) {
                    $data['metadata'] = array_replace($form->metadata ?: [], $data['metadata'] ?? []);
                    $form->update($data);
                } else {
                    $form = IntakeForm::create($data);
                }
                // Resolve omitted IDs by key and free old keys before replacements/renames.
                $original = $form->fields()->get()->keyBy('id');
                $byKey = $original->keyBy('key');
                foreach ($fields as &$field) {
                    $field['id'] = $field['id'] ?: ($byKey->get($field['key'])?->id);
                }
                unset($field);
                $retained = array_values(array_filter(array_column($fields, 'id')));
                $form->fields()->whereNotIn('id', $retained)->delete();
                foreach ($retained as $id) {
                    $form->fields()->whereKey($id)->update(['key' => '__intake_'.Str::uuid()]);
                }
                $kept = [];
                foreach ($fields as $field) {
                    $id = $field['id'];
                    unset($field['id']);
                    $existing = $id ? $original->get($id) : null;
                    if ($existing) {
                        $incoming = $field['metadata'];
                        $field['metadata'] = array_replace_recursive($existing->metadata ?: [], $incoming);
                        $field['metadata']['layout'] = $incoming['layout'];
                        $field['metadata']['visibility'] = $incoming['visibility'];
                        $existing->update($field);
                        $kept[] = $existing->id;
                    } else {
                        $kept[] = $form->fields()->create($field)->id;
                    }
                }
                $form->fields()->whereNotIn('id', $kept)->delete();

                return $form->fresh('fields');
            });
        });
    }

    /** Validate without effects for previews and pre-claim API input checks. */
    public function prepare(Request $request, ?IntakeForm $form = null): array
    {
        $form?->refresh();
        $data = $this->data($request, $form);
        $request->validate([
            'fields.*' => ['array'],
            'fields.*.id' => ['nullable', 'integer', 'min:1'],
            'fields.*.label' => ['nullable', 'string', 'max:255'],
            'fields.*.key' => ['nullable', 'string', 'max:100'],
            'fields.*.options_text' => ['nullable', 'string', 'max:10000'],
            'fields.*.field_type' => ['sometimes', 'string'],
            'fields.*.maps_to' => ['nullable', 'string', 'max:100'],
            'fields.*.help_text' => ['nullable', 'string', 'max:2000'],
            'fields.*.placeholder' => ['nullable', 'string', 'max:255'],
            'fields.*.is_required' => ['sometimes', 'boolean'],
            'fields.*.is_active' => ['sometimes', 'boolean'],
            'fields.*.max_files' => ['nullable', 'integer', 'min:0', 'max:20'],
            'fields.*.max_file_size_kb' => ['nullable', 'integer', 'min:1', 'max:51200'],
            'fields.*.allowed_mime_types_text' => ['nullable', 'string', 'max:2000'],
            'fields.*.layout_width' => ['sometimes', 'nullable', 'integer'],
            'fields.*.visibility_mode' => ['sometimes', 'string'],
            'fields.*.visibility_match' => ['sometimes', 'string'],
            'fields.*.visibility_rules.*' => ['array'],
            'fields.*.visibility_rules.*.source_key' => ['sometimes', 'string', 'max:100'],
            'fields.*.visibility_rules.*.operator' => ['sometimes', 'string', 'max:50'],
            'fields.*.visibility_rules.*.value' => ['nullable', 'string', 'max:1000'],
            'fields.*.visibility_rules' => ['sometimes', 'array', 'max:50'],
        ]);
        $fields = $this->fieldInput->normalize($request->input('fields', []));
        $ids = array_values(array_filter(array_column($fields, 'id')));
        if (count($ids) !== count(array_unique($ids))
            || ($ids && (! $form || $form->fields()->whereIn('id', $ids)->count() !== count($ids)))) {
            throw ValidationException::withMessages(['fields' => 'Field IDs must belong to this form and may appear only once.']);
        }
        if ($data['owner_id']) {
            $owner = User::find($data['owner_id']);
            if (! $owner || ! $owner->isActive() || $owner->isSystemActor()) {
                throw ValidationException::withMessages(['owner_id' => 'Choose an active internal owner.']);
            }
        }

        return [$data, $fields];
    }

    public function toggle(IntakeForm $form): IntakeForm
    {
        return IntakeWorkflowLock::run(function () use ($form): IntakeForm {
            $form->refresh()->forceFill([
                'status' => $form->isActive() ? IntakeForm::STATUS_PAUSED : IntakeForm::STATUS_PUBLISHED,
            ])->save();

            return $form->fresh('fields');
        });
    }

    public function data(Request $request, ?IntakeForm $form = null): array
    {
        $validated = $request->validate([
            'fields' => ['required', 'array', 'max:100'],
            'auto_create_client' => ['sometimes', 'boolean'],
            'auto_create_contact' => ['sometimes', 'boolean'],
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

        $slug = ($validated['slug'] ?? null) ?: Str::slug($validated['name']);
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
            'spam_honeypot_field' => ($validated['spam_honeypot_field'] ?? null) ?: 'intake_website',
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
}
