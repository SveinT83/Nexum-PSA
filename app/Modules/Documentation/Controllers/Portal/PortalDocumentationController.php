<?php

namespace App\Modules\Documentation\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\CustomerPortal\Support\CustomerPortalContext;
use App\Modules\Documentation\Models\Documentation;
use App\Modules\Documentation\Support\PortalDocumentationAccess;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PortalDocumentationController extends Controller
{
    public function index(Request $request, PortalDocumentationAccess $access): View
    {
        $context = $this->context($request);

        $documentations = $access->visibleDocumentations($context)
            ->with(['category', 'site'])
            ->latest('updated_at')
            ->paginate(15);

        return view('documentation::Portal.documents.index', [
            'context' => $context,
            'documentations' => $documentations,
        ]);
    }

    public function show(Request $request, Documentation $documentation, PortalDocumentationAccess $access): View
    {
        $context = $this->context($request);
        abort_unless($access->canView($context, $documentation), 404);

        $documentation->load(['category', 'client', 'site', 'template']);

        return view('documentation::Portal.documents.show', [
            'context' => $context,
            'documentation' => $documentation,
        ]);
    }

    private function context(Request $request): CustomerPortalContext
    {
        /** @var CustomerPortalContext $context */
        $context = $request->attributes->get('customerPortalContext');

        return $context;
    }
}
