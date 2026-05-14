<?php

namespace App\Modules\Sales\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class SalesSettingsController extends Controller
{
    public function rules(): View
    {
        return view('sales::Admin.Settings.rules.index');
    }

    public function workflows(): View
    {
        return view('sales::Admin.Settings.workflows.index');
    }
}
