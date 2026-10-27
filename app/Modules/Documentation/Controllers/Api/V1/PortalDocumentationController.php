<?php

namespace App\Modules\Documentation\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Documentation\Models\Documentation;
use App\Modules\Documentation\Resources\Api\V1\PortalDocumentationResource;
use App\Modules\Documentation\Support\DocumentationApiInput;
use App\Modules\Documentation\Support\PortalDocumentationAccess;
use Illuminate\Http\Request;

class PortalDocumentationController extends Controller
{
    public function index(Request $request, PortalDocumentationAccess $access)
    {
        DocumentationApiInput::allowed($request, ['membership_id', 'page', 'per_page', 'q']);
        $perPage = DocumentationApiInput::page($request);
        $request->validate(['q' => 'sometimes|nullable|string|max:255']);
        $query = $access->visibleDocumentations($request->attributes->get('customerPortalContext'))
            ->with(['category', 'site'])->latest('updated_at')->orderByDesc('id');
        if ($needle = trim((string) $request->input('q', ''))) {
            $query->where('title', 'like', '%'.$needle.'%');
        }

        return PortalDocumentationResource::collection($query->paginate($perPage));
    }

    public function show(Request $request, Documentation $documentation, PortalDocumentationAccess $access)
    {
        DocumentationApiInput::allowed($request, ['membership_id']);
        abort_unless($access->canView($request->attributes->get('customerPortalContext'), $documentation), 404);

        return new PortalDocumentationResource($documentation->load(['category', 'site']));
    }
}
