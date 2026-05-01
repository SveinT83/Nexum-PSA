<?php

namespace App\Http\Controllers\Tech\Admin\System\TemplatesManagement;

use App\Http\Controllers\Controller;
use App\Models\Doc\DocumentationTemplate;
use App\Service\SideBarMenus\Admin\TemplatesMenu;
class TemplatesManagementController extends Controller
{

    // -----------------------------------------
    // INDEX - Show a list of all templates
    // -----------------------------------------
    public function index()
    {

        return view('tech.admin.system.templatesManagement.index', [
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
        return view('tech.admin.system.templatesManagement.doc.index', [
            'templates' => $templates,
            'sidebarMenuItems' => (new TemplatesMenu())->TemplatesMenu(null),
        ]);
    }

    // -----------------------------------------
    // DOC create - Create a new doc template
    // -----------------------------------------
    public function docCreate()
    {
        return view('tech.admin.system.templatesManagement.doc.form', [
            'sidebarMenuItems' => (new TemplatesMenu())->TemplatesMenu(null),
        ]);
    }

    // -----------------------------------------
    // DOC Edit - Edit an doc template
    // -----------------------------------------
    public function docEdit($id)
    {
        return view('tech.admin.system.templatesManagement.doc.form', [
            'templateId' => $id,
            'sidebarMenuItems' => (new TemplatesMenu())->TemplatesMenu(null),
        ]);
    }
}
