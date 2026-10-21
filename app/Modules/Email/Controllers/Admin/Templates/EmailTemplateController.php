<?php

namespace App\Modules\Email\Controllers\Admin\Templates;

use App\Http\Controllers\Controller;
use App\Modules\Email\Actions\EnsureDefaultEmailTemplates;
use App\Modules\Email\Models\EmailTemplate;
use App\Modules\Email\Services\EmailTemplateRenderer;
use App\Modules\Email\Services\OutboundEmailHtmlPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    | Body content and outer layout are edited independently. The explicit
    | layout mode is the only action that freezes organization branding.
    |
    */
    public function index(Request $request, EnsureDefaultEmailTemplates $defaultTemplates): View
    {
        $defaultTemplates->handle();

        return view('email::Admin.Templates.index', app(\App\Modules\Email\Services\EmailTemplateAdministration::class)->index($request, $defaultTemplates));
    }

    public function create(EmailTemplateRenderer $renderer): View
    {
        return view('email::Admin.Templates.form', app(\App\Modules\Email\Services\EmailTemplateAdministration::class)->create($renderer));
    }

    public function edit(EmailTemplate $template, EmailTemplateRenderer $renderer): View
    {
        return view('email::Admin.Templates.form', app(\App\Modules\Email\Services\EmailTemplateAdministration::class)->edit($template, $renderer));
    }

    public function store(Request $request, OutboundEmailHtmlPolicy $htmlPolicy): RedirectResponse
    {
        app(\App\Modules\Email\Services\EmailTemplateAdministration::class)->store($request, $htmlPolicy);

        return redirect()->route('tech.admin.system.templatesManagement.email.index')->with('success', 'Email template created.');
    }

    public function update(
        Request $request,
        EmailTemplate $template,
        OutboundEmailHtmlPolicy $htmlPolicy,
    ): RedirectResponse {
        app(\App\Modules\Email\Services\EmailTemplateAdministration::class)->update($request, $template, $htmlPolicy);

        return redirect()->route('tech.admin.system.templatesManagement.email.index')->with('success', 'Email template updated.');
    }

    public function preview(
        Request $request,
        EmailTemplateRenderer $renderer,
        OutboundEmailHtmlPolicy $htmlPolicy,
    ): JsonResponse {
        return response()->json(app(\App\Modules\Email\Services\EmailTemplateAdministration::class)->preview($request, $renderer, $htmlPolicy));
    }
}
