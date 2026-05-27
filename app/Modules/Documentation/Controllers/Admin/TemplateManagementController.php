<?php

namespace App\Modules\Documentation\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Documentation\Menus\SideBar\TemplatesMenu;
use App\Modules\Documentation\Models\DocumentationTemplate;
use App\Modules\Taxonomy\Models\Category;
use Illuminate\Http\Request;

/**
 * Controller for managing system templates.
 * Handles the main hub and documentation templatesManagement specifically.
 */
class TemplateManagementController extends Controller
{

    // -----------------------------------------
    // INDEX - Show a list of all templates
    // -----------------------------------------
    public function index()
    {

        return view('documentation::Admin.TemplateManagement.index', [
        'sidebarMenuItems' => (new TemplatesMenu())->TemplatesMenu(null),
            ]);
    }

    // -----------------------------------------
    // DOC INDEX - Show a list of all documentations templates
    // -----------------------------------------
    public function docIndex(Request $request)
    {
        $search = trim((string) $request->get('q', ''));
        $categoryId = $request->get('category_id');

        // Get all documentation templates with simple admin search and category filtering.
        $templates = DocumentationTemplate::query()
            ->with('category')
            ->when($search !== '', fn ($query) => $query->where('name', 'like', "%{$search}%"))
            ->when($categoryId, fn ($query) => $query->where('category_id', $categoryId))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('documentation::Admin.TemplateManagement.Doc.index', [
            'templates' => $templates,
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
            'search' => $search,
            'selectedCategoryId' => $categoryId,
            'sidebarMenuItems' => (new TemplatesMenu())->TemplatesMenu(null),
        ]);
    }

    // -----------------------------------------
    // DOC create - Create a new doc template
    // -----------------------------------------
    public function docCreate()
    {
        return view('documentation::Admin.TemplateManagement.Doc.form', [
            'sidebarMenuItems' => (new TemplatesMenu())->TemplatesMenu(null),
        ]);
    }

    // -----------------------------------------
    // DOC Edit - Edit an doc template
    // -----------------------------------------
    public function docEdit($id)
    {
        return view('documentation::Admin.TemplateManagement.Doc.form', [
            'templateId' => $id,
            'sidebarMenuItems' => (new TemplatesMenu())->TemplatesMenu(null),
        ]);
    }
}
