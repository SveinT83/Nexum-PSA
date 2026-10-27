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
        $form = app(\App\Modules\Intake\Actions\SaveIntakeForm::class)->handle($request);

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
        app(\App\Modules\Intake\Actions\SaveIntakeForm::class)->handle($request, $form);

        return redirect()
            ->route('tech.admin.system.intake.forms.edit', $form)
            ->with('success', 'Intake form updated.');
    }

    public function toggle(IntakeForm $form): RedirectResponse
    {
        app(\App\Modules\Intake\Actions\SaveIntakeForm::class)->toggle($form);

        return back()->with('success', 'Intake form status updated.');
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
