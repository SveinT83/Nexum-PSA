<?php

namespace App\Modules\Documentation\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Documentation\Menus\SideBar\TemplatesMenu;
use App\Modules\Documentation\Models\DocumentationTemplate;

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
    public function docIndex()
    {
        //Get all dokumentations templates
        $templates = DocumentationTemplate::all();

        //Reurn View: Sidebar menu and all Documentatins templates
        return view('documentation::Admin.TemplateManagement.Doc.index', [
            'templates' => $templates,
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
