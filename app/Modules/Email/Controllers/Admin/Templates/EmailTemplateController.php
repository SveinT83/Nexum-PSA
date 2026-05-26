<?php

namespace App\Modules\Email\Controllers\Admin\Templates;

use App\Http\Controllers\Controller;
use App\Modules\Documentation\Menus\SideBar\TemplatesMenu;
use App\Modules\Email\Actions\EnsureDefaultEmailTemplates;
use App\Modules\Email\Models\EmailTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class EmailTemplateController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Outbound email template management
    |--------------------------------------------------------------------------
    |
    | Email owns the actual template records because outbound rendering and
    | delivery happen in the Email module. The routes live under the global
    | Templates hub so admins manage all templates from one place.
    |
    | Version 1 permits create/edit because that is useful during development.
    | Product policy may later restrict this screen to editing seeded templates
    | only, unless template selection rules are introduced for clients, brands,
    | languages, queues, or workflow conditions.
    |
    */
    public function index(Request $request, EnsureDefaultEmailTemplates $defaultTemplates): View
    {
        $defaultTemplates->handle();

        $scope = $request->get('scope');

        $templates = EmailTemplate::query()
            ->when($scope, fn ($query) => $query->where('scope', $scope))
            ->orderBy('scope')
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('email::Admin.Templates.index', [
            'templates' => $templates,
            'scopes' => EmailTemplate::SCOPES,
            'selectedScope' => $scope,
            'sidebarMenuItems' => (new TemplatesMenu())->TemplatesMenu('email'),
        ]);
    }

    public function create(): View
    {
        return view('email::Admin.Templates.form', [
            'template' => new EmailTemplate(['scope' => 'tickets', 'is_active' => true]),
            'scopes' => EmailTemplate::SCOPES,
            'sidebarMenuItems' => (new TemplatesMenu())->TemplatesMenu('email'),
        ]);
    }

    public function edit(EmailTemplate $template): View
    {
        return view('email::Admin.Templates.form', [
            'template' => $template,
            'scopes' => EmailTemplate::SCOPES,
            'sidebarMenuItems' => (new TemplatesMenu())->TemplatesMenu('email'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        EmailTemplate::create($this->validatedData($request));

        return redirect()
            ->route('tech.admin.system.templatesManagement.email.index')
            ->with('success', 'Email template created.');
    }

    public function update(Request $request, EmailTemplate $template): RedirectResponse
    {
        $template->update($this->validatedData($request, $template));

        return redirect()
            ->route('tech.admin.system.templatesManagement.email.index')
            ->with('success', 'Email template updated.');
    }

    private function validatedData(Request $request, ?EmailTemplate $template = null): array
    {
        $data = $request->validate([
            'scope' => 'required|string|in:' . implode(',', array_keys(EmailTemplate::SCOPES)),
            'key' => 'required|string|max:100',
            'name' => 'required|string|max:255',
            'subject' => 'required|string|max:255',
            'body_html' => 'nullable|string',
            'body_text' => 'nullable|string',
            'variables' => 'nullable|string',
            'is_default' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
        ]);

        $exists = EmailTemplate::query()
            ->where('scope', $data['scope'])
            ->where('key', $data['key'])
            ->when($template?->exists, fn ($query) => $query->whereKeyNot($template->id))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'key' => 'This key is already used for the selected scope.',
            ]);
        }

        $data['variables'] = collect(preg_split('/[\r\n,]+/', $data['variables'] ?? ''))
            ->map(fn (string $value) => trim($value))
            ->filter()
            ->values()
            ->all();
        $data['is_default'] = (bool) ($data['is_default'] ?? false);
        $data['is_active'] = (bool) ($data['is_active'] ?? false);

        return $data;
    }
}
