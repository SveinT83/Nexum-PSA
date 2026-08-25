<?php

namespace App\Modules\Email\Controllers\Admin\Templates;

use App\Http\Controllers\Controller;
use App\Modules\Documentation\Menus\SideBar\TemplatesMenu;
use App\Modules\Email\Actions\EnsureDefaultEmailTemplates;
use App\Modules\Email\Models\EmailTemplate;
use App\Modules\Email\Services\EmailTemplateRenderer;
use App\Modules\Email\Services\OutboundEmailHtmlPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

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
    | Body content and outer layout are edited independently. The explicit
    | layout mode is the only action that freezes organization branding.
    |
    */
    public function index(Request $request, EnsureDefaultEmailTemplates $defaultTemplates): View
    {
        $defaultTemplates->handle();

        $scope = $request->get('scope');
        $search = trim((string) $request->get('q', ''));

        $templates = EmailTemplate::query()
            ->when($scope, fn ($query) => $query->where('scope', $scope))
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('key', 'like', "%{$search}%")
                        ->orWhere('subject', 'like', "%{$search}%");
                });
            })
            ->orderBy('scope')
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('email::Admin.Templates.index', [
            'templates' => $templates,
            'scopes' => EmailTemplate::SCOPES,
            'selectedScope' => $scope,
            'search' => $search,
            'sidebarMenuItems' => (new TemplatesMenu)->TemplatesMenu('email'),
        ]);
    }

    public function create(EmailTemplateRenderer $renderer): View
    {
        $template = new EmailTemplate([
            'scope' => 'tickets',
            'subject' => 'Email from {{ company_name }}',
            'body_html' => '<p>Hello {{ contact_name }},</p><p>Write your message here.</p>',
            'layout_mode' => EmailTemplate::LAYOUT_BRANDING,
            'is_active' => true,
        ]);

        return view('email::Admin.Templates.form', $this->formData($template, $renderer));
    }

    public function edit(EmailTemplate $template, EmailTemplateRenderer $renderer): View
    {
        return view('email::Admin.Templates.form', $this->formData($template, $renderer));
    }

    public function store(Request $request, OutboundEmailHtmlPolicy $htmlPolicy): RedirectResponse
    {
        EmailTemplate::create($this->validatedData($request, $htmlPolicy));

        return redirect()
            ->route('tech.admin.system.templatesManagement.email.index')
            ->with('success', 'Email template created.');
    }

    public function update(
        Request $request,
        EmailTemplate $template,
        OutboundEmailHtmlPolicy $htmlPolicy,
    ): RedirectResponse {
        $template->update($this->validatedData($request, $htmlPolicy, $template));

        return redirect()
            ->route('tech.admin.system.templatesManagement.email.index')
            ->with('success', 'Email template updated.');
    }

    public function preview(
        Request $request,
        EmailTemplateRenderer $renderer,
        OutboundEmailHtmlPolicy $htmlPolicy,
    ): JsonResponse {
        $data = $this->validatedPreviewData($request, $htmlPolicy);
        $template = new EmailTemplate($data);

        return response()->json($renderer->render($template, $renderer->sampleVariables($template)));
    }

    private function formData(EmailTemplate $template, EmailTemplateRenderer $renderer): array
    {
        $sampleVariables = $renderer->sampleVariables($template);

        return [
            'template' => $template,
            'scopes' => EmailTemplate::SCOPES,
            'layoutModes' => EmailTemplate::LAYOUT_MODES,
            'brandingLayout' => $renderer->brandingLayout(),
            'preview' => $renderer->render($template, $sampleVariables),
            'previewUrl' => route('tech.admin.system.templatesManagement.email.preview'),
            'sampleVariables' => $sampleVariables,
            'sidebarMenuItems' => (new TemplatesMenu)->TemplatesMenu('email'),
        ];
    }

    private function validatedData(
        Request $request,
        OutboundEmailHtmlPolicy $htmlPolicy,
        ?EmailTemplate $template = null,
    ): array {
        $data = $request->validate([
            'scope' => 'required|string|in:'.implode(',', array_keys(EmailTemplate::SCOPES)),
            'key' => 'required|string|max:100',
            'name' => 'required|string|max:255',
            'subject' => 'required|string|max:255',
            'body_html' => 'nullable|string|max:250000',
            'body_text' => 'nullable|string|max:250000',
            'layout_mode' => 'sometimes|string|in:'.implode(',', array_keys(EmailTemplate::LAYOUT_MODES)),
            'layout_html' => 'nullable|string|max:500000',
            'variables' => 'nullable|string',
            'is_default' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
        ]);
        $data['layout_mode'] = $data['layout_mode']
            ?? $template?->layout_mode
            ?? EmailTemplate::LAYOUT_BRANDING;
        $data['layout_html'] = $data['layout_html'] ?? $template?->layout_html;

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

        $this->assertHtmlPolicy($data, $htmlPolicy);
        $data['variables'] = collect(preg_split('/[\r\n,]+/', $data['variables'] ?? ''))
            ->map(fn (string $value) => trim($value))
            ->filter()
            ->values()
            ->all();
        $data['is_default'] = (bool) ($data['is_default'] ?? false);
        $data['is_active'] = (bool) ($data['is_active'] ?? false);
        $data['layout_html'] = $data['layout_mode'] === EmailTemplate::LAYOUT_CUSTOM
            ? $data['layout_html']
            : null;

        return $data;
    }

    private function validatedPreviewData(Request $request, OutboundEmailHtmlPolicy $htmlPolicy): array
    {
        $data = $request->validate([
            'subject' => 'nullable|string|max:255',
            'body_html' => 'nullable|string|max:250000',
            'body_text' => 'nullable|string|max:250000',
            'layout_mode' => 'required|string|in:'.implode(',', array_keys(EmailTemplate::LAYOUT_MODES)),
            'layout_html' => 'nullable|string|max:500000',
            'variables' => 'nullable|string|max:20000',
        ]);
        $this->assertHtmlPolicy($data, $htmlPolicy);
        $data['subject'] = $data['subject'] ?? '';
        $data['variables'] = collect(preg_split('/[\r\n,]+/', $data['variables'] ?? ''))
            ->map(fn (string $value) => trim($value))
            ->filter()
            ->values()
            ->all();
        $data['layout_html'] = $data['layout_mode'] === EmailTemplate::LAYOUT_CUSTOM
            ? $data['layout_html']
            : null;

        return $data;
    }

    private function assertHtmlPolicy(array $data, OutboundEmailHtmlPolicy $htmlPolicy): void
    {
        try {
            $htmlPolicy->assertBody($data['body_html'] ?? null);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['body_html' => $exception->getMessage()]);
        }

        if (($data['layout_mode'] ?? EmailTemplate::LAYOUT_BRANDING) !== EmailTemplate::LAYOUT_CUSTOM) {
            return;
        }

        try {
            $htmlPolicy->assertLayout($data['layout_html'] ?? null);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['layout_html' => $exception->getMessage()]);
        }
    }
}
